<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\ServerVersionProvider;
use PDO;
use SensitiveParameter;

/**
 * A Doctrine driver that opens no connection of its own and uses
 * Laravel's instead.
 *
 * Optional helper — the package does not wire it. It exists because
 * deriving connection parameters from `config('database.connections.*')`
 * looks safe but diverges whenever Laravel built the connection from
 * something other than the plain keys:
 *
 *   - `DB_URL` — ConnectionFactory parses it and overrides host/port/db
 *   - `unix_socket` — the connection goes through a socket, not host:port
 *   - sqlite `:memory:` — two in-memory connections are two databases
 *   - `foreign_key_constraints` — Laravel issues the PRAGMA, Doctrine does not
 *
 * Sharing one PDO instance makes those cases unremarkable: wherever
 * Eloquent lands, Doctrine lands. A welcome side effect is that
 * `DB::transaction()` then wraps `$em->flush()` too.
 *
 * The platform and exception conversion come from the decorated driver,
 * which knows the dialect. Transactions are handled by
 * SharedPdoConnection — on a shared PDO the other side may open them.
 */
final class SharedPdoDriver implements Driver
{
    public function __construct(
        private readonly Driver $decorated,
        private readonly PDO $pdo,
    ) {}

    /** @param array<string, mixed> $params */
    public function connect(#[SensitiveParameter] array $params): DriverConnection
    {
        return new SharedPdoConnection($this->pdo);
    }

    public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
    {
        return $this->decorated->getDatabasePlatform($versionProvider);
    }

    public function getExceptionConverter(): Driver\API\ExceptionConverter
    {
        return $this->decorated->getExceptionConverter();
    }
}
