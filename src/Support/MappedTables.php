<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;

/**
 * Every table the mapping owns, join tables included.
 *
 * This exists because the obvious one-liner is wrong in a way that stays
 * quiet for a long time:
 *
 *     array_map(fn ($meta) => $meta->getTableName(), $allMetadata)
 *
 * A join table has no entity, so that list leaves it out. Use it for the
 * DBAL schema asset filter and Doctrine can no longer see the join table
 * it owns; `doctrine:diff` then cannot tell whether a rebuild of it keeps
 * the rows, and asks for `--allow-destructive` on a migration that loses
 * nothing. Use it for the classifier and a `DROP TABLE task_tag` looks
 * like a table nobody maps, which is fatal and cannot be overridden.
 *
 * Neither shows up until something touches a join table — renaming the
 * table of a joined entity is enough.
 */
final class MappedTables
{
    /** @return list<string> */
    public static function of(EntityManagerInterface $em): array
    {
        return self::fromMetadata($em->getMetadataFactory()->getAllMetadata());
    }

    /**
     * @param  list<ClassMetadata<object>>  $metadata
     * @return list<string>
     */
    public static function fromMetadata(array $metadata): array
    {
        $tables = [];

        foreach ($metadata as $meta) {
            $tables[] = $meta->getTableName();

            foreach ($meta->associationMappings as $association) {
                // only the owning side carries the join table definition
                if ($association instanceof ManyToManyOwningSideMapping) {
                    $tables[] = $association->joinTable->name;
                }
            }
        }

        return array_values(array_unique($tables));
    }
}
