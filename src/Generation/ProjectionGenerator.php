<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Generation;

use Carbon\CarbonImmutable;
use Darangonaut\DoctrineProjections\Eloquent\ReadOnlyModel;
use Darangonaut\DoctrineProjections\Exceptions\DuplicateProjectionName;
use Darangonaut\DoctrineProjections\Exceptions\UnsupportedMapping;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToManyInverseSideMapping;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\Mapping\OneToOneInverseSideMapping;
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
    /** @var array<string, string> import basename => FQCN, for the model being rendered */
    private array $uses = [];

    /** @var list<string> basenames of every generated model — reserved names */
    private array $reserved = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $namespace,
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
            static fn (ClassMetadata $meta): bool => ! $meta->isMappedSuperclass && ! $meta->isEmbeddedClass,
        ));

        $this->guardAgainstUnsupportedInheritance($metadata);
        $this->guardAgainstDuplicateNames($metadata);

        $this->reserved = array_map(
            static fn (ClassMetadata $meta): string => class_basename($meta->getName()),
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
        $joined = array_values(array_map(
            static fn (ClassMetadata $meta): string => $meta->getName(),
            array_filter($metadata, static fn (ClassMetadata $meta): bool => $meta->isInheritanceTypeJoined()),
        ));

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
        $this->uses = [];
        $warnings = [];

        // The base class and the trait go through short() too — an entity
        // may be named Model or ReadOnlyModel, and then they must be
        // fully qualified.
        $traitRef = $this->short(ReadOnlyModel::class, $class);
        $modelRef = $this->short(Model::class, $class);

        // The body is assembled before the header, because assembling it
        // is what collects the imports.
        $members = $this->members($meta, $class, $warnings);
        $docblock = $this->propertyDocblock($meta, $class);

        $fqcns = array_values($this->uses);
        sort($fqcns);
        $useBlock = implode("\n", array_map(static fn (string $u): string => "use {$u};", $fqcns));

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

    /**
     * Short name, registering the import. Falls back to the FQCN when the
     * basename collides with the model itself, another generated model, or
     * an import already taken by a different class.
     */
    private function short(string $fqcn, string $modelClass): string
    {
        $fqcn = ltrim($fqcn, '\\');
        $base = class_basename($fqcn);

        $collides = $base === $modelClass
            || in_array($base, $this->reserved, true)
            || (isset($this->uses[$base]) && $this->uses[$base] !== $fqcn);

        if ($collides) {
            return '\\'.$fqcn;
        }

        $this->uses[$base] = $fqcn;

        return $base;
    }

    /** @param ClassMetadata<object> $meta */
    private function propertyDocblock(ClassMetadata $meta, string $modelClass): string
    {
        $lines = [];

        foreach ($meta->getFieldNames() as $field) {
            $lines[] = sprintf(
                ' * @property %s $%s',
                $this->phpType($meta, $field, $modelClass),
                $meta->getColumnName($field),
            );
        }

        foreach ($meta->getAssociationMappings() as $name => $assoc) {
            $target = class_basename($assoc->targetEntity);
            $property = Str::snake($name);

            if ($assoc->isToMany()) {
                $lines[] = sprintf(
                    ' * @property %s<int, %s> $%s',
                    $this->short(Collection::class, $modelClass),
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

            $join = $assoc->joinColumns[0];
            $nullable = $join->nullable ?? true;

            // The foreign key is not among the fields, but it is queried often.
            $lines[] = sprintf(' * @property int%s $%s', $nullable ? '|null' : '', $join->name);
            $lines[] = sprintf(' * @property %s%s $%s', $target, $nullable ? '|null' : '', $property);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  ClassMetadata<object>  $meta
     * @param  list<string>  $warnings
     */
    private function members(ClassMetadata $meta, string $modelClass, array &$warnings): string
    {
        $out = sprintf("    protected \$table = '%s';\n", $meta->getTableName());
        $out .= $this->keyMembers($meta, $modelClass, $warnings);

        // Doctrine manages timestamps itself.
        $out .= "\n    public \$timestamps = false;\n";

        // The lock is ReadOnlyModel, not $fillable. Without this, update()
        // would fail with MassAssignmentException and the same mistake
        // would raise two different exception types depending on the path.
        $out .= "\n    protected \$guarded = [];\n";

        $casts = [];
        foreach ($meta->getFieldNames() as $field) {
            $cast = $this->castFor($meta, $field, $modelClass);
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

        $out .= $this->discriminatorScope($meta, $modelClass);

        foreach ($meta->getAssociationMappings() as $name => $assoc) {
            $out .= $this->relation($name, $assoc, $modelClass);
        }

        return $out;
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
    private function discriminatorScope(ClassMetadata $meta, string $modelClass): string
    {
        if (! $meta->isInheritanceTypeSingleTable() || ($meta->discriminatorValue ?? null) === null) {
            return '';
        }

        $builder = $this->short(Builder::class, $modelClass);

        return sprintf(
            "\n    /** Single table inheritance — this class owns only its own rows. */\n"
            ."    protected static function booted(): void\n    {\n"
            ."        static::addGlobalScope('doctrine_discriminator', static function (%s \$query): void {\n"
            ."            \$query->where('%s', '%s');\n"
            ."        });\n    }\n",
            $builder,
            $meta->discriminatorColumn['name'],
            $meta->discriminatorValue,
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
    private function keyMembers(ClassMetadata $meta, string $modelClass, array &$warnings): string
    {
        $idFields = $meta->getIdentifierFieldNames();

        if (count($idFields) > 1) {
            $warnings[] = sprintf(
                '%s has a composite key (%s) — Eloquent does not support one: find() and getKey() will not work, reading via where() will.',
                $modelClass,
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

    private function relationType(mixed $assoc): string
    {
        return match (true) {
            $assoc instanceof ManyToManyOwningSideMapping,
            $assoc instanceof ManyToManyInverseSideMapping => 'BelongsToMany',
            $assoc instanceof OneToOneInverseSideMapping => 'HasOne',
            $assoc->isToMany() => 'HasMany',
            default => 'BelongsTo',
        };
    }

    private function relation(string $name, mixed $assoc, string $modelClass): string
    {
        $target = class_basename($assoc->targetEntity);
        $method = Str::camel($name);
        $type = $this->relationType($assoc);

        // short() returns an FQCN when the relation class name clashes with
        // a generated model. That return value must be used — otherwise
        // `HasMany` in the body would resolve to the projection of the same
        // name and the call would throw a TypeError.
        $typeRef = $this->short('Illuminate\\Database\\Eloquent\\Relations\\'.$type, $modelClass);

        $args = match ($type) {
            'BelongsToMany' => $this->manyToManyArgs($assoc),
            'HasMany', 'HasOne' => $this->inverseSideArgs($assoc),
            default => sprintf("'%s'", $assoc->joinColumns[0]->name),
        };

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
     * The join table is always declared by the owning side. From the inverse
     * side it has to be reached through mappedBy — with the keys swapped.
     */
    private function manyToManyArgs(mixed $assoc): string
    {
        if ($assoc instanceof ManyToManyOwningSideMapping) {
            $joinTable = $assoc->joinTable;
            $foreign = $joinTable->joinColumns[0]->name;
            $related = $joinTable->inverseJoinColumns[0]->name;
        } else {
            $owning = $this->em
                ->getClassMetadata($assoc->targetEntity)
                ->getAssociationMapping($assoc->mappedBy);

            $joinTable = $owning->joinTable;
            $foreign = $joinTable->inverseJoinColumns[0]->name;
            $related = $joinTable->joinColumns[0]->name;
        }

        return sprintf("'%s', '%s', '%s'", $joinTable->name, $foreign, $related);
    }

    /** The FK for hasMany/hasOne sits on the other side, under the mappedBy name. */
    private function inverseSideArgs(mixed $assoc): string
    {
        $owning = $this->em
            ->getClassMetadata($assoc->targetEntity)
            ->getAssociationMapping($assoc->mappedBy);

        return sprintf("'%s'", $owning->joinColumns[0]->name);
    }

    /** @param ClassMetadata<object> $meta */
    private function castFor(ClassMetadata $meta, string $field, string $modelClass): ?string
    {
        $enum = $meta->getFieldMapping($field)->enumType ?? null;

        if ($enum !== null) {
            return $this->short($enum, $modelClass).'::class';
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
    private function phpType(ClassMetadata $meta, string $field, string $modelClass): string
    {
        $mapping = $meta->getFieldMapping($field);
        $enum = $mapping->enumType ?? null;
        $nullable = $mapping->nullable ?? false;

        $type = $enum !== null
            ? $this->short($enum, $modelClass)
            : match ($meta->getTypeOfField($field)) {
                'integer', 'smallint', 'bigint' => 'int',
                'boolean' => 'bool',
                'decimal', 'float' => 'float',
                'datetime', 'datetime_immutable', 'date', 'date_immutable',
                'time', 'time_immutable' => $this->short(CarbonImmutable::class, $modelClass),
                'json' => 'array',
                default => 'string',
            };

        return $nullable ? $type.'|null' : $type;
    }
}
