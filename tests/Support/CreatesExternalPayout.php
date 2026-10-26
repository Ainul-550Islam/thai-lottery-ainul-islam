<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DTOs\Finance\PayoutApprovalData;
use App\DTOs\Finance\PayoutBatchData;
use App\DTOs\Finance\PayoutRequestData;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\PayoutApprovalStatus;
use App\Enums\PrizePayoutMethod;
use App\Models\Draw;
use App\Models\Payout;
use App\Models\User;
use App\Services\Finance\Money;
use App\Services\Finance\PayoutApprovalIntakeService;
use App\Services\Finance\PayoutApprovalService;
use App\Services\Finance\PayoutBatchService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\PayoutEvidenceService;
use App\Services\Payment\PayoutTransferGuard;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

trait CreatesExternalPayout
{
    protected function externalFixture(bool $approve = true, PrizePayoutMethod $method = PrizePayoutMethod::BankTransfer): array
    {
        config(['finance.prize_payout.safety_mode' => 'LIVE']);
        $this->seed(RolePermissionSeeder::class);
        $owner = $this->createPlayer('1000.00', Currency::THB);
        $this->verifyUserKycForTest($owner['user']);
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $reviewer = User::factory()->create();
        foreach ([$maker, $checker, $reviewer] as $staff) {
            $staff->assignRole('admin');
        }
        $row = Payout::query()->create(['reference_number' => 'SYNTHETIC-PRIZE-'.bin2hex(random_bytes(8)),
            'draw_id' => Draw::factory()->create()->id, 'user_id' => $owner['user']->id,
            'wallet_id' => $owner['wallet']->id, 'amount' => '400.00', 'currency' => Currency::THB, 'metadata' => []]);
        $target = $method === PrizePayoutMethod::BankTransfer
            ? ['bank_name' => 'SYNTHETIC BANK', 'account_name' => 'SYNTHETIC PERSON', 'account_number' => 'NOT-PAYABLE']
            : ['payee_name' => 'SYNTHETIC PERSON', 'address' => 'NOT-A-REAL-ADDRESS'];
        $request = new PayoutRequestData($row->reference_number, '400.00', Currency::THB, $method, (int) $maker->id,
            (int) $owner['user']->id, $target, 'SYNTHETIC approved original destination.',
            PayoutRequestData::deriveRequestKey($row->reference_number, '400.00', Currency::THB, (int) $owner['user']->id));
        app(PayoutApprovalService::class)->request($row, $request);
        if ($approve) {
            app(PayoutApprovalService::class)->decide($row, new PayoutApprovalData((int) $checker->id, PayoutApprovalStatus::Approved, 'Independently reviewed synthetic request.'));
        }
        $row->refresh();
        if ($approve) {
            app(PayoutBatchService::class)->create(new PayoutBatchData([$row->reference_number], (string) $row->amount, $row->currency, null));
            $row->refresh();
        } else {
            // Pending-approval negative tests intentionally cannot manufacture a real membership.
            $metadata = $row->metadata;
            $metadata['batch'] = ['batch_key' => 'SYNTHETIC-UNAPPROVED-'.$row->id];
            $row->metadata = $metadata;
            $row->save();
        }
        $evidence_id = $approve ? $this->archiveSyntheticPayoutEvidence($row, $maker, $reviewer) : null;
        $data = app(PayoutTransferGuard::class)->fromRow($row);

        return compact('row', 'maker', 'checker', 'reviewer', 'owner', 'request', 'data', 'evidence_id');
    }

    /** A fresh obligation for HTTP maker intake; no approval or batch is fabricated. */
    protected function unrequestedPayout(): array
    {
        $f = $this->externalFixture(false);
        $f['row'] = Payout::query()->create(['reference_number' => 'SYNTHETIC-INTAKE-'.bin2hex(random_bytes(8)),
            'draw_id' => $f['row']->draw_id, 'user_id' => $f['owner']['user']->id, 'wallet_id' => $f['owner']['wallet']->id,
            'amount' => '400.00', 'currency' => Currency::THB, 'metadata' => []]);

        return $f;
    }

