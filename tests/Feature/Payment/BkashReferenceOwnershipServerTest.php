<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Services\Finance\Money;
use App\Services\Payment\DepositCheckoutService;
use App\Services\Payment\DepositProviderAccountService;
use App\Services\Payment\Drivers\BkashGateway;
use App\Services\Payment\FinancialProviderReferenceAliasService;
use App\Services\Payment\FinancialProviderReferenceService;
use App\Services\Payment\OutboundProviderReferenceService;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as OutboundRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\Support\InnoDbLockWitness;
use Tests\Support\RunsServerFinancialRaces;

/** Real InnoDB ownership races with synthetic checkout facts, not provider authentication. */
final class BkashReferenceOwnershipServerTest extends PaymentTestCase
{
    use RunsServerFinancialRaces;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireServerRace();
        config([
            'payment.currency.default' => 'BDT',
            'payment.country' => 'BD',
            'payment.markets.bkash' => ['currencies' => ['BDT'], 'countries' => ['BD']],
            'payment.gateways.bkash.enabled' => true,
            'payment.gateways.bkash.supports_deposit' => true,
            'payment.gateways.bkash.supported_currencies' => ['BDT'],
            'payment.gateways.bkash.username' => 'synthetic-user',
            'payment.gateways.bkash.password' => 'synthetic-password',
            'payment.gateways.bkash.base_url' => 'https://bkash.example.test',
        ]);
    }

    private function checkout(string $suffix): array
    {
        $f = $this->createPlayer('0.00', Currency::BDT);
        $paymentId = 'SYNTHETIC-PAYMENT-'.$suffix;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (OutboundRequest $request) use ($paymentId) {
            $this->assertSame(0, DB::transactionLevel());
            if (str_ends_with($request->url(), '/tokenized/checkout/token/grant')) {
                return Http::response(['statusCode' => '0000', 'id_token' => 'synthetic-token']);
            }
            if (str_ends_with($request->url(), '/tokenized/checkout/create')) {
                return Http::response(['statusCode' => '0000', 'paymentID' => $paymentId,
                    'bkashURL' => 'https://checkout.example.test/synthetic']);
            }
            $this->fail('Unexpected synthetic provider request.');
        });
        $out = app(DepositCheckoutService::class)->execute($f['wallet'], Money::of('100.00', Currency::BDT),
            PaymentMethod::Bkash, 'synthetic-bkash-server-'.$suffix, [], app(BkashGateway::class));
        $f['deposit'] = $out['deposit'];
        $f['payment'] = $out['payment'];
        $f['attempt'] = DB::table(DepositCheckoutService::TABLE)->where('deposit_id', $f['deposit']->id)->first();
        $this->assertNotNull($f['attempt']);
        $this->assertSame('projected', $f['attempt']->state);
        $this->assertSame($paymentId, $f['payment']->gateway_reference);
        Http::swap(new Factory);
        Http::preventStrayRequests();

        return $f;
    }

    public function test_alias_first_refuses_a_foreign_primary_and_preserves_the_original_owner(): void
    {
        $alias = $this->checkout('ALIAS');
        $primary = $this->checkout('PRIMARY');
        $ref = 'SYNTHETIC-ALIAS-THEN-PRIMARY';
        $scope = app(DepositProviderAccountService::class)->assertActive($primary['deposit']);
        DB::transaction(fn () => app(FinancialProviderReferenceAliasService::class)->bind(
            $alias['deposit'], $alias['payment'], $alias['attempt'], $ref,
        ));
        try {
            DB::transaction(fn () => app(FinancialProviderReferenceService::class)->bind(
                $primary['deposit'], 'bkash', $scope, $ref, $primary['payment'],
            ));
            $this->fail('A foreign primary cannot reuse an owned alias.');
        } catch (FinancialException $exception) {
            $this->assertSame('outbound_reference_ownership_conflict', $exception->errorCode());
        }
        $this->assertDatabaseCount(FinancialProviderReferenceAliasService::TABLE, 1);
        $this->assertDatabaseCount(FinancialProviderReferenceService::TABLE, 0);
        $this->assertSame((int) $alias['deposit']->id, (int) DB::table(FinancialProviderReferenceAliasService::TABLE)->sole()->deposit_id);
        $this->assertSame('0.00', (string) $alias['wallet']->fresh()->balance);
        $this->assertSame('0.00', (string) $primary['wallet']->fresh()->balance);
    }

    public function test_nested_rollback_does_not_leave_an_alias_or_identity_owner(): void
    {
        $f = $this->checkout('ROLLBACK');
        $ref = 'SYNTHETIC-NESTED-ROLLBACK';
        $scope = app(DepositProviderAccountService::class)->assertActive($f['deposit']);
        $key = app(OutboundProviderReferenceService::class)->identity('bkash', $scope, $ref);
        DB::transaction(function () use ($f, $ref): void {
            try {
                DB::transaction(function () use ($f, $ref): void {
                    app(FinancialProviderReferenceAliasService::class)->bind($f['deposit'], $f['payment'], $f['attempt'], $ref);
                    throw new \RuntimeException('synthetic savepoint rollback');
                });
            } catch (\RuntimeException $exception) {
                $this->assertSame('synthetic savepoint rollback', $exception->getMessage());
            }
            $this->assertDatabaseCount(FinancialProviderReferenceAliasService::TABLE, 0);
        });
        $this->assertDatabaseMissing(FinancialProviderReferenceAliasService::LOCKS, ['identity_key' => $key]);
        $this->assertDatabaseCount(FinancialProviderReferenceService::TABLE, 0);
        $this->assertSame(0, FinancialTransaction::query()->count());
    }

    public function test_two_real_connections_contend_on_shared_alias_primary_identity(): void
    {
        $alias = $this->checkout('RACE-A');
        $primary = $this->checkout('RACE-B');
        $this->assertNotSame((int) $alias['wallet']->id, (int) $primary['wallet']->id);
        $ref = 'SYNTHETIC-ALIAS-PRIMARY-RACE';
        $scope = app(DepositProviderAccountService::class)->assertActive($alias['deposit']);
        $this->assertSame($scope, app(DepositProviderAccountService::class)->assertActive($primary['deposit']));
        $key = app(OutboundProviderReferenceService::class)->identity('bkash', $scope, $ref);
        DB::transaction(fn () => app(FinancialProviderReferenceAliasService::class)->lockIdentity($key));

        $directory = storage_path('framework/testing/bkash-reference-races/'.bin2hex(random_bytes(12)));
        mkdir($directory, 0700, true);
        $processes = [];
        $db = DB::connection();
        $code = <<<'PHP'
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = $app->make('db')->connection();
    if (! app()->environment('testing') || $db->getDatabaseName() !== 'thai_lottery_test'
        || $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('Unsafe synthetic ownership race.');
    }
    $file = $argv[1];
    $cmd = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    file_put_contents($file.'.ready', (string) $db->selectOne('SELECT CONNECTION_ID() AS id')->id);
    $deadline = microtime(true) + 20;
    while (! is_file(dirname($file).'/go') && microtime(true) < $deadline) { usleep(10000); }
    if (! is_file(dirname($file).'/go')) { throw new RuntimeException('Synthetic race barrier timeout.'); }
    try {
        $db->transaction(function () use ($cmd) {
            $candidate = \App\Models\Deposit::query()->findOrFail($cmd['deposit_id']);
            \App\Models\Wallet::query()->whereKey($candidate->wallet_id)->lockForUpdate()->firstOrFail();
            $deposit = \App\Models\Deposit::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $payment = \App\Models\Payment::query()->whereKey($cmd['payment_id'])->lockForUpdate()->firstOrFail();
            $attempt = \Illuminate\Support\Facades\DB::table('deposit_checkout_attempts')
                ->where('id', $cmd['attempt_id'])->lockForUpdate()->first();
            if ($cmd['kind'] === 'alias') {
                app(\App\Services\Payment\FinancialProviderReferenceAliasService::class)->bind($deposit, $payment, $attempt, $cmd['reference']);
            } else {
                app(\App\Services\Payment\FinancialProviderReferenceService::class)->bind($deposit, 'bkash', $cmd['scope'], $cmd['reference'], $payment);
            }
        });
        echo json_encode(['outcome' => 'bound'], JSON_THROW_ON_ERROR);
    } catch (\App\Exceptions\FinancialException $exception) {
        echo json_encode(['outcome' => 'refused', 'code' => $exception->errorCode()], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(1);
}
PHP;
        try {
            foreach ([['alias', $alias], ['primary', $primary]] as $i => [$kind, $f]) {
                $file = $directory.'/child-'.$i.'.json';
                file_put_contents($file, json_encode(['kind' => $kind, 'deposit_id' => $f['deposit']->id,
                    'payment_id' => $f['payment']->id, 'attempt_id' => $f['attempt']->id,
                    'reference' => $ref, 'scope' => $scope], JSON_THROW_ON_ERROR));
                $process = new Process([PHP_BINARY, '-r', $code, $file], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => $db->getName(), 'DB_DATABASE' => 'thai_lottery_test',
                    'DB_SOCKET' => (string) $db->getConfig('unix_socket'), 'DB_HOST' => 'localhost',
                    'DB_USERNAME' => (string) $db->getConfig('username'), 'DB_PASSWORD' => (string) $db->getConfig('password'),
                    'DB_URL' => '', 'APP_KEY' => config('app.key'), 'CACHE_STORE' => 'array',
                ]);
                $process->setTimeout(35);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($directory.'/child-0.json.ready') || ! is_file($directory.'/child-1.json.ready')) && microtime(true) < $deadline) {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        $this->fail('Race child failed to start: '.$process->getErrorOutput());
                    }
                }
                usleep(10000);
            }
            $ids = [];
            foreach ([0, 1] as $i) {
                $marker = $directory.'/child-'.$i.'.json.ready';
                $this->assertFileExists($marker);
                $ids[] = (int) file_get_contents($marker);
            }
            $this->assertNotSame($ids[0], $ids[1]);
            $parent = (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
            $db->beginTransaction();
            $this->assertNotNull($db->table(FinancialProviderReferenceAliasService::LOCKS)
                ->where('identity_key', $key)->lockForUpdate()->first());
            file_put_contents($directory.'/go', 'go');
            $deadline = microtime(true) + 12;
            $waiting = 0;
            while ($waiting !== 2 && microtime(true) < $deadline) {
                $waiting = InnoDbLockWitness::waiting($db, $ids, $parent, FinancialProviderReferenceAliasService::LOCKS, 'PRIMARY');
                if ($waiting !== 2) {
                    usleep(200000);
                }
            }
            $db->rollBack();
            $this->assertSame(2, $waiting, 'Both independent writers must directly wait on the same real identity lock.');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $this->assertSame('', $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $outcomes = array_column($results, 'outcome');
            sort($outcomes);
            $this->assertSame(['bound', 'refused'], $outcomes, json_encode($results));
            $this->assertSame('outbound_reference_ownership_conflict', $results[array_search('refused', array_column($results, 'outcome'), true)]['code']);
            $a = $db->table(FinancialProviderReferenceAliasService::TABLE)->where('identity_key', $key)->first();
            $p = $db->table(FinancialProviderReferenceService::TABLE)->where('identity_key', $key)->first();
            $this->assertSame(1, (int) ($a !== null) + (int) ($p !== null));
            $this->assertSame($a ? (int) $alias['deposit']->id : (int) $primary['deposit']->id, (int) ($a ?? $p)->deposit_id);
            $this->assertSame('0.00', (string) $alias['wallet']->fresh()->balance);
            $this->assertSame('0.00', (string) $primary['wallet']->fresh()->balance);
            $this->assertSame(0, FinancialTransaction::query()->count());
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
