<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Tests\Fixtures\SelfRef\Person;
use Darangonaut\DoctrineProjections\Tests\Fixtures\SelfRef\Ticket;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two shapes where the generator has nothing but the side to go on.
 *
 * A self-referencing ManyToMany points both join columns at the same
 * table, so a swapped pair still produces a working relation returning
 * the wrong people. And two associations onto the same entity have to
 * keep their own foreign keys apart.
 *
 * The data is deliberately lopsided — one person follows two, one is
 * followed by two, nobody reciprocates — so a reversed direction cannot
 * agree by accident.
 */
final class SelfReferenceDifferentialTest extends TestCase
{
    private Harness $harness;

    private Compare $compare;

    protected function setUp(): void
    {
        $this->harness = Harness::for('SelfRef', 'DifferentialSelfRef'.getmypid());
        $this->compare = new Compare($this->harness);

        $people = [];

        foreach (['Jana', 'Peter', 'Sam'] as $name) {
            $person = new Person;
            $person->name = $name;

            $this->harness->em()->persist($person);

            $people[$name] = $person;
        }

        // Jana follows Peter and Sam; nobody follows Jana.
        foreach (['Peter', 'Sam'] as $name) {
            $people['Jana']->following->add($people[$name]);
            $people[$name]->followers->add($people['Jana']);
        }

        $ticket = new Ticket;
        $ticket->subject = 'Rozbitý export';
        $ticket->author = $people['Peter'];
        $ticket->reviewer = $people['Sam'];

        $unreviewed = new Ticket;
        $unreviewed->subject = 'Bez recenzenta';
        $unreviewed->author = $people['Jana'];

        $this->harness->em()->persist($ticket);
        $this->harness->em()->persist($unreviewed);
        $this->harness->em()->flush();
        $this->harness->forget();
    }

    #[Test]
    public function both_directions_of_the_self_reference_agree(): void
    {
        $this->compare->associations(Person::class);
    }

    #[Test]
    public function the_two_directions_are_not_the_same_set(): void
    {
        // if they were, the test above could pass with the keys swapped
        $projection = $this->harness->projection('Person');

        $jana = $projection::query()->where('name', 'Jana')->first();

        self::assertNotNull($jana);
        self::assertSame(['Peter', 'Sam'], $this->names($jana->getAttribute('following')));
        self::assertSame([], $this->names($jana->getAttribute('followers')));
    }

    #[Test]
    public function two_associations_onto_the_same_entity_keep_their_own_keys(): void
    {
        $this->compare->associations(Ticket::class);

        $projection = $this->harness->projection('Ticket');
        $ticket = $projection::query()->where('subject', 'Rozbitý export')->first();

        self::assertNotNull($ticket);
        self::assertSame('Peter', $this->nameOf($ticket->getAttribute('author')));
        self::assertSame('Sam', $this->nameOf($ticket->getAttribute('reviewer')));
    }

    #[Test]
    public function a_nullable_association_stays_null(): void
    {
        $projection = $this->harness->projection('Ticket');
        $ticket = $projection::query()->where('subject', 'Bez recenzenta')->first();

        self::assertNotNull($ticket);
        self::assertNull($ticket->getAttribute('reviewer'));
        self::assertSame('Jana', $this->nameOf($ticket->getAttribute('author')));
    }

    /** @return list<string> */
    private function names(mixed $collection): array
    {
        self::assertIsIterable($collection);

        $names = [];

        foreach ($collection as $model) {
            $names[] = $this->nameOf($model);
        }

        sort($names);

        return $names;
    }

    private function nameOf(mixed $model): string
    {
        self::assertInstanceOf(Model::class, $model);

        $name = $model->getAttribute('name');

        self::assertIsString($name);

        return $name;
    }
}