    protected function approvedIntake(string $method = 'wallet'): array
    {
        $f = $this->unrequestedPayout();
        $target = $method === 'wallet' ? null : ($method === 'cheque' ? ['payee_name' => 'SYNTHETIC NAME', 'address' => 'SYNTHETIC ADDRESS'] : ['bank_name' => 'SYNTHETIC BANK', 'account_name' => 'SYNTHETIC NAME', 'account_number' => 'NOT-PAYABLE']);
        app(PayoutApprovalIntakeService::class)->request($f['maker'], $f['row']->id, $method, $target, 'Synthetic original destination request.');
        app(PayoutApprovalIntakeService::class)->decide($f['checker'], $f['row']->id, 'approved', true, 'Independent synthetic original-facts review.');
        $f['row']->refresh();
        if ($method !== 'wallet') {
            $f['evidence_id'] = $this->archiveSyntheticPayoutEvidence($f['row'], $f['maker'], $f['reviewer']);
        }

        return $f;
    }

    protected function archiveSyntheticPayoutEvidence(Payout $row, User $uploader, User $reader): int
    {
        $service = app(PayoutEvidenceService::class);
        $proof = $service->upload($uploader, (int) $row->id,
            UploadedFile::fake()->createWithContent('synthetic.txt', 'SYNTHETIC FINAL-RESULT ARCHIVE; NOT PROVIDER-AUTHENTICATED OR A REAL TRANSFER.'),
            'Synthetic private archival fixture, not provider proof.');
        $service->read($reader, (int) $row->id, $proof['id']);

        return $proof['id'];
    }

    protected function approvedReferenceWithdrawal(): array
    {
        $owner = $this->createPlayer('1000.00', Currency::THB);
        $row = app(WithdrawalService::class)->request($owner['wallet'],
            Money::of('400.00', Currency::THB), PaymentMethod::BankTransfer,
            options: ['payout_details' => ['account_name' => 'SYNTHETIC ONLY', 'account_number' => 'NOT-PAYABLE']]);
        app(WithdrawalApprovalService::class)->approve($row);

        return ['row' => $row->fresh(), 'wallet' => $owner['wallet']];
    }

    protected function requirePayoutServerRace(): void
    {
        if (getenv('FINANCIAL_PAYOUT_RACE') !== '1' || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Explicit disposable MariaDB/MySQL payout contention profile only.');
        }
        $this->assertSame('thai_lottery_test', DB::connection()->getDatabaseName());
    }

