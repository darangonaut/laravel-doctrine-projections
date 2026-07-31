<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A named class rather than an anonymous one: `Model::observe()` registers
 * the observer by class name and resolves it back out of the container,
 * which an anonymous class cannot survive.
 */
class RecordingObserver
{
    /** @var list<string> */
    public static array $seen = [];

    public static function reset(): void
    {
        self::$seen = [];
    }

    public function retrieved(Model $model): void
    {
        self::$seen[] = 'retrieved';
    }

    public function saving(Model $model): void
    {
        self::$seen[] = 'saving';
    }

    public function deleting(Model $model): void
    {
        self::$seen[] = 'deleting';
    }
}
