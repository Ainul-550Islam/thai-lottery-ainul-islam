<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Exceptions\FinancialException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * An invocation-local witness of the caller's write context, not its owner.
 *
 * Metadata serialization is extension code. Before posting DML, require the
 * same manager, registered default writer, raw PDO, framework depth and live
 * physical transaction that held the posting locks before those callbacks.
 * Never resolve/reconnect a missing PDO or repair the caller's transaction.
 * This is not a server transaction-generation token: a raw commit-and-rebegin
 * on the same PDO at the same depth cannot be identified by these APIs.
 */
final readonly class LedgerPostingWriteBoundary
{
    private function __construct(
        private DatabaseManager $manager,
        private string $name,
        private Connection $connection,
        private PDO $pdo,
        private int $depth,
    ) {}

    public static function capture(): self
    {
        $manager = DB::getFacadeRoot();
        if (! $manager instanceof DatabaseManager) {
            self::refuse('manager_unavailable');
        }
        $name = $manager->getDefaultConnection();
        $connection = $manager->getConnections()[$name] ?? null;
        if (! $connection instanceof Connection) {
            self::refuse('writer_unregistered');
        }
        $pdo = $connection->getRawPdo();
        if (! $pdo instanceof PDO) {
            self::refuse('write_pdo_unavailable');
        }
        $depth = $connection->transactionLevel();
        if ($depth < 1) {
            self::refuse('framework_depth_invalid');
        }
        self::assertActive($pdo);

        return new self($manager, $name, $connection, $pdo, $depth);
    }

    public function assertUnchanged(): void
    {
        if (DB::getFacadeRoot() !== $this->manager) {
            self::refuse('manager_changed');
        }
        // Compare the name before inspecting the registration map. Do not let a
        // callback route a lazy connection lookup to another host/store.
        if ($this->manager->getDefaultConnection() !== $this->name) {
            self::refuse('default_writer_changed');
        }
        if (($this->manager->getConnections()[$this->name] ?? null) !== $this->connection) {
            self::refuse('writer_registration_changed');
        }
        if ($this->connection->getRawPdo() !== $this->pdo) {
            self::refuse('write_pdo_changed');
        }
        if ($this->connection->transactionLevel() !== $this->depth) {
            self::refuse('framework_depth_changed');
        }
        self::assertActive($this->pdo);
    }

    private static function assertActive(PDO $pdo): void
    {
        try {
            $active = $pdo->inTransaction();
        } catch (Throwable) {
            self::refuse('physical_state_unavailable');
        }
        if (! $active) {
            self::refuse('physical_transaction_inactive');
        }
    }

    private static function refuse(string $reason): never
    {
        throw FinancialException::withCode(
            'ledger_posting_write_context_changed',
            'The caller write context is not intact at the ledger write boundary.',
            ['stage' => 'payload_preflight', 'reason' => $reason],
        );
    }
}
