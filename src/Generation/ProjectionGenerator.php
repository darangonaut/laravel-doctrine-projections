<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Generation;

use Carbon\CarbonImmutable;
use Darangonaut\DoctrineProjections\Eloquent\Casts\SimpleArray;
use Darangonaut\DoctrineProjections\Eloquent\Casts\TimeOfDay;
use Darangonaut\DoctrineProjections\Eloquent\ReadOnlyModel;
use Darangonaut\DoctrineProjections\Exceptions\DuplicateProjectionName;
use Darangonaut\DoctrineProjections\Exceptions\NamespaceCollision;
use Darangonaut\DoctrineProjections\Exceptions\UnsupportedMapping;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\InverseSideMapping;
use Doctrine\ORM\Mapping\JoinColumnMapping;
use Doctrine\ORM\Mapping\ManyToManyInverseSideMapping;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Mapping\OneToOneInverseSideMapping;
use Doctrine\ORM\Mapping\ToManyAssociationMapping;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionProperty;

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

    /** @var list<string>|null names Eloquent's Model already uses */
    private ?array $modelProperties = null;

    /** @var list<string>|null the type names DBAL ships */
    private ?array $builtInTypes = null;

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
        $this->guardAgainstNamespaceCollision($metadata);

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

        // Reported once, on whichever projection is rendered first: the
        // warning is about the mapping as a whole, not about one model.
        $seed = $this->enabledFilterWarning();
        $rendered = [];

        foreach ($metadata as $meta) {
            $projection = $this->render($meta, $seed);
            $seed = [];
            $rendered[$projection->className] = $projection;
        }

        return $rendered;
    }

    /**
     * A Doctrine filter narrows entity queries and cannot narrow a
     * projection — Eloquent queries the table and knows nothing about it.
     *
     * Worth saying loudly rather than listing as a limitation, because
     * the usual reason for a filter is to keep one tenant from seeing
     * another's rows. Measured: with a tenant filter enabled the entity
     * returned 2 rows and the projection returned all 4.
     *
     * Only *enabled* filters can be seen from here — Doctrine's
     * Configuration exposes no way to enumerate configured ones — so this
     * fires when generation happens to run with them on, and the README
     * carries the rest.
     *
     * @return list<string>
     */
    private function enabledFilterWarning(): array
    {
        $filters = array_keys($this->em->getFilters()->getEnabledFilters());

        if ($filters === []) {
            return [];
        }

        return [sprintf(
            'Doctrine filter(s) %s are enabled. They narrow entity queries and cannot narrow a '
            .'projection, which reads the table directly — so rows the entity hides are visible '
            .'through the model. Apply the same condition yourself, or keep those tables '
            .'unprojected.',
            implode(', ', $filters),
        )];
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
            // Keyed case-insensitively: the projections become files, and
            // on macOS or Windows `Order.php` and `order.php` are the same
            // file — one would quietly overwrite the other. PHP class
            // names are case-insensitive too, so nothing is lost by
            // treating them as one name here.
            $byBase[strtolower(class_basename($meta->getName()))][] = $meta->getName();
        }

        $clashes = array_filter($byBase, static fn (array $classes): bool => count($classes) > 1);

        if ($clashes !== []) {
            throw DuplicateProjectionName::between($clashes);
        }
    }

    /**
     * A projection namespace that an entity already lives in would give
     * the generated model the entity's own class name. Whichever the
     * autoloader reaches first wins: either a redeclaration fatal, or an
     * application quietly handed a read-only model where it asked for the
     * entity, with writes failing for reasons nothing explains.
     *
     * @param  list<ClassMetadata<object>>  $metadata
     *
     * @throws NamespaceCollision
     */
    private function guardAgainstNamespaceCollision(array $metadata): void
    {
        $clashes = [];

        foreach ($metadata as $meta) {
            $entity = $meta->getName();

            if ($this->namespace.'\\'.class_basename($entity) === $entity) {
                $clashes[] = $entity;
            }
        }

        if ($clashes !== []) {
            throw NamespaceCollision::with($this->namespace, $clashes);
        }
    }

    /**
     * @param  ClassMetadata<object>  $meta
     * @param  list<string>  $warnings  reported alongside this projection
     */
    private function render(ClassMetadata $meta, array $warnings = []): RenderedProjection
    {
        $class = class_basename($meta->getName());
        $this->imports = new Imports($class, $this->reserved);

        // The base class and the trait go through the collector too — an
        // entity may be named Model or ReadOnlyModel, and then they must
        // be fully qualified.
        $traitRef = $this->imports->reference(ReadOnlyModel::class);
        $modelRef = $this->imports->reference(Model::class);

        // The body is assembled before the header, because assembling it
        // is what collects the imports.
        $members = $this->members($meta, $warnings);
        $docblock = $this->propertyDocblock($meta, $warnings);

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

    /**
     * Names Eloquent's Model already uses for a property of its own.
     *
     * A column called `exists` is not readable as `$flag->exists`: PHP
     * finds Model's public `$exists` and never calls `__get`, so the
     * answer is "this row is persisted" — `true` — while the column says
     * false. Nothing errors.
     *
     * @return list<string>
     */
    private function modelPropertyNames(): array
    {
        return $this->modelProperties ??= array_map(
            static fn (ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(Model::class))->getProperties(),
        );
    }

    /**
     * @param  ClassMetadata<object>  $meta
     * @param  list<string>  $warnings
     */
    private function propertyDocblock(ClassMetadata $meta, array &$warnings): string
    {
        $lines = [];

        foreach ($meta->getFieldNames() as $field) {
            $column = $meta->getColumnName($field);

            // Documenting a shadowed column would tell every IDE and
            // static analyser that `$model->exists` is the column, which is
            // the one thing it is not. The column is still readable — just
            // not that way.
            if (in_array($column, $this->modelPropertyNames(), true)) {
                $warnings[] = sprintf(
                    'column "%s" has the same name as an Eloquent Model property, so $model->%s '
                    .'returns the framework value, not the column. Read it with '
                    ."getAttribute('%s') — it is left out of the docblock for that reason.",
                    $column,
                    $column,
                    $column,
                );

                continue;
            }

            $lines[] = sprintf(
                ' * @property %s $%s',
                $this->phpType($meta, $field),
                $column,
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

            $joinColumns = $this->joinColumnsOf($assoc);

            $nullable = $assoc instanceof ToOneOwningSideMapping
                ? ($joinColumns[0]->nullable ?? true)
                : true;

            // The foreign keys are not among the fields, but they are
            // queried often. Their type comes from the column they point
            // at — it was hardcoded to `int`, which is wrong the moment a
            // key is a UUID.
            foreach ($joinColumns as $joinColumn) {
                $lines[] = sprintf(
                    ' * @property %s%s $%s',
                    $this->referencedType($assoc, $joinColumn->referencedColumnName),
                    $nullable ? '|null' : '',
                    $joinColumn->name,
                );
            }

            // More than one join column means the target has a composite
            // key, which belongsTo cannot express — see members().
            if (count($joinColumns) > 1) {
                continue;
            }

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
        $out = sprintf("    protected \$table = '%s';\n", $this->qualifiedTableName($meta));
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

            $this->warnAboutCustomType($meta, $field, $warnings);
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

            // A to-one association across several join columns means the
            // target has a composite key. `belongsTo` takes one column, so
            // the generated relation matched on the first and ignored the
            // rest — returning any row that happened to share it, quietly.
            if (! $assoc->isToMany() && count($this->joinColumnsOf($assoc)) > 1) {
                $warnings[] = sprintf(
                    'Skipped relation %s::$%s — it joins %s on %d columns, which belongsTo cannot '
                    .'express. Matching on the first alone would return the wrong row. The key '
                    .'columns are still readable; join them yourself.',
                    class_basename($meta->getName()),
                    $name,
                    class_basename($assoc->targetEntity),
                    count($this->joinColumnsOf($assoc)),
                );

                continue;
            }

            // `indexBy` keys the collection by a field. An Eloquent
            // relation always returns 0..n and has no hook to change that
            // which would survive regeneration, so the two sides disagree
            // about the keys — `$config->settings['timezone']` is the
            // entity on one side and null on the other.
            $field = $this->indexedBy($assoc);

            if ($field !== null) {
                $target = $this->em->getClassMetadata($assoc->targetEntity);

                $warnings[] = sprintf(
                    'Relation %s::$%s is indexed by "%s"; an Eloquent relation cannot be, so it '
                    ."returns a list where the entity returns a map. Use ->keyBy('%s') at the "
                    .'call site if you need the keys.',
                    class_basename($meta->getName()),
                    $name,
                    $field,
                    $target->hasField($field) ? $target->getColumnName($field) : $field,
                );
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

        if (! $meta->isInheritanceTypeSingleTable() || $column === null) {
            return '';
        }

        // The root represents "every row" and stays unscoped. Everything
        // below it is scoped — including an abstract class in the middle,
        // which has no discriminator value of its own but does have
        // subclasses. Requiring an own value left `AbstractFile`
        // unscoped, so it returned folders too: Doctrine said 3 rows, the
        // projection said 4.
        if ($meta->rootEntityName === $meta->getName()) {
            return '';
        }

        $values = $this->discriminatorValuesFor($meta);

        $builder = $this->imports->reference(Builder::class);

        // One value is the common case and reads better as where().
        //
        // No values at all means an abstract class that nothing concrete
        // extends. No row can carry a discriminator that is not in the
        // map, so it owns none — but returning no scope let it own
        // *every* row instead: Doctrine answered 0, the projection
        // answered all of them. The matching condition is Doctrine's own;
        // it emits `WHERE 1=0` for exactly this case.
        $condition = match (count($values)) {
            0 => "\$query->whereRaw('1 = 0');",
            1 => sprintf("\$query->where('%s', '%s');", $column, $values[0]),
            default => sprintf("\$query->whereIn('%s', ['%s']);", $column, implode("', '", $values)),
        };

        $title = match (count($values)) {
            0 => 'abstract with nothing below it, so no row is ever one of these',
            1 => 'this class owns only its own rows',
            default => 'this class and everything below it',
        };

        return sprintf(
            "\n    /** Single table inheritance — %s. */\n"
            ."    protected static function booted(): void\n    {\n"
            ."        static::addGlobalScope('doctrine_discriminator', static function (%s \$query): void {\n"
            ."            %s\n"
            ."        });\n    }\n"
            ."\n    /**\n"
            ."     * Laravel restores a queued model with newQueryWithoutScopes(), so a\n"
            ."     * soft-deleted one can come back. A projection has no such case, and\n"
            ."     * dropping the scope here meant find() and a job disagreed: the id of\n"
            ."     * a sibling subclass was null through one and a wrongly-typed row\n"
            ."     * through the other.\n"
            ."     *\n"
            ."     * @param  array<int, mixed>|mixed  \$ids\n"
            ."     * @return %s<static>\n"
            ."     */\n"
            ."    public function newQueryForRestoration(\$ids)\n    {\n"
            ."        /** @var %s<static> */\n"
            ."        return \$this->newQuery()->whereKey(\$ids);\n    }\n",
            $title,
            $builder,
            $condition,
            $builder,
            $builder,
        );
    }

    /**
     * The discriminator values a class answers to: its own, plus every
     * subclass below it.
     *
     * Scoping to its own alone is right for a leaf and wrong for anything
     * with children — a `CorporateCardPayment` *is* a `CardPayment`, and
     * Doctrine returns it from `CardPayment` queries. Measured on a
     * three-level hierarchy, the projection returned 1 row where the
     * entity returned 3: an undercount, silently.
     *
     * @param  ClassMetadata<object>  $meta
     * @return list<string>
     */
    private function discriminatorValuesFor(ClassMetadata $meta): array
    {
        $own = $meta->discriminatorValue;

        // An abstract class in the middle has none of its own — it is
        // only ever its subclasses.
        $values = is_scalar($own) ? [(string) $own] : [];

        foreach ($meta->subClasses as $subClass) {
            $subValue = $this->em->getClassMetadata($subClass)->discriminatorValue;

            if (is_scalar($subValue)) {
                $values[] = (string) $subValue;
            }
        }

        return array_values(array_unique($values));
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

        // A method defined on the class wins over the same method from a
        // trait, silently — so an association named `delete` would replace
        // the write lock with a relation and PHP would not say a word.
        // Every other name here would quietly replace framework behaviour
        // the projection depends on.
        if (method_exists(Model::class, $method) || method_exists(ReadOnlyModel::class, $method)) {
            throw UnsupportedMapping::relationShadowsModelMethod($assoc->sourceEntity, $name, $method);
        }

        // short() returns an FQCN when the relation class name clashes with
        // a generated model. That return value must be used — otherwise
        // `HasMany` in the body would resolve to the projection of the same
        // name and the call would throw a TypeError.
        $typeRef = $this->imports->reference('Illuminate\\Database\\Eloquent\\Relations\\'.$type);

        $args = $this->relationArgs($assoc, $type);

        return sprintf(
            "\n    /** @return %s<%s, \$this> */\n"
            ."    public function %s(): %s\n    {\n"
            ."        return \$this->%s(%s::class, %s)%s;\n    }\n",
            $typeRef,
            $target,
            $method,
            $typeRef,
            // the method name comes from the bare type, not the reference
            lcfirst($type),
            $target,
            $args,
            $this->ordering($assoc),
        );
    }

    /**
     * Mirrors Doctrine's `#[ORM\OrderBy]` onto the relation.
     *
     * Dropping it looked harmless and was not: the same association came
     * back in one order through the entity and another through the
     * projection — measured on a list whose insertion order was the
     * reverse of its `position`. Silently diverging from the mapping is
     * the failure this package exists to prevent.
     *
     * Doctrine keys `orderBy` by *field* name, so `dueOn` has to become
     * `due_on` here; emitting the field name would produce SQL for a
     * column that does not exist.
     */
    private function ordering(AssociationMapping $assoc): string
    {
        // Only to-many mappings carry an ordering, and it is reached
        // through the interface method — the property behind it lives on
        // an implementation trait, so `->orderBy` is undefined on a
        // ManyToOne rather than null.
        if (! $assoc instanceof ToManyAssociationMapping) {
            return '';
        }

        $target = $this->em->getClassMetadata($assoc->targetEntity);
        $chain = '';

        foreach ($assoc->orderBy() as $field => $direction) {
            if (! $target->hasField($field)) {
                throw UnsupportedMapping::unorderableAssociation($assoc->sourceEntity, $field);
            }

            // Doctrine's interface types this as 'asc'|'desc' but stores
            // whatever the attribute said, so 'DESC' arrives here. Laravel
            // would lowercase it anyway; doing it now keeps the generated
            // file from varying with how someone typed the mapping.
            $chain .= sprintf(
                "\n            ->orderBy('%s', '%s')",
                $target->getColumnName($field),
                strtolower($direction) === 'desc' ? 'desc' : 'asc',
            );
        }

        return $chain;
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
     * The table, with its schema when the mapping names one.
     *
     * `getTableName()` returns the bare name, so an entity mapped to
     * `archive.entries` produced `$table = 'entries'` — pointing at
     * whatever `entries` the search path finds first. On PostgreSQL that
     * is either an error or, worse, a different table with the same name.
     * Eloquent's grammar quotes each dotted segment, so the qualified
     * name is what it wants.
     *
     * @param  ClassMetadata<object>  $meta
     */
    private function qualifiedTableName(ClassMetadata $meta): string
    {
        $schema = $meta->getSchemaName();

        return $schema === null || $schema === ''
            ? $meta->getTableName()
            : $schema.'.'.$meta->getTableName();
    }

    /**
     * A type DBAL does not ship is one Eloquent cannot know about, so the
     * projection reads whatever sits in the column while the entity hands
     * back whatever `convertToPHPValue()` made of it.
     *
     * The generated docblock already says `string` rather than promising
     * the value object, so this is visible to static analysis — but only
     * to someone who looks. Saying it at generation is cheaper.
     *
     * Built-ins come from DBAL's own `Types` constants, so the list
     * cannot fall behind the library.
     *
     * @param  ClassMetadata<object>  $meta
     * @param  list<string>  $warnings
     */
    private function warnAboutCustomType(ClassMetadata $meta, string $field, array &$warnings): void
    {
        $type = $meta->getTypeOfField($field);

        if (! is_string($type)) {
            return;
        }

        $this->builtInTypes ??= array_values(array_filter(
            (new ReflectionClass(Types::class))->getConstants(),
            'is_string',
        ));

        if (in_array($type, $this->builtInTypes, true)) {
            return;
        }

        $warnings[] = sprintf(
            'Column "%s" uses the custom Doctrine type "%s". The projection reads the stored '
            .'value; only the entity gets what convertToPHPValue() makes of it.',
            $meta->getColumnName($field),
            $type,
        );
    }

    /**
     * The field a to-many collection is keyed by, if any.
     *
     * Doctrine offers `isIndexed()` for this, but its interface carries
     * `@phpstan-assert-if-true string $this->indexBy()` while `indexBy()`
     * is already natively `string` — so an honest check reads as
     * always-true to static analysis, with no setting to turn that off.
     * The mapping's array form answers the same question without the
     * annotation in the way.
     */
    private function indexedBy(AssociationMapping $assoc): ?string
    {
        $indexBy = $assoc->toArray()['indexBy'] ?? null;

        return is_string($indexBy) && $indexBy !== '' ? $indexBy : null;
    }

    /**
     * The join columns of the owning side. On an owning side they are
     * declared here; on an inverse side they live on the other class,
     * under the mappedBy name.
     *
     * There is more than one when the target has a composite key.
     *
     * @return non-empty-list<JoinColumnMapping>
     */
    private function joinColumnsOf(AssociationMapping $assoc): array
    {
        $owning = $assoc instanceof ToOneOwningSideMapping
            ? $assoc
            : $this->owningSideOf($assoc);

        if (! $owning instanceof ToOneOwningSideMapping) {
            throw UnsupportedMapping::unexpectedOwningSide($assoc->targetEntity, $owning::class);
        }

        // Doctrine types this as a possibly-empty list. It never is for a
        // to-one owning side — it defaults one in — but the callers index
        // into it, so the assumption is checked rather than trusted.
        if ($owning->joinColumns === []) {
            throw UnsupportedMapping::unexpectedOwningSide($assoc->targetEntity, 'an association with no join column');
        }

        return $owning->joinColumns;
    }

    /**
     * The foreign key column. Only meaningful for a single-column join —
     * callers handle the composite case before reaching here.
     */
    private function foreignKeyFor(AssociationMapping $assoc): string
    {
        return $this->joinColumnsOf($assoc)[0]->name;
    }

    /**
     * The PHP type of the column a join column points at.
     *
     * This used to be hardcoded to `int`, so a projection referencing an
     * entity keyed by UUID documented `@property int|null $parent_uuid`
     * for a VARCHAR(36) — wrong for every non-integer key, and static
     * analysis believed it.
     */
    private function referencedType(AssociationMapping $assoc, string $referencedColumn): string
    {
        $target = $this->em->getClassMetadata($assoc->targetEntity);

        try {
            return $this->phpType($target, $target->getFieldForColumn($referencedColumn));
        } catch (MappingException) {
            // A column with no field behind it — nothing to read a type
            // from, and guessing is what caused this in the first place.
            return 'mixed';
        }
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
            'integer', 'smallint' => "'integer'",
            // Doctrine hands back a string for both of these, and for the
            // same reason: an int cannot hold every BIGINT (unsigned goes
            // past PHP_INT_MAX) and a float cannot hold a DECIMAL. Casting
            // to int or float silently changed the value — measured on
            // MySQL, 12345678901234.5678 came back as …4.568.
            //
            // Plain `string` rather than Laravel's `decimal:N`: that one
            // pads to a fixed scale, which on SQLite (no real DECIMAL)
            // invents places Doctrine does not report. Returning exactly
            // what the driver gave is what keeps the two sides equal.
            'bigint', 'decimal' => "'string'",
            'boolean' => "'boolean'",
            'float' => "'float'",
            'datetime', 'datetime_immutable', 'datetimetz', 'datetimetz_immutable' => "'immutable_datetime'",
            'date', 'date_immutable' => "'immutable_date'",
            // Laravel has no cast for either of these, so the package
            // ships one that matches Doctrine's own conversion.
            'time', 'time_immutable' => $this->imports->reference(TimeOfDay::class).'::class',
            'simple_array' => $this->imports->reference(SimpleArray::class).'::class',
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
                'integer', 'smallint' => 'int',
                'boolean' => 'bool',
                'float' => 'float',
                // string on both, matching the casts above
                'bigint', 'decimal' => 'string',
                'datetime', 'datetime_immutable', 'datetimetz', 'datetimetz_immutable',
                'date', 'date_immutable',
                'time', 'time_immutable' => $this->imports->reference(CarbonImmutable::class),
                // level max in a consuming project rejects a bare `array`,
                // and the generated file is code they did not write and
                // cannot fix
                'simple_array' => 'list<string>',
                'json' => 'array<array-key, mixed>',
                default => 'string',
            };

        return $nullable ? $type.'|null' : $type;
    }
}
