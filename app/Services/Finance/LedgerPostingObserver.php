<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Exceptions\FinancialException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * A current, nonlocking journal observation while the caller owns the root lock.
 * No caller or borrowed transaction/connection may be repaired or disconnected.
 */
final class LedgerPostingObserver
{
    public function hasPosting(Connection $writer, int $transactionId): bool
    {
        $writerPdo = $writer->getPdo();
        if ($writer->transactionLevel() < 1 || ! $writerPdo->inTransaction() || $transactionId < 1) {
            $this->invalid();
        }
        $config = $writer->getConfig();
        unset($config['read'], $config['write']);
        $config['options'][PDO::ATTR_PERSISTENT] = false;
        $observer = app('db.factory')->make($config, 'ledger_posting_current_observer');
        $owned = false;
        try {
            if (! $observer instanceof Connection || $observer === $writer) {
                $this->invalid();
            }
            // Do not resolve another manager connection's lazy PDO, change its
            // depth, or disconnect it, even when it currently appears idle.
            foreach (DB::getConnections() as $registered) {
                if ($registered === $observer) {
                    $this->invalid();
                }
            }
            if ($observer->getRawReadPdo() !== null) {
                $this->invalid();
            }
            $observerPdo = $observer->getPdo();
            if ($observerPdo === $writerPdo) {
                $this->invalid();
            }
            foreach (DB::getConnections() as $registered) {
                if ($registered->getRawPdo() === $observerPdo || $registered->getRawReadPdo() === $observerPdo) {
                    $this->invalid();
                }
            }
            if ($observer->transactionLevel() !== 0 || $observerPdo->inTransaction()) {
                $this->invalid();
            }
            if ($writerPdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
                || $observerPdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                $this->invalid();
            }
            // Only now is cleanup ours: distinct, unregistered, unsplit and idle.
            // An active/borrowed candidate is refused without touching its state.
            $owned = true;
            if ((int) $observer->selectOne('SELECT @@SESSION.autocommit AS enabled', [], false)->enabled !== 1) {
                $this->invalid();
            }
            $identity = 'SELECT DATABASE() AS db_name, @@hostname AS server_host, @@port AS server_port, '
                .'@@socket AS server_socket, @@datadir AS server_directory, @@server_id AS server_identifier';
            if ((array) $writer->selectOne($identity, [], false) !== (array) $observer->selectOne($identity, [], false)) {
                throw FinancialException::withCode('ledger_posting_current_reader_mismatch',
                    'Journal observation did not reach the same physical writer store.');
            }

            return $observer->table('ledger_entries')->useWritePdo()
                ->where('financial_transaction_id', $transactionId)->exists();
        } finally {
            if ($owned) {
                $observer->disconnect();
            }
        }
    }

    private function invalid(): never
    {
        throw FinancialException::withCode('ledger_posting_current_reader_invalid',
            'Journal observation requires a distinct owned idle autocommit writer connection.');
    }
}
