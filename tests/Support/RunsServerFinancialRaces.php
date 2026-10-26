<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Withdrawal;
use App\Models\WithdrawalReconciliation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/** Two distinct processes and confirmed simultaneous InnoDB lock waits. */
trait RunsServerFinancialRaces
{
    protected function requireServerRace(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('This race requires the separately acknowledged local InnoDB test server; SQLite is not row-lock proof.');
        }
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('thai_lottery_test', DB::connection()->getDatabaseName());
        $this->assertSame('mysql', DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $socket = DB::connection()->getConfig('unix_socket');
        $this->assertTrue(is_string($socket) && str_starts_with($socket, '/') && filetype($socket) === 'socket');
        $this->assertFileDoesNotExist(base_path('bootstrap/cache/config.php'));
    }

    protected function serverRace(array $commands, string $table, int $id, array $config = [], ?\Closure $afterWalletLock = null, ?\Closure $afterChildren = null, ?\Closure $afterPolicyLock = null, ?\Closure $afterLifecycleLock = null): array
    {
        $this->assertCount(2, $commands);
        $this->assertContains($table, ['users', 'draws', 'wallets', 'withdrawal_provider_references', 'stripe_deposit_followup_inbox', 'stripe_followup_sweep_work', 'deposits', 'withdrawals', 'financial_transactions']);
        $walletIds = [];
        if ($table === 'withdrawal_provider_references') {
            foreach ($commands as $command) {
                $withdrawal = match ($command['action']) {
                    'webhook' => Withdrawal::query()->where('reference_number', $command['webhook']['internal'])->firstOrFail(),
                    'dispatch', 'dispatch_accepted' => Withdrawal::query()->findOrFail($command['withdrawal_id']),
                    'reconcile' => Withdrawal::query()->findOrFail(WithdrawalReconciliation::query()->findOrFail($command['case_id'])->withdrawal_id),
                    default => throw new \LogicException('Unknown cross-wallet reference probe.'),
                };
                $walletIds[] = (int) $withdrawal->wallet_id;
            }
            $this->assertCount(2, $walletIds);
            $this->assertNotSame($walletIds[0], $walletIds[1], 'This profile must not hide behind one wallet lock.');
        }
        if ($table === 'users' && count(array_filter($commands, fn ($c) => in_array($c['action'], ['refund_wager_context', 'refund_wager_snapshot', 'refund_withdrawal_request', 'refund_withdrawal_snapshot', 'refund_withdrawal_approve', 'refund_conversion', 'refund_conversion_snapshot'], true))) === 2) {
            $walletIds = array_map(fn ($c) => (int) $c['wallet_id'], $commands);
            $this->assertNotSame($walletIds[0], $walletIds[1], 'Refund admission must serialize distinct wallets at the player root.');
        }
        $directory = storage_path('framework/testing/financial-races/'.bin2hex(random_bytes(12)));
        mkdir($directory, 0700, true);
        $processes = [];
        $files = [];
        try {
            foreach ($commands as $index => $command) {
                $file = $directory.'/child-'.$index.'.json';
                file_put_contents($file, json_encode($command + ['config' => $config], JSON_THROW_ON_ERROR));
                chmod($file, 0600);
                $files[] = $file;
                $process = new Process([PHP_BINARY, base_path('tests/Support/FinancialRaceProbe.php'), $file], base_path(), [
                    'FINANCIAL_RACE_PROBE' => '1', 'APP_ENV' => 'testing',
                    'DB_CONNECTION' => config('database.default'), 'DB_DATABASE' => 'thai_lottery_test',
                    'DB_SOCKET' => (string) DB::connection()->getConfig('unix_socket'),
                    'DB_USERNAME' => (string) DB::connection()->getConfig('username'),
                    'DB_PASSWORD' => (string) DB::connection()->getConfig('password'), 'DB_URL' => '',
                    'APP_KEY' => config('app.key'), 'CACHE_STORE' => 'array',
                    'REAL_MONEY_ENABLED' => config('real_money.enabled', false) ? 'true' : 'false', 'QUEUE_CONNECTION' => 'database',
                ]);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while ((! is_file($files[0].'.ready') || ! is_file($files[1].'.ready')) && microtime(true) < $deadline) {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        $this->fail('A child refused bootstrap: '.$process->getErrorOutput());
                    }
                }
                usleep(10000);
            }
            $ids = [];
            $acceptedMarkers = [];
            $snapshotOwners = [];
            $snapshotReversals = [];
            $conversionProofSnapshots = [];
            $conversionAuthoritySnapshots = [];
            $conversionLimitSnapshots = [];
            $conversionQuoteSlotSnapshots = [];
            $conversionMemberSnapshots = [];
            $conversionWalletSnapshots = [];
            $walletBoundarySnapshots = [];
            $reversalBoundarySnapshots = [];
            $lifecycleBoundarySnapshots = [];
            foreach ($files as $file) {
                $this->assertFileExists($file.'.ready');
                $marker = json_decode(file_get_contents($file.'.ready'), true, 16, JSON_THROW_ON_ERROR);
                $ids[] = $marker['connection_id'];
                $acceptedMarkers[] = $marker['accepted_before_barrier'] ?? false;
                $snapshotOwners[] = $marker['snapshot_owner_count'] ?? null;
                $snapshotReversals[] = $marker['snapshot_reversal_count'] ?? null;
                $conversionProofSnapshots[] = $marker['conversion_proof_snapshot'] ?? null;
                $conversionAuthoritySnapshots[] = $marker['conversion_authority_snapshot'] ?? null;
                $conversionLimitSnapshots[] = $marker['conversion_limit_snapshot'] ?? null;
                $conversionQuoteSlotSnapshots[] = $marker['conversion_quote_slot_snapshot'] ?? null;
                $conversionMemberSnapshots[] = $marker['conversion_member_snapshot'] ?? null;
                $conversionWalletSnapshots[] = $marker['conversion_wallet_snapshot'] ?? null;
                $walletBoundarySnapshots[] = $marker['wallet_boundary_snapshot'] ?? null;
                $reversalBoundarySnapshots[] = $marker['reversal_boundary_snapshot'] ?? null;
                $lifecycleBoundarySnapshots[] = $marker['lifecycle_boundary_snapshot'] ?? null;
            }
            $this->assertNotSame($ids[0], $ids[1]);
            $blockerId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            DB::beginTransaction();
            if ($table === 'withdrawal_provider_references') {
                // Own the actual unique identity index too, not just its clustered
                // PK row: duplicate-key insert checks must both wait on THIS parent.
                $identity = DB::table($table)->where('id', $id)->value('identity_key');
                $this->assertNotNull(DB::table($table)->where('identity_key', $identity)->lockForUpdate()->first());
            } else {
                $this->assertNotNull(DB::table($table)->where('id', $id)->lockForUpdate()->first());
            }
            if ($afterLifecycleLock !== null) {
                $this->assertContains($table, ['deposits', 'withdrawals', 'financial_transactions']);
                $this->assertSame(array_fill(0, 2, ['table' => $table, 'status' => 'pending']), $lifecycleBoundarySnapshots);
                $afterLifecycleLock();
            }
            if ($afterWalletLock !== null) {
                $this->assertSame('wallets', $table);
                if (count(array_filter($commands, fn ($c) => $c['action'] === 'reversal_transaction_boundary')) === 2) {
                    $this->assertSame(array_fill(0, 2, $commands[0]['expected_reversal_snapshot']), $reversalBoundarySnapshots, 'Both real children must read the declared original/replay snapshot before the parent changes its wallet.');
                } elseif (count(array_filter($commands, fn ($c) => $c['action'] === 'wallet_transaction_boundary')) === 2) {
                    $this->assertSame(['1000.00', '1000.00'], $walletBoundarySnapshots, 'Both real children must warm the old wallet before the parent changes it.');
                } elseif ($snapshotOwners !== [0, 0]) {
                    $this->assertSame([0, 0], $snapshotReversals, 'Both children established old empty-compensation snapshots before reversal.');
                } else {
                    $this->assertSame([0, 0], $snapshotOwners, 'Both children established old empty-owner snapshots before approval.');
                }
                $afterWalletLock();
            }
            if ($afterPolicyLock !== null) {
                $this->assertSame('users', $table);
                if (count(array_filter($commands, fn ($c) => $c['action'] === 'conversion_wallet_snapshot')) === 2) {
                    $this->assertSame(array_fill(0, 2, $commands[0]['expected_wallet_snapshot']), $conversionWalletSnapshots, 'Both real children must warm the declared old wallet facts before the parent mutation.');
                } elseif (count(array_filter($commands, fn ($c) => $c['action'] === 'conversion_member_snapshot')) === 2) {
                    $this->assertSame(array_fill(0, 2, $commands[0]['expected_member_snapshot']), $conversionMemberSnapshots, 'Both real children must warm the declared old member and verification snapshot before the parent mutation.');
                } elseif (count(array_filter($commands, fn ($c) => $c['action'] === 'conversion_quote_slot_snapshot')) === 2) {
                    $this->assertSame(array_fill(0, 2, $commands[0]['expected_slot_snapshot']), $conversionQuoteSlotSnapshots, 'Both real children must read the declared old repeatable-read quote count.');
                } elseif (count(array_filter($commands, fn ($c) => $c['action'] === 'conversion_limit_snapshot')) === 2) {
                    $this->assertSame([['daily' => '0.00', 'monthly' => '0.00'], ['daily' => '0.00', 'monthly' => '0.00']], $conversionLimitSnapshots, 'Both real children established old empty rolling-allowance snapshots.');
                } elseif (count(array_filter($commands, fn ($c) => $c['action'] === 'conversion_authority_snapshot')) === 2) {
                    $this->assertSame(array_fill(0, 2, $commands[0]['expected_authority_snapshot']), $conversionAuthoritySnapshots, 'Both real child sessions must warm the declared old policy/cache and repeatable-read principal snapshot.');
                } elseif (count(array_filter($commands, fn ($c) => str_starts_with($c['action'], 'conversion_proof_'))) === 2) {
                    $this->assertSame(array_fill(0, 2, $commands[0]['expected_snapshot']), $conversionProofSnapshots, 'Both independent child sessions must actually read the declared old conversion/source snapshot.');
                } else {
                    $this->assertSame([0, 0], $snapshotOwners, 'Both refund probes established an empty old repeatable-read source snapshot.');
                }
                $afterPolicyLock();
            }
            file_put_contents($directory.'/go', 'go');
            $deadline = microtime(true) + 10;
            $waiting = 0;
            while ($waiting !== 2 && microtime(true) < $deadline) {
                $waiting = (int) DB::selectOne(
                    'SELECT COUNT(DISTINCT t.trx_mysql_thread_id) AS n FROM information_schema.INNODB_LOCK_WAITS w '
                    .'JOIN information_schema.INNODB_TRX t ON t.trx_id = w.requesting_trx_id '
                    .'JOIN information_schema.INNODB_TRX b ON b.trx_id = w.blocking_trx_id '
                    .'WHERE t.trx_mysql_thread_id IN (?, ?) AND b.trx_mysql_thread_id = ?', [...$ids, $blockerId],
                )->n;
                if ($waiting !== 2) {
                    // InnoDB information_schema snapshots are cached: allow
                    // more than 100ms between reads so live waits become visible.
                    usleep(200000);
                }
            }
            // Ordinary probes roll back only their blocking read. The explicit
            // snapshot probe commits its newly approved owner before releasing waiters.
            if ($afterWalletLock === null && $afterPolicyLock === null && $afterLifecycleLock === null) {
                DB::rollBack();
            } else {
                DB::commit();
            }
            $this->assertSame(2, $waiting, 'Both child connections must be waiting on the decisive parent InnoDB lock.');
            $witness = getenv('FINANCIAL_RACE_WITNESS');
            if (is_string($witness) && str_starts_with($witness, sys_get_temp_dir().'/financial-concurrency-')) {
                file_put_contents($witness, json_encode([
                    'class' => static::class, 'name' => $this->name(), 'table' => $table,
                    'waiting_children' => $waiting, 'child_connections' => $ids,
                    'parent_blocker' => $blockerId, 'wallet_ids' => $walletIds, 'blocker_row_id' => $id,
                    'lock_index' => $table === 'withdrawal_provider_references' ? 'identity_key' : 'PRIMARY',
                    'accepted_before_barrier' => $acceptedMarkers, 'snapshot_owner_counts' => $snapshotOwners, 'snapshot_reversal_counts' => $snapshotReversals, 'conversion_proof_snapshots' => $conversionProofSnapshots, 'conversion_authority_snapshots' => $conversionAuthoritySnapshots, 'conversion_limit_snapshots' => $conversionLimitSnapshots, 'conversion_quote_slot_snapshots' => $conversionQuoteSlotSnapshots, 'conversion_member_snapshots' => $conversionMemberSnapshots, 'conversion_wallet_snapshots' => $conversionWalletSnapshots, 'wallet_boundary_snapshots' => $walletBoundarySnapshots, 'reversal_boundary_snapshots' => $reversalBoundarySnapshots, 'lifecycle_boundary_snapshots' => $lifecycleBoundarySnapshots,
                ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $this->assertSame('', $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
            }

            if ($afterChildren !== null) {
                $afterChildren($directory, $results);
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
