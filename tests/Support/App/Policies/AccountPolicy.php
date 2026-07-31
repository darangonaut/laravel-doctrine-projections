<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Support\App\Policies;

/**
 * Where Laravel's convention says a policy for
 * `…\App\Models\Projections\Account` may live. The guesser walks the
 * namespace upwards, so the question is whether it walks far enough past
 * the extra `Projections` segment.
 */
class AccountPolicy
{
    public function view(mixed $user, mixed $account): bool
    {
        return true;
    }
}
