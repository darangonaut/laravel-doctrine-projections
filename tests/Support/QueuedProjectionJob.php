<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;

/**
 * The shape of a queued job that carries a model: `SerializesModels`
 * replaces it with its class and key on the way out and looks it up again
 * on the way back.
 *
 * A named class rather than an anonymous one — PHP refuses to serialize
 * anonymous classes, which would hide the thing under test.
 */
final class QueuedProjectionJob
{
    use SerializesModels;

    public function __construct(public Model $model) {}
}
