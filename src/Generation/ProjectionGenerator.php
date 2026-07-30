<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Generation;

use Carbon\CarbonImmutable;
use Darangonaut\DoctrineProjections\Eloquent\ReadOnlyModel;
use Darangonaut\DoctrineProjections\Exceptions\DuplicateProjectionName;
use Darangonaut\DoctrineProjections\Exceptions\UnsupportedMapping;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\InverseSideMapping;
use Doctrine\ORM\Mapping\ManyToManyInverseSideMapping;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\Mapping\OneToOneInverseSideMapping;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Renders read-only Eloquent models from Doctrine metadata.
 *
 * Deliberately free of Laravel console plumbing: this is a pure
 * metadata → source transformation, which is what makes the awkward
 * cases (composite keys, self-references, name collisions) cheap to test.
 *
 * Foreign keys and join table names are read from the mapping, never
 * guessed from Laravel conventions — a convention holds only until
 * someone names a column differently.
 */
final class ProjectionGenerator
{
    /** @var list<string> basenames of every generated model — reserved names */
    private array $reserved = [];

    /** Imports for the class currently being rendered. */
    private Imports $imports;

    /** @var list<string> entity classes that will get a projection */
    private array $projected = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $namespace,
        private readonly EntityFilter $filter = new EntityFilter,
    ) {}

    /**
     * @return array<string, RenderedProjection> keyed by class basename
     *
     * @throws DuplicateProjectionName
     */
    public function generate(): array
    {
        // Production metadata cache survives a deploy — without clearing it
        // the models would be generated from yesterday's mapping.
        $this->em->getConfiguration()->getMetadataCache()?->clear();

        $metadata = array_values(array_filter(
            $this->em->getMetadataFactory()->getAllMetadata(),
            fn (ClassMetadata $meta): bool => ! $meta->isMappedSuperclass
                && ! $meta->isEmbeddedClass
                && $this->filter->accepts($meta->getName()),
        ));

        $this->guardAgainstUnsupportedInheritance($metadata);
        $this->guardAgainstDuplicateNames($metadata);

        $this->reserved = array_map(
            static fn (ClassMetadata $meta): string => class_basename($meta->getName()),
            $metadata,
        );

        // Relations pointing at an entity nobody projected would reference
        // a class that does not exist, so they have to be skipped — see
        // relationsFor().
        $this->projected = array_map(
            static fn (ClassMetadata $meta): string => $meta->getName(),
            $metadata,
        );

        $rendered = [];

        foreach ($metadata as $meta) {
            $projection = $this->render($meta);
            $rendered[$projection->className] = $projection;
        }

        return $rendered;
    }

    /**
     * Class table inheritance spreads one entity across several tables and
     * needs a join to reconstruct. An Eloquent model bound to a single
     * table cannot express that, and a projection that quietly returns
     * only the root columns is worse than none.
     *
     * Single table inheritance is supported — see discriminatorScope().
     *
     * @param  list<ClassMetadata<object>>  $metadata
     *
     * @throws UnsupportedMapping
     */
    private function guardAgainstUnsupportedInheritance(array $metadata): void
    {
        $joined = [];

        foreach ($metadata as $meta) {
            if ($meta->isInheritanceTypeJoined()) {
                $joined[] = $meta->getName();
            }
        }

        if ($joined !== []) {
            throw UnsupportedMapping::joinedInheritance($joined);
        }
    }

    /**
     * Projections share one namespace, so two entities with the same short
     * name would overwrite each other's file. Failing loudly beats leaving
     * one table silently without a model.
     *
     * @param  list<ClassMetadata<object>>  $metadata
     *
     * @throws DuplicateProjectionName
     */
    private function guardAgainstDuplicateNames(array $metadata): void
    {
        $byBase = [];

        foreach ($metadata as $meta) {
            $byBase[class_basename($meta->getName())][] = $meta->getName();
        }

        $clashes = array_filter($byBase, static fn (array $classes): bool => count($classes) > 1);

        if ($clashes !== []) {
            throw DuplicateProjectionName::between($clashes);
        }
    }

    /** @param ClassMetadata<object> $meta */
    private function render(ClassMetadata $meta): RenderedProjection
    {
        $class = class_basename($meta->getName());
        $this->imports = new Imports($class, $this->reserved);
        $warnings = [];

        // The base class and the trait go through the collector too — an
        // entity may be named Model or ReadOnlyModel, and then they must
        // be fully qualified.
        $traitRef = $this->imports->reference(ReadOnlyModel::class);
        $modelRef = $this->imports->reference(Model::class);

        // The body is assembled before the header, because assembling it
        // is what collects the imports.
        $members = $this->members($meta, $warnings);
        $docblock = $this->propertyDocblock($meta);

        $useBlock = implode("\n", array_map(
            static fn (string $fqcn): string => "use {$fqcn};",
            $this->imports->all(),
        ));

        $code = sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace %s;\n\n%s\n\n/**\n"
            ." * GENERATED — do not edit.\n"
            ." *\n"
            ." * Source: %s\n"
            ." * Regenerate: php artisan doctrine:projections\n"
            ." *\n"
            ." * Read-only projection. Writing throws ReadOnlyProjection — change\n"
            ." * data through the Doctrine entity.\n"
            ." *\n%s */\nclass %s extends %s\n{\n    use %s;\n\n%s}\n",
            $this->namespace,
            $useBlock,
            $meta->getName(),
            $docblock,
            $class,
            $modelRef,
            $traitRef,
            $members,
        );

        return new RenderedProjection($class, $meta->getName(), $meta->getTableName(), $code, $warnings);
    }

    /** @param ClassMetadata<object> $meta */
    private function propertyDocblock(ClassMetadata $meta): string
    {
        $lines = [];

        foreach ($meta->getFieldNames() as $field) {
            $lines[] = sprintf(
                ' * @property %s $%s',
                $this->phpType($meta, $field),
                $meta->getColumnName($field),
            );
        }

        foreach ($meta->getAssociationMappings() as $name => $assoc) {
            if (! $this->isProjected($assoc->targetEntity)) {
                continue;
            }

            $target = class_basename($assoc->targetEntity);

            // Must match the generated method exactly: Eloquent resolves
            // `$task->blockedBy` by looking for a method of that name, so a
            // snake_cased `$blocked_by` is not a property at all — it reads
            // back as null, silently, while the docblock and every IDE
            // insist it is fine. Columns below stay snake_case; they really
            // are columns.
            $property = Str::camel($name);

            if ($assoc->isToMany()) {
                $lines[] = sprintf(
                    ' * @property %s<int, %s> $%s',
                    $this->imports->reference(Collection::class),
                    $target,
                    $property,
                );

                continue;
            }

            if ($assoc instanceof OneToOneInverseSideMapping) {
                // The FK lives on the other table; the counterpart row may not exist.
                $lines[] = sprintf(' * @property %s|null $%s', $target, $property);

                continue;
            }

            $nullable = $assoc instanceof ToOneOwningSideMapping
                ? ($assoc->joinColumns[0]->nullable ?? true)
                : true;

            // The foreign key is not among the fields, but it is queried often.
            $lines[] = sprintf(
                ' * @property int%s $%s',
                $nullable ? '|null' : '',
                $this->foreignKeyFor($assoc),
            );
            $lines[] = sprintf(' * @property %s%s $%s', $target, $nullable ? '|null' : '', $property);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  ClassMetadata<object>  $meta
     * @param  list<string>  $warnings
     */
    private function members(ClassMetadata $meta, array &$warnings): string
    {
        $out = sprintf("    protected \$table = '%s';\n", $meta->getTableName());
        $out .= $this->keyMembers($meta, $warnings);

        // Doctrine manages timestamps itself.
        $out .= "\n    public \$timestamps = false;\n";

        // The lock is ReadOnlyModel, not $fillable. Without this, update()
        // would fail with MassAssignmentException and the same mistake
        // would raise two different exception types depending on the path.
        $out .= "\n    protected \$guarded = [];\n";

        $casts = [];
        foreach ($meta->getFieldNames() as $field) {
            $cast = $this->castFor($meta, $field);
            if ($cast !== null) {
                $casts[$meta->getColumnName($field)] = $cast;
            }
        }

        if ($casts !== []) {
            $out .= "\n    /** @return array<string, string> */\n";
            $out .= "    protected function casts(): array\n    {\n        return [\n";
            foreach ($casts as $column => $cast) {
                $out .= sprintf("            '%s' => %s,\n", $column, $cast);
            }
            $out .= "        ];\n    }\n";
        }

        $out .= $this->discriminatorScope($meta);

        foreach ($meta->getAssociationMappings() as $name => $assoc) {
            if (! $this->isProjected($assoc->targetEntity)) {
                $warnings[] = sprintf(
                    'Skipped relation %s::$%s — %s has no projection, so there would be nothing to point at.',
                    class_basename($meta->getName()),
                    $name,
                    class_basename($assoc->targetEntity),
                );

                continue;
            }

            $out .= $this->relation($name, $assoc);
        }

        return $out;
    }

    private function isProjected(string $entityClass): bool
    {
        return in_array($entityClass, $this->projected, true);
    }

    /**
     * Under single table inheritance every subclass shares one table, so a
     * plain Eloquent model would happily return its siblings' rows —
     * CardPayment::all() handing back cash payments. A global scope on the
     * discriminator column restores the boundary.
     *
     * The root class gets no scope: "every payment" is a meaningful query
     * and that is exactly what the root represents.
     *
     * @param  ClassMetadata<object>  $meta
     */
    private function discriminatorScope(ClassMetadata $meta): string
    {
        $column = $meta->discriminatorColumn?->name;
        $value = $meta->discriminatorValue;

        if (! $meta->isInheritanceTypeSingleTable() || $column === null || ! is_scalar($value)) {
            return '';
        }

        $builder = $this->imports->reference(Builder::class);

        return sprintf(
            "\n    /** Single table inheritance — this class owns only its own rows. */\n"
            ."    protected static function booted(): void\n    {\n"
            ."        static::addGlobalScope('doctrine_discriminator', static function (%s \$query): void {\n"
            ."            \$query->where('%s', '%s');\n"
            ."        });\n    }\n",
            $builder,
            $column,
            (string) $value,
        );
    }

    /**
     * The key is emitted from metadata, not from Eloquent defaults. Without
     * that a string key would get an implicit int cast (UUID "018f…" read
     * back as 18) and a composite key would be silently reduced to its
     * first column.
     *
     * @param  ClassMetadata<object>  $meta
     * @param  list<string>  $warnings
     */
    private function keyMembers(ClassMetadata $meta, array &$warnings): string
    {
        $idFields = $meta->getIdentifierFieldNames();

        if (count($idFields) > 1) {
            $warnings[] = sprintf(
                '%s has a composite key (%s) — Eloquent does not support one: find() and getKey() will not work, reading via where() will.',
                class_basename($meta->getName()),
                implode(', ', $idFields),
            );

            return "\n    /** Composite key — find() and getKey() do not work, query with where(). */\n"
                ."    protected \$primaryKey = null;\n"
                ."\n    public \$incrementing = false;\n";
        }

        $out = '';
        $identifier = $idFields[0] ?? 'id';
        $keyColumn = $meta->getColumnName($identifier);

        if ($keyColumn !== 'id') {
            $out .= sprintf("\n    protected \$primaryKey = '%s';\n", $keyColumn);
        }

        $intKey = in_array($meta->getTypeOfField($identifier), ['integer', 'smallint', 'bigint'], true);

        if (! $intKey) {
            $out .= "\n    protected \$keyType = 'string';\n";
        }

        if (! ($intKey && $meta->isIdGeneratorIdentity())) {
            $out .= "\n    public \$incrementing = false;\n";
        }

        return $out;
    }

    private function relationType(AssociationMapping $assoc): string
    {
        return match (true) {
            $assoc instanceof ManyToManyOwningSideMapping,
            $assoc instanceof ManyToManyInverseSideMapping => 'BelongsToMany',
            $assoc instanceof OneToOneInverseSideMapping => 'HasOne',
            $assoc->isToMany() => 'HasMany',
            default => 'BelongsTo',
        };
    }

    private function relation(string $name, AssociationMapping $assoc): string
    {
        $target = class_basename($assoc->targetEntity);
        $method = Str::camel($name);
        $type = $this->relationType($assoc);

        // short() returns an FQCN when the relation class name clashes with
        // a generated model. That return value must be used — otherwise
        // `HasMany` in the body would resolve to the projection of the same
        // name and the call would throw a TypeError.
        $typeRef = $this->imports->reference('Illuminate\\Database\\Eloquent\\Relations\\'.$type);

        $args = $this->relationArgs($assoc, $type);

        return sprintf(
            "\n    /** @return %s<%s, \$this> */\n"
            ."    public function %s(): %s\n    {\n"
            ."        return \$this->%s(%s::class, %s);\n    }\n",
            $typeRef,
            $target,
            $method,
            $typeRef,
            // the method name comes from the bare type, not the reference
            lcfirst($type),
            $target,
            $args,
        );
    }

    /**
     * Relation arguments, resolved from the mapping rather than guessed.
     *
     * Which side declares what is the whole subtlety here: an owning side
     * carries the join columns, an inverse side only knows the property
     * name on the other class. Getting this wrong is how the generator
     * once crashed on the inverse side of a OneToOne.
     */
    private function relationArgs(AssociationMapping $assoc, string $type): string
    {
        if ($type === 'BelongsToMany') {
            [$table, $foreign, $related] = $this->joinTableColumns($assoc);

            return sprintf("'%s', '%s', '%s'", $table, $foreign, $related);
        }

        return sprintf("'%s'", $this->foreignKeyFor($assoc));
    }

    /**
     * @return array{string, string, string} table, foreign pivot key, related pivot key
     */
    private function joinTableColumns(AssociationMapping $assoc): array
    {
        if ($assoc instanceof ManyToManyOwningSideMapping) {
            $joinTable = $assoc->joinTable;

            return [
                $joinTable->name,
                $joinTable->joinColumns[0]->name,
                $joinTable->inverseJoinColumns[0]->name,
            ];
        }

        // The join table is declared by the owning side; from here it has to
        // be reached through mappedBy — with the keys swapped.
        $owning = $this->owningSideOf($assoc);

        if (! $owning instanceof ManyToManyOwningSideMapping) {
            throw UnsupportedMapping::unexpectedOwningSide($assoc->targetEntity, $owning::class);
        }

        $joinTable = $owning->joinTable;

        return [
            $joinTable->name,
            $joinTable->inverseJoinColumns[0]->name,
            $joinTable->joinColumns[0]->name,
        ];
    }

    /**
     * The foreign key column. On an owning side it is declared here; on an
     * inverse side it lives on the other class, under the mappedBy name.
     */
    private function foreignKeyFor(AssociationMapping $assoc): string
    {
        if ($assoc instanceof ToOneOwningSideMapping) {
            return $assoc->joinColumns[0]->name;
        }

        $owning = $this->owningSideOf($assoc);

        if (! $owning instanceof ToOneOwningSideMapping) {
            throw UnsupportedMapping::unexpectedOwningSide($assoc->targetEntity, $owning::class);
        }

        return $owning->joinColumns[0]->name;
    }

    private function owningSideOf(AssociationMapping $assoc): AssociationMapping
    {
        if (! $assoc instanceof InverseSideMapping) {
            throw UnsupportedMapping::unexpectedOwningSide($assoc->targetEntity, $assoc::class);
        }

        /** @var class-string $target */
        $target = $assoc->targetEntity;

        return $this->em->getClassMetadata($target)->getAssociationMapping($assoc->mappedBy);
    }

    /** @param ClassMetadata<object> $meta */
    private function castFor(ClassMetadata $meta, string $field): ?string
    {
        $enum = $meta->getFieldMapping($field)->enumType ?? null;

        if ($enum !== null) {
            return $this->imports->reference($enum).'::class';
        }

        return match ($meta->getTypeOfField($field)) {
            'integer', 'smallint', 'bigint' => "'integer'",
            'boolean' => "'boolean'",
            'decimal', 'float' => "'float'",
            'datetime', 'datetime_immutable' => "'immutable_datetime'",
            'date', 'date_immutable' => "'immutable_date'",
            'time', 'time_immutable' => "'immutable_datetime'",
            'json' => "'array'",
            default => null,
        };
    }

    /** @param ClassMetadata<object> $meta */
    private function phpType(ClassMetadata $meta, string $field): string
    {
        $mapping = $meta->getFieldMapping($field);
        $enum = $mapping->enumType ?? null;
        $nullable = $mapping->nullable ?? false;

        $type = $enum !== null
            ? $this->imports->reference($enum)
            : match ($meta->getTypeOfField($field)) {
                'integer', 'smallint', 'bigint' => 'int',
                'boolean' => 'bool',
                'decimal', 'float' => 'float',
                'datetime', 'datetime_immutable', 'date', 'date_immutable',
                'time', 'time_immutable' => $this->imports->reference(CarbonImmutable::class),
                'json' => 'array',
                default => 'string',
            };

        return $nullable ? $type.'|null' : $type;
    }
}
