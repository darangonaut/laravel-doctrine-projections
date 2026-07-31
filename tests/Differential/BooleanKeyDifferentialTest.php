<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Tests\Fixtures\BooleanKey\Flag;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A two-row lookup table keyed by a boolean. Unusual, entirely legal, and
 * the one key type where Eloquent's two-way choice of `$keyType` does
 * damage.
 *
 * `whereKey()` casts the value when the key type is 'string', and
 * `(string) false` is the empty string — so `find(false)` queried
 * `where enabled = ''` and answered null while the entity found the row.
 * `find(true)` worked, which is what made it worth finding: half of a
 * two-row table.
 */
final class BooleanKeyDifferentialTest extends TestCase
{
    private Harness $harness;

    protected function setUp(): void
    {
        $this->harness = Harness::for('BooleanKey', 'DifferentialFlag'.getmypid());

        foreach ([[true, 'zapnute'], [false, 'vypnute']] as [$enabled, $label]) {
            $flag = new Flag;
            $flag->enabled = $enabled;
            $flag->label = $label;

            $this->harness->em()->persist($flag);
        }

        $this->harness->em()->flush();
        $this->harness->forget();
    }

    #[Test]
    public function both_rows_are_findable_by_key(): void
    {
        // A list of pairs, not a map: PHP array keys turn true and false
        // into 1 and 0, and `find(0)` never reproduced the bug — `(string)
        // 0` is '0', which matches, while `(string) false` is ''.
        foreach ([[true, 'zapnute'], [false, 'vypnute']] as [$key, $label]) {
            $entity = $this->harness->em()->find(Flag::class, $key);
            $model = $this->harness->projection('Flag')::query()->find($key);

            self::assertNotNull($entity, 'the entity lost '.var_export($key, true));
            self::assertNotNull($model, 'the projection lost '.var_export($key, true));
            self::assertSame($label, $entity->label);
            self::assertSame($label, $model->getAttribute('label'));
        }
    }

    /** The key comes back as a boolean, not as '1' or ''. */
    #[Test]
    public function the_key_keeps_its_type(): void
    {
        $model = $this->harness->projection('Flag')::query()->where('label', 'vypnute')->first();

        self::assertNotNull($model);
        self::assertFalse($model->getKey());
    }

    #[Test]
    public function every_column_agrees_with_the_entity(): void
    {
        (new Compare($this->harness))->columns(Flag::class);
    }
}