    /** Two real child connections directly blocked by the parent's decisive row/index, never a child chain. */
    protected function payoutServerRace(array $commands, string $table, int $id): array
    {
        $this->assertCount(2, $commands);
        $this->assertContains($table, ['payouts', 'payout_transfer_references', 'wallets', 'outbound_provider_references', 'financial_provider_reference_owners']);
        $db = DB::connection();
        $directory = storage_path('framework/testing/payout-races/'.bin2hex(random_bytes(12)));
        mkdir($directory, 0700, true);
        $processes = [];
        $code = <<<'PHP'
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = $app->make('db')->connection();
    if (getenv('FINANCIAL_PAYOUT_RACE') !== '1' || app()->environment() !== 'testing'
        || $db->getDatabaseName() !== 'thai_lottery_test' || $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('Unsafe payout race connection.');
    }
    config(['finance.prize_payout.safety_mode' => 'LIVE', 'cache.default' => 'array']);
    $input = $argv[1];
    $command = json_decode(file_get_contents($input), true, flags: JSON_THROW_ON_ERROR);
    $id = (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
    file_put_contents($input.'.ready', (string) $id);
    $deadline = microtime(true) + 20;
    while (! is_file(dirname($input).'/go') && microtime(true) < $deadline) { usleep(10000); }
    if (! is_file(dirname($input).'/go')) { throw new RuntimeException('Payout barrier timed out.'); }
    if (isset($command['now'])) { \Illuminate\Support\Carbon::setTestNow($command['now']); }
    $row = \App\Models\Payout::query()->findOrFail($command['payout_id']);
    $actor = \App\Models\User::query()->findOrFail($command['actor_id']);
    if (($command['action'] ?? null) === 'claim_expire') {
        $out = app(\App\Services\Prize\UnclaimedPrizeService::class)->expireLapsedClaims();
        echo json_encode(['outcome' => $out['expired_count'] === 1 ? 'expired' : 'held'], JSON_THROW_ON_ERROR);
    } elseif (($command['action'] ?? null) === 'claim_intake') {
        app(\App\Services\Finance\PayoutApprovalIntakeService::class)->request($actor, (int) $row->id, 'wallet', null, 'Synthetic race original wallet intake request.');
        echo json_encode(['outcome' => 'requested'], JSON_THROW_ON_ERROR);
    } elseif (($command['action'] ?? null) === 'claim_review') {
        app(\App\Services\Prize\PrizeClaimService::class)->approve((int) $row->id, (int) $actor->id, 'Synthetic independent winning-facts race review.', true);
        echo json_encode(['outcome' => 'approved'], JSON_THROW_ON_ERROR);
    } elseif (($command['action'] ?? null) === 'claim_wallet') {
        $out = app(\App\Services\Finance\ApprovedWalletPayoutService::class)->execute((int) $row->id);
        echo json_encode(['outcome' => $out['result']], JSON_THROW_ON_ERROR);
    } elseif (($command['action'] ?? null) === 'deposit') {
        $deposit = \App\Models\Deposit::query()->findOrFail($command['deposit_id']);
        $out = app(\App\Services\Finance\DepositCompletionService::class)->complete($deposit, options: ['provider_reference' => $command['reference']]);
        echo json_encode(['outcome' => $out['credited'] ? 'credited' : 'replayed'], JSON_THROW_ON_ERROR);
    } elseif (($command['action'] ?? null) === 'withdrawal') {
        $withdrawal = \App\Models\Withdrawal::query()->findOrFail($command['withdrawal_id']);
        app(\App\Services\Finance\WithdrawalCompletionService::class)->complete($withdrawal, options: ['provider' => 'bank_transfer', 'provider_reference' => $command['reference']]);
        echo json_encode(['outcome' => 'completed'], JSON_THROW_ON_ERROR);
    } elseif (($command['action'] ?? null) === 'wallet') {
        $out = app(\App\Services\Finance\ApprovedWalletPayoutService::class)->execute((int) $row->id);
        echo json_encode(['outcome' => $out['result']], JSON_THROW_ON_ERROR);
    } elseif (($command['action'] ?? null) === 'route') {
        $batch = \App\Models\PayoutBatch::query()->findOrFail($command['batch_id']);
        $out = app(\App\Services\Finance\PayoutBatchRoutingService::class)->dispatchApproved($batch, $actor);
        echo json_encode(['outcome' => $out['enqueued'] ? 'queued' : 'replayed'], JSON_THROW_ON_ERROR);
    } elseif (($command['action'] ?? null) === 'refusal') {
        $data = app(\App\Services\Payment\PayoutTransferGuard::class)->fromRow($row);
        $result = app(\App\Services\Payment\PayoutTransferService::class)->fail($data, 'Independent synthetic final refusal evidence.', $actor, true);
        echo json_encode(['outcome' => $result['lane_status']], JSON_THROW_ON_ERROR);
    } else {
        $data = app(\App\Services\Payment\PayoutTransferGuard::class)->fromRow($row);
        $result = app(\App\Services\Payment\PayoutTransferService::class)->complete($data, $command['reference'], $actor, true, 'Independent synthetic SQL race provider evidence.');
        echo json_encode(['outcome' => $result['lane_status']], JSON_THROW_ON_ERROR);
    }
} catch (\App\Exceptions\PrizeClaimException|\App\Exceptions\PayoutTransferException|\App\Exceptions\PayoutApprovalException|\App\Exceptions\PayoutBatchException|\App\Exceptions\FinancialException|\Illuminate\Auth\Access\AuthorizationException $e) {
    echo json_encode(['outcome' => 'refused'], JSON_THROW_ON_ERROR);
} catch (Throwable $e) { fwrite(STDERR, 'Payout child bootstrap/barrier refused.'); exit(1); }
PHP;
        try {
            foreach ($commands as $i => $command) {
                $input = $directory.'/child-'.$i.'.json';
                file_put_contents($input, json_encode($command, JSON_THROW_ON_ERROR));
                $process = new Process([PHP_BINARY, '-r', $code, $input], base_path(), [
                    'APP_ENV' => 'testing', 'FINANCIAL_PAYOUT_RACE' => '1', 'DB_CONNECTION' => $db->getName(),
                    'DB_DATABASE' => 'thai_lottery_test', 'DB_SOCKET' => $db->getConfig('unix_socket'),
                    'DB_HOST' => 'localhost', 'DB_USERNAME' => $db->getConfig('username'), 'DB_PASSWORD' => $db->getConfig('password'),
                    'DB_URL' => '', 'APP_KEY' => config('app.key'), 'CACHE_STORE' => 'array',
                    'REAL_MONEY_ENABLED' => config('real_money.enabled', false) ? 'true' : 'false',
                ]);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while ((! is_file($directory.'/child-0.json.ready') || ! is_file($directory.'/child-1.json.ready')) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $connections = [];
            foreach ([0, 1] as $i) {
                $marker = $directory.'/child-'.$i.'.json.ready';
                $this->assertFileExists($marker);
                $connections[] = (int) file_get_contents($marker);
            }
            $this->assertGreaterThan(0, min($connections));
            $this->assertNotSame(...$connections);
            $parent = (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
            $db->beginTransaction();
            $primary = $table === 'wallets' || ($commands[0]['action'] ?? null) === 'route'
                || str_starts_with($commands[0]['action'] ?? '', 'claim_');
            if ($primary) {
                $this->assertNotNull($db->table($table)->where('id', $id)->lockForUpdate()->first());
            } elseif ($table === 'payouts') {
                $reference = $db->table($table)->where('id', $id)->value('reference_number');
                $this->assertNotNull($db->table($table)->where('reference_number', $reference)->lockForUpdate()->first());
            } else {
                $key = $db->table($table)->where('id', $id)->value('identity_key');
                $this->assertNotNull($db->table($table)->where('identity_key', $key)->lockForUpdate()->first());
            }
            file_put_contents($directory.'/go', 'go');
            $waiting = 0;
            $deadline = microtime(true) + 10;
            $index = $primary ? 'PRIMARY' : ($table === 'payouts' ? 'reference_number' : 'identity_key');
            while ($waiting !== 2 && microtime(true) < $deadline) {
                $waiting = (int) $db->selectOne('SELECT COUNT(DISTINCT t.trx_mysql_thread_id) AS n FROM information_schema.INNODB_LOCK_WAITS w '
                    .'JOIN information_schema.INNODB_TRX t ON t.trx_id=w.requesting_trx_id '
                    .'JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id '
                    .'JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id '
                    .'WHERE t.trx_mysql_thread_id IN (?, ?) AND b.trx_mysql_thread_id=? AND l.lock_table LIKE ? AND l.lock_index LIKE ?',
                    [...$connections, $parent, '%'.$table.'%', '%'.$index.'%'])->n;
                if ($waiting !== 2) {
                    usleep(200000);
                }
            }
            $db->rollBack();
            $this->assertSame(2, $waiting, 'Both children must directly wait on the actual parent-owned decisive index.');
            $outcomes = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $this->assertSame('', $process->getErrorOutput());
                $outcomes[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['outcome'];
            }
            $witness = getenv('FINANCIAL_PAYOUT_WITNESS');
            if (is_string($witness) && str_starts_with($witness, sys_get_temp_dir().'/payout-races-')) {
                file_put_contents($witness, json_encode(['case' => $this->name(), 'table' => $table, 'lock_index' => $index,
                    'blocker_row_id' => $id, 'parent_blocker' => $parent, 'child_connections' => $connections,
                    'waiting_children' => $waiting, 'payout_ids' => array_column($commands, 'payout_id'), 'outcomes' => $outcomes], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
            }

            return $outcomes;
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
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
