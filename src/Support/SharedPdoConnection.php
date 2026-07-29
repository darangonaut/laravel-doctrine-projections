<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Support;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\PDO\Connection as PdoConnection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use PDO;

/**
 * A PDO connection that knows someone else may have opened the transaction.
 *
 * When Doctrine and Eloquent share one PDO, `DB::beginTransaction()` opens
 * a transaction DBAL knows nothing about, and `$em->flush()` then fails
 * with "There is already an active transaction".
 *
 * The fix: if a transaction is already open, Doctrine borrows it instead
 * of starting its own, and neither commits nor rolls it back — that
 * belongs to whoever opened it. If flush() fails the exception still
 * propagates, so `DB::transaction()` rolls the whole thing back correctly.
 *
 * Doctrine's PDO\Connection is final, hence delegation over inheritance.
 */
final class SharedPdoConnection implements DriverConnection
{
    private readonly PdoConnection $inner;

    private bool $borrowed = false;

    public function __construct(private readonly PDO $pdo)
    {
        $this->inner = new PdoConnection($pdo);
    }

    /* ── transactions: the only thing that behaves differently ──────── */

    public function beginTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->borrowed = true;

            return;
        }

        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        if ($this->borrowed) {
            // The owner commits it, not us.
            $this->borrowed = false;

            return;
        }

        $this->inner->commit();
    }

    public function rollBack(): void
    {
        if ($this->borrowed) {
            // We cannot and must not roll back someone else's transaction —
            // it would revert their writes too. The exception that brought
            // us here propagates, and the owner rolls it back.
            $this->borrowed = false;

            return;
        }

        $this->inner->rollBack();
    }

    /* ── the rest is plain delegation ───────────────────────────────── */

    public function prepare(string $sql): Statement
    {
        return $this->inner->prepare($sql);
    }

    public function query(string $sql): Result
    {
        return $this->inner->query($sql);
    }

    public function quote(string $value): string
    {
        return $this->inner->quote($value);
    }

    public function exec(string $sql): int
    {
        return $this->inner->exec($sql);
    }

    public function lastInsertId(): int|string
    {
        return $this->inner->lastInsertId();
    }

    public function getServerVersion(): string
    {
        return $this->inner->getServerVersion();
    }

    public function getNativeConnection(): PDO
    {
        return $this->pdo;
    }
}
