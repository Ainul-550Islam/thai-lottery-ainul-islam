<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\WebhookEventType;
use App\Enums\WithdrawalStatus;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Feature\Payment\PaymentTestCase;
use Tests\Support\RunsServerFinancialRaces;

/** Real job-handling process death at committed claim / synthetic acceptance boundaries. */
final class WithdrawalDispatchRecoveryTest extends PaymentTestCase
{
    use RunsServerFinancialRaces;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireServerRace();
        $this->assertTrue(function_exists('posix_kill') && function_exists('pcntl_signal'));
        $this->directory = storage_path('framework/testing/outbound-dispatch/'.bin2hex(random_bytes(10)));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    private function start(int $id, string $phase): array
    {
        $file = $this->directory.'/'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($file, json_encode(['withdrawal' => $id, 'phase' => $phase], JSON_THROW_ON_ERROR));
        chmod($file, 0600);
        $worker = new Process([PHP_BINARY, base_path('tests/Support/WithdrawalDispatchProbe.php'), $file], base_path(), [
            'WITHDRAWAL_DISPATCH_PROBE' => '1', 'APP_ENV' => 'testing', 'DB_CONNECTION' => config('database.default'),
            'DB_DATABASE' => 'thai_lottery_test', 'DB_SOCKET' => DB::connection()->getConfig('unix_socket'),
            'DB_USERNAME' => DB::connection()->getConfig('username'), 'DB_PASSWORD' => DB::connection()->getConfig('password'),
            'DB_URL' => '', 'APP_KEY' => config('app.key'), 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database', 'LOG_CHANNEL' => 'null',
        ]);
        $worker->setTimeout(20);
        $worker->start();

        return [$worker, $file];
    }

    private function recover(string $phase): void
    {
        $player = $this->createPlayer('1000.00', Currency::THB);
        $row = app(WithdrawalService::class)->request($player['wallet'], Money::of('400.00', Currency::THB), PaymentMethod::BankTransfer);
        app(WithdrawalApprovalService::class)->approve($row);
        [$worker, $file] = $this->start((int) $row->id, $phase);
        try {
            $deadline = microtime(true) + 8;
            while (! is_file($file.'.paused') && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($file.'.paused');
            $marker = json_decode(file_get_contents($file.'.paused'), true, 16, JSON_THROW_ON_ERROR);
            $this->assertSame($phase, $marker['phase']);
            $this->assertSame($worker->getPid(), $marker['pid']);
            $this->assertSame(0, $marker['transaction_level']);
            $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, $marker['connection']);
            $this->assertSame('sending', $row->fresh()->metadata['outbound_dispatch']['state']);
            $key = $row->fresh()->metadata['outbound_dispatch']['key'];
            $worker->signal(SIGKILL);
            $worker->wait();
            $this->assertSame(SIGKILL, $worker->getTermSignal());
            [$replacement] = $this->start((int) $row->id, 'none');
            $replacement->wait();
            $this->assertSame(0, $replacement->getExitCode());
            $this->assertSame('', $replacement->getErrorOutput());
            $answer = json_decode($replacement->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            $this->assertTrue($answer['worked']);
            $this->assertTrue($answer['unknown']);
            $this->assertTrue($answer['pending']);
            $this->assertFalse($answer['successful']);
            $this->assertSame($key, $row->fresh()->metadata['outbound_dispatch']['key']);
            $journal = $this->directory.'/accepted.jsonl';
            $calls = is_file($journal) ? array_map(fn ($line) => json_decode($line, true, 16, JSON_THROW_ON_ERROR), file($journal, FILE_IGNORE_NEW_LINES)) : [];
            $this->assertCount($phase === 'accepted' ? 1 : 0, $calls);
            if ($calls !== []) {
                $this->assertSame($key, $calls[0]['key']);
                $this->assertSame($marker['pid'], $calls[0]['pid']);
            }
            $this->assertSame(WithdrawalStatus::Processing, $row->fresh()->status);
            $this->assertSame('1000.00', (string) $player['wallet']->fresh()->balance);
            $this->assertSame('400.00', (string) $player['wallet']->fresh()->locked_balance);
            $this->assertSame(0, FinancialTransaction::query()->count());
            $this->assertSame(0, LedgerEntry::query()->count());
            $this->assertSame(1, Payment::query()->count());
            if ($phase === 'accepted') {
                $result = $this->webhookService()->processWebhookPayload(new WebhookPayload(
                    gateway: 'bank_transfer', eventId: 'synthetic-recovered-'.bin2hex(random_bytes(5)),
                    eventType: WebhookEventType::WithdrawalSuccess, providerReference: 'SYNTHETIC-DISPATCH-'.$row->id,
                    internalReference: $row->reference_number, amount: '400.00', currency: Currency::THB, isSuccess: true,
                ));
                $this->assertTrue($result->success);
                $this->assertSame('600.00', (string) $player['wallet']->fresh()->balance);
                $this->assertSame('0.00', (string) $player['wallet']->fresh()->locked_balance);
                $this->assertSame(1, FinancialTransaction::query()->count());
                $this->assertSame(2, LedgerEntry::query()->count());
            }
            $path = getenv('WITHDRAWAL_DISPATCH_WITNESS');
            if (is_string($path) && str_starts_with($path, sys_get_temp_dir().'/withdrawal-dispatch-')) {
                file_put_contents($path, json_encode(['class' => self::class, 'name' => $this->name(), 'phase' => $phase,
                    'pid' => $marker['pid'], 'connection' => $marker['connection'], 'transaction_level' => 0,
                    'signal' => SIGKILL, 'synthetic_acceptances' => count($calls), 'replayed_without_resend' => true,
                    'reserved_until_reconciled' => true], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
            }
        } finally {
            if ($worker->isRunning()) {
                $worker->signal(SIGKILL);
                $worker->wait();
            }
        }
    }

    public function test_death_after_claim_commit_does_not_become_permission_to_send(): void
    {
        $this->recover('claimed');
    }

    public function test_death_after_synthetic_acceptance_does_not_send_again_and_reconciles_once(): void
    {
        $this->recover('accepted');
    }
}
