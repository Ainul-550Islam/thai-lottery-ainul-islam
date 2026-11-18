<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Services\Ops\LedgerIntegrityChecker;
use Illuminate\Console\Command;

/**
 * ledger:verify — read-only proof that wallets and the ledger agree.
 *
 * WHY THIS EXISTS ALONGSIDE ledger:audit-integrity
 * That command answers "are the ledger's own rows internally consistent" (currency
 * identity, transaction balance, hash chain). This one answers the player-facing
 * question "does each wallet's stored balance equal the sum of its entries", plus
 * the exit-code contract an alerting wrapper needs. Neither writes anything, and
 * the runbook (docs/ops/RUNBOOK_ledger_mismatch.md) says which to reach for.
 *
 * EXIT CODES ARE THE INTERFACE
 *   0  every check ran and found nothing
 *   1  a discrepancy was found  -> page the on-call, do not "fix" it here
 *   2  a check could not be run -> the verification itself is broken
 * There is deliberately no --fix.
 *
 * EXIT CODES, because this is what a cron wrapper and an on-call alert read:
 *   0  every wallet and every transaction balanced
 *   1  at least one discrepancy found (NOTHING was corrected)
 *   2  the check itself could not run (a database that cannot be read is not a
 *      clean bill of health, and must not exit 0)
 *
 * The existing ledger:audit-integrity command asserts inside the posting path;
 * this one is the out-of-band sweep. They are deliberately separate: a sweep that
 * threw an assertion would crash a cron job rather than page a human with a report.
 *
 * ON MISMATCH it prints the discrepancies and STOPS. There is no --fix flag, and
 * adding one would defeat the point of the command: see
 * docs/ops/RUNBOOK_ledger_mismatch.md.
 */
final class VerifyLedgerIntegrityCommand extends Command
{
    protected $signature = 'ledger:verify
        {--chunk=500 : Wallets/transactions per chunk}
        {--json : Emit the report as JSON}';

    protected $description = 'Read-only ledger-vs-wallet integrity sweep. Exit 1 on any discrepancy.';

    public function handle(LedgerIntegrityChecker $checker): int
    {
        try {
            $report = $checker->check(null, (int) $this->option('chunk'));
        } catch (\Throwable $e) {
            $this->error('The integrity sweep could not run: '.$e->getMessage());

            return 2;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'Checked %d wallets and %d financial transactions.',
                $report['checked_wallets'],
                $report['checked_transactions'],
            ));

            foreach ($report['wallet_discrepancies'] as $row) {
                $this->error(sprintf(
                    'WALLET %d (user %d, %s): ledger %s vs stored %s (difference %s)',
                    $row['wallet_id'],
                    $row['user_id'],
                    $row['wallet_type'],
                    $row['ledger_balance'],
                    $row['stored_balance'],
                    $row['difference'],
                ));
            }

            foreach ($report['transaction_discrepancies'] as $row) {
                $this->error(sprintf(
                    'TRANSACTION %d (%s): %s debit %s credit %s',
                    $row['financial_transaction_id'],
                    $row['reference'],
                    $row['reason'],
                    $row['debit_total'],
                    $row['credit_total'],
                ));
            }
        }

        if ($report['clean']) {
            $this->info('The ledger and every wallet balance agree.');

            return self::SUCCESS;
        }

        $this->error('Discrepancies found. Nothing was corrected. Follow docs/ops/RUNBOOK_ledger_mismatch.md.');

        return self::FAILURE;
    }
}
