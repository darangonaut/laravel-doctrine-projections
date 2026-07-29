<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Unit;

use Darangonaut\DoctrineProjections\Eloquent\ReadOnlyBuilder;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The blocklist is a hand-written list, so a Laravel upgrade can add a
 * write method we do not know about. That is exactly how touch() slipped
 * through.
 *
 * This test checks completeness rather than behaviour: every writing
 * method on the Eloquent builder must be overridden. If an upgrade adds
 * another one, the test fails before anyone calls it on a projection.
 */
final class ReadOnlyBuilderCoverageTest extends TestCase
{
    /** Write methods on Eloquent\Builder in the current Laravel version. */
    private const array WRITE_METHODS = [
        'update', 'updateFrom', 'updateOrInsert', 'upsert',
        'insert', 'insertGetId', 'insertOrIgnore', 'insertUsing', 'insertOrIgnoreUsing',
        'increment', 'incrementEach', 'decrement', 'decrementEach',
        'touch', 'delete', 'forceDelete', 'truncate', 'incrementOrCreate',
    ];

    #[Test]
    public function every_known_write_method_is_overridden(): void
    {
        $missing = [];

        foreach (self::WRITE_METHODS as $method) {
            if (! method_exists(Builder::class, $method) && ! method_exists(ReadOnlyBuilder::class, $method)) {
                continue; // not present in this version
            }

            $declaring = (new \ReflectionMethod(ReadOnlyBuilder::class, $method))->getDeclaringClass()->getName();

            if ($declaring !== ReadOnlyBuilder::class) {
                $missing[] = $method;
            }
        }

        self::assertSame([], $missing, 'Write methods not overridden: '.implode(', ', $missing));
    }

    /**
     * The other direction: if Laravel adds a write method missing from the
     * list above, we want to know. Heuristic, by name shape.
     */
    #[Test]
    public function no_unknown_write_shaped_method_appeared_in_eloquent_builder(): void
    {
        $suspicious = [];
        $known = array_merge(self::WRITE_METHODS, [
            // read-only or non-persisting despite the name
            // these write through a model instance, so model events catch
            // them — verified in the integration suite
            'updateOrCreate', 'createOrFirst', 'firstOrCreate', 'firstOrNew',
            'create', 'forceCreate', 'forceCreateQuietly', 'make', 'newModelInstance',
            'updateOrCreateQuietly', 'createQuietly', 'restore', 'restoreOrCreate',
        ]);

        foreach ((new \ReflectionClass(Builder::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() !== Builder::class) {
                continue;
            }
            if (preg_match('/^(update|insert|upsert|delete|truncate|touch|increment|decrement)/i', $m->getName())
                && ! in_array($m->getName(), $known, true)) {
                $suspicious[] = $m->getName();
            }
        }

        self::assertSame([], $suspicious,
            'New write-shaped methods — review and add to the blocklist: '.implode(', ', $suspicious));
    }
}
