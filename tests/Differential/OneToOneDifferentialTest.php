<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Differential;

use Darangonaut\DoctrineProjections\Tests\Fixtures\Pairs\Account;
use Darangonaut\DoctrineProjections\Tests\Fixtures\Pairs\Profile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A OneToOne is generated as belongsTo on the owning side and hasOne on
 * the inverse — the one shape where getting the direction wrong still
 * returns a row, just the wrong one. An account with no profile is here
 * for the same reason: null on one side has to be null on the other.
 */
final class OneToOneDifferentialTest extends TestCase
{
    private Harness $harness;

    private Compare $compare;

    protected function setUp(): void
    {
        $this->harness = Harness::for('Pairs', 'DifferentialPairs'.getmypid());
        $this->compare = new Compare($this->harness);

        foreach (['jana@example.test', 'peter@example.test'] as $email) {
            $account = new Account;
            $account->email = $email;

            $profile = new Profile;
            $profile->bio = 'životopis '.$email;
            $profile->account = $account;
            $account->profile = $profile;

            $this->harness->em()->persist($account);
            $this->harness->em()->persist($profile);
        }

        // deliberately profileless: the inverse side must come back null
        $lonely = new Account;
        $lonely->email = 'sam@example.test';
        $this->harness->em()->persist($lonely);

        $this->harness->em()->flush();
        $this->harness->forget();
    }

    #[Test]
    public function every_column_agrees(): void
    {
        $this->compare->columns(Account::class);
        $this->compare->columns(Profile::class);
    }

    #[Test]
    public function the_owning_side_agrees(): void
    {
        $this->compare->associations(Profile::class);
    }

    #[Test]
    public function the_inverse_side_agrees_including_where_there_is_nothing(): void
    {
        $this->compare->associations(Account::class);
    }
}
