<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Filtered;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/** The shape people reach for when a table holds more than one tenant. */
final class TenantFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (! $targetEntity->hasField('tenantId')) {
            return '';
        }

        return sprintf('%s.tenant_id = %s', $targetTableAlias, $this->getParameter('tenant'));
    }
}
