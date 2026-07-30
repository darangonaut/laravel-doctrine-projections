<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Filtered\Note;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Filtered\TenantFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The one divergence with a security shape.
 *
 * A Doctrine filter narrows entity queries; a projection reads the table
 * and knows nothing about it. Since the usual reason for a filter is to
 * keep one tenant out of another's rows, "not supported" is too quiet a
 * way to put it — this test records exactly what leaks, so the claim
 * cannot rot into an assumption.
 */
final class FilteredDifferentialTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Filtered', 'DifferentialFiltered'.getmypid());

        foreach ([[1, 'tenant 1 – A'], [1, 'tenant 1 – B'], [2, 'tenant 2 – private'], [2, 'tenant 2 – private 2']] as [$tenant, $body]) {
            $note = new Note;
            $note->tenantId = $tenant;
            $note->body = $body;

            $this->harness->em()->persist($note);
        }

        $this->harness->em()->flush();
        $this->harness->forget();
    }

    private function enableTenantFilter(int $tenant): void
    {
        $this->harness->em()->getConfiguration()->addFilter('tenant', TenantFilter::class);
        $this->harness->em()->getFilters()->enable('tenant')->setParameter('tenant', $tenant);
    }

    #[Test]
    public function the_filter_narrows_the_entity_and_not_the_projection(): void
    {
        $this->enableTenantFilter(1);

        $entities = $this->harness->em()->getRepository(Note::class)->findAll();

        self::assertCount(2, $entities, 'Doctrine applies the filter');

        $projection = $this->harness->projection('Note');

        self::assertSame(4, $projection::query()->count(), 'the projection reads the table');

        $leaked = $projection::query()->where('tenant_id', '!=', 1)->pluck('body')->all();

        self::assertSame(['tenant 2 – private', 'tenant 2 – private 2'], $leaked);
    }

    #[Test]
    public function generation_says_so_when_a_filter_is_enabled(): void
    {
        $this->enableTenantFilter(1);

        $rendered = (new ProjectionGenerator(
            $this->harness->em(),
            'FilteredWarnings'.getmypid(),
        ))->generate();

        $warnings = $rendered['Note']->warnings;

        self::assertCount(1, $warnings);
        self::assertStringContainsString('tenant', $warnings[0]);
        self::assertStringContainsString('rows the entity hides are visible', $warnings[0]);
    }

    #[Test]
    public function nothing_is_said_when_no_filter_is_enabled(): void
    {
        $rendered = (new ProjectionGenerator(
            $this->harness->em(),
            'UnfilteredWarnings'.getmypid(),
        ))->generate();

        self::assertSame([], $rendered['Note']->warnings);
    }

    /**
     * Without a filter the two sides agree, which is what makes the
     * divergence above attributable to the filter rather than to the
     * fixture.
     */
    #[Test]
    public function without_a_filter_both_sides_agree(): void
    {
        (new Compare($this->harness))->columns(Note::class);

        $projection = $this->harness->projection('Note');

        self::assertSame(
            count($this->harness->em()->getRepository(Note::class)->findAll()),
            $projection::query()->count(),
        );
    }
}
