<?php

namespace Tests;

use App\DTOs\DrawResultData;
use App\DTOs\Payment\PaymentWebhookData;
use App\Enums\BetType;
use App\Enums\DrawStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use App\Enums\LimitStatus;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\FinancialTransaction;
use App\Models\KycDocument;
use App\Models\NumberLimit;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Draw\DrawResultConfirmationService;
use App\Services\Draw\DrawResultIngestionService;
use App\Services\Draw\DrawResultValidator;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use Database\Seeders\LedgerAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Testing\FileFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * The project test case.
 *
 * WHY THIS CLASS NOW DOES SOMETHING
 * -----------------------------------------------------------------------------
 * The Phase 4.3 / 4.4 / 5.1 suites deliberately do NOT use RefreshDatabase:
 * a purchase asserts it is not already inside a transaction, so a suite-wide
 * wrapping transaction would break the very invariant it is testing. They use
 * DatabaseTruncation instead, which commits normally and truncates between
 * tests.
 *
 * DatabaseTruncation, however, only truncates - it never migrates. Every one of
 * those suites therefore assumed an externally prepared, already-migrated test
 * database, which is exactly how they were run during development
 * (`php artisan migrate` against MariaDB `thai_lottery_test`, with DB_* exported
 * in the shell). On a clean checkout, `php artisan test` used the phpunit.xml
 * defaults instead, hit an empty database, and reported ~211 errors of the form
 * "no such table: ledger_accounts" - failures with no relation to any business
 * rule.
 *
 * This override closes that gap in the only place that can see it: the schema is
 * prepared once per PHPUnit process, BEFORE the traits run, so truncation and
 * every fixture find the tables they expect.
 *
 * WHAT IT WILL NOT DO
 * It never drops or recreates a database, and it never runs against a database
 * whose name is not an explicitly allow-listed test name. `migrate` is
 * idempotent, so on the developer's already-migrated MariaDB test database this
 * is a no-op ("Nothing to migrate") and the historical workflow is unchanged.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * The database names this bootstrap is allowed to migrate, matched against
     * the connection's database name and against its basename so that a
     * file-backed SQLite path such as `database/thai_lottery_test` is accepted
     * while a production database name never is.
     *
     * @var list<string>
     */
    private const MIGRATABLE_DATABASES = ['thai_lottery_test', ':memory:'];

    /**
     * Guarded per process, not per test: the schema survives the application
     * refresh that happens between tests for every driver except in-memory
     * SQLite, and for in-memory SQLite the check below is re-evaluated because
     * the table genuinely disappears with the connection.
     */
    private static bool $schemaPrepared = false;

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $this->prepareTestSchema($app);

        return $app;
    }

    /**
     * Build a published result through the real maker/checker ingestion path.
     *
     * Tests that need a result for downstream reads or settlement must not seed a
     * bare draw_results row: publication now requires a persisted Pending intake,
     * two different persisted operators, and the independent publisher.
     *
     * @param  array{first_prize: string, bottom_two: string}  $numbers
     * @return array{draw: Draw, result: DrawResult, data: DrawResultData}
     */
    protected function publishTrustedManualResult(Draw $draw, array $numbers, string $source = 'operator'): array
    {
        $draw = Draw::query()->findOrFail($draw->getKey());
        $lifecycle = app(DrawLifecycleService::class);

        if ($draw->status === DrawStatus::Open) {
            $draw = $lifecycle->close($draw, ['source' => 'test-fixture']);
        }

        if ($draw->status === DrawStatus::Closed) {
            $draw = $lifecycle->markResultPending($draw, ['source' => 'test-fixture']);
        }

        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $drawId = (int) $draw->getKey();

        app(DrawResultIngestionService::class)->ingest(
            $drawId,
            $numbers,
            $source,
            (int) $maker->getKey(),
        );

        app(DrawResultConfirmationService::class)->confirm(
            $drawId,
            (int) $checker->getKey(),
            $numbers,
        );

        return [
            'draw' => Draw::query()->findOrFail($drawId),
            'result' => DrawResult::query()->where('draw_id', $drawId)->firstOrFail(),
            'data' => app(DrawResultValidator::class)->validate($numbers),
        ];
    }

    /**
     * Create an image upload fixture without requiring the GD extension.
     *
     * The committed JPEG bytes are genuine images at the stated dimensions, so
     * Laravel's MIME and image-dimension validation still exercises the normal
     * upload path instead of bypassing it with a fake MIME type.
     */
    protected function fakeImageUpload(string $name, int $width = 640, int $height = 480): UploadedFile
    {
        $fixture = match ([$width, $height]) {
            [900, 600] => base_path('tests/Fixtures/Uploads/test-upload-900x600.jpg'),
            default => base_path('tests/Fixtures/Uploads/test-upload-640x480.jpg'),
        };

        if (! is_file($fixture)) {
            throw new \RuntimeException('The committed upload image fixture is missing.');
        }

        return (new FileFactory)->createWithContent($name, (string) file_get_contents($fixture));
    }

    private function prepareTestSchema(Application $app): void
    {
        $connection = $app->make('db')->connection();
        $database = (string) $connection->getDatabaseName();

        if (! in_array($database, self::MIGRATABLE_DATABASES, true)
            && ! in_array(basename($database), self::MIGRATABLE_DATABASES, true)) {
            // Not a recognised test database. Do nothing at all: the individual
            // suites carry their own fail-safe guard and will abort themselves.
            return;
        }

        if ($database === ':memory:') {
            // A fresh in-memory database exists per connection, so the cached
            // flag must not be trusted here.
            self::$schemaPrepared = false;
        }

        if (self::$schemaPrepared) {
            return;
        }

        if ($connection->getDriverName() === 'sqlite' && $database !== ':memory:') {
            $path = $connection->getConfig('database');

            if (is_string($path) && $path !== '' && ! file_exists($path)) {
                @touch($path);
            }
        }

        // The database is allow-listed and disposable; run pending migrations
        // on every application boot so additive schema corrections are never
        // hidden by a stale SQLite file.
        $app->make(Kernel::class)->call('migrate', ['--force' => true, '--no-interaction' => true]);

        // Financial tests use the same isolated disposable database, so the
        // chart of accounts is an explicit test prerequisite rather than an
        // implicit production fallback. This never runs outside the guarded
        // test database allow-list above.
        (new LedgerAccountSeeder)->run();
        (new RolePermissionSeeder)->run();

        self::$schemaPrepared = true;
    }

    protected function setUp(): void
    {
        parent::setUp();

        (new LedgerAccountSeeder)->run();
        (new RolePermissionSeeder)->run();

        // Real-money gate: tests exercise the money paths, so the gate is open here.
        // RealMoneyGateTest closes it and proves the refusals. Production defaults stay closed.
        config(['real_money.enabled' => true]);
    }

    /**
     * RefreshDatabase drops and rebuilds the disposable schema after the
     * application is created; reseed the deterministic chart and role/permission
     * prerequisites after that refresh. The allow-list remains the guard against
     * production writes.
     */
    protected function afterRefreshingDatabase()
    {
        (new LedgerAccountSeeder)->run();
        (new RolePermissionSeeder)->run();
    }

    /**
     * Provision one risk-capacity row for an exact sellable market selection.
     * Risk counters stay explicit here; the model remains guarded in production.
     */
    protected function provisionBetLimitForTest(Draw $draw, string $marketKey, string $number): NumberLimit
    {
        $market = (array) config('lottery.markets.'.$marketKey, []);
        $betType = BetType::tryFrom((string) ($market['bet_type'] ?? ''));
        $digits = (int) ($market['digits'] ?? 0);

        if ($betType === null || $digits < 1 || preg_match('/^\d+$/', $number) !== 1 || strlen($number) > $digits) {
            throw new \InvalidArgumentException('The test selection does not match a configured market.');
        }

        $normalizedNumber = str_pad($number, $digits, '0', STR_PAD_LEFT);
        $limit = NumberLimit::query()
            ->where('draw_id', (int) $draw->getKey())
            ->where('bet_type', $betType->value)
            ->where('number', $normalizedNumber)
            ->first();

        if ($limit instanceof NumberLimit) {
            return $limit;
        }

        $limit = new NumberLimit;
        $limit->draw_id = (int) $draw->getKey();
        $limit->bet_type = $betType;
        $limit->number = $normalizedNumber;
        $limit->max_amount = '100000.00';
        $limit->current_amount = '0.00';
        $limit->maximum_payout_exposure = null;
        $limit->current_payout_exposure = '0.00';
        $limit->status = LimitStatus::Active;
        $limit->save();

        return $limit;
    }

    /**
     * Seed verified identity evidence for tests that exercise a KYC-gated flow.
     */
    protected function verifyUserKycForTest(User $user): KycDocument
    {
        return KycDocument::query()->create([
            'user_id' => (int) $user->getKey(),
            'document_type' => KycDocumentType::NationalId,
            'file_path' => sprintf('kyc_private/test/%d/%s.enc', $user->getKey(), Str::uuid()),
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'status' => KycStatus::Verified,
            'submitted_at' => now(),
            'verified_at' => now(),
        ]);
    }

    /**
     * Build a valid signed webhook envelope for idempotency tests.
     *
     * The helper uses the real canonical bytes and HMAC contract; it does not
     * bypass signature verification or apply any synthetic wallet mutation.
     */
    protected function signedPaymentWebhookForTest(string $providerCode): PaymentWebhookData
    {
        $providerCode = strtolower($providerCode);
        $secret = 'test-webhook-secret-'.Str::uuid();
        config(["payment.gateways.{$providerCode}.webhook_secret" => $secret]);

        $eventId = $providerCode.'-event-'.Str::uuid();
        $payload = [
            'id' => $eventId,
            'provider' => $providerCode,
            'event_type' => 'deposit.success',
            'created_at' => now()->toIso8601String(),
            'reference' => 'TEST-'.Str::uuid(),
        ];
        $signature = 'sha256='.hash_hmac('sha256', PaymentWebhookData::payloadBytes($payload), $secret);

        return PaymentWebhookData::fromInput(
            providerCode: $providerCode,
            eventId: $eventId,
            eventType: 'deposit.success',
            signature: $signature,
            receivedAtIso: now()->toIso8601String(),
            payload: $payload,
        );
    }

    /**
     * Seed a wallet through the production ledger path for test setup.
     *
     * These helpers deliberately keep test fixtures on the same exact-money,
     * typed-wallet API used by the application. Older repository suites passed
     * wallet IDs and decimal strings directly to WalletService; converting
     * their setup here avoids weakening the production API while preserving
     * atomic posting and idempotency behavior.
     */
    protected function creditWalletForTest(
        Wallet $wallet,
        string $amount,
        string $description,
        ?string $idempotencyKey = null,
        array $additionalOptions = [],
    ): FinancialTransaction {
        return app(WalletService::class)->credit(
            wallet: $wallet,
            amount: Money::fromDatabase($amount, $wallet->currency),
            type: FinancialTransactionType::Deposit,
            idempotencyKey: $idempotencyKey !== null && strlen($idempotencyKey) >= 16
                ? $idempotencyKey
                : 'test-credit-'.hash('sha256', $wallet->getKey().':'.($idempotencyKey ?? Str::uuid())),
            options: array_merge(['description' => $description], $additionalOptions),
        );
    }

    /**
     * Seed a debit through the production ledger path for test setup.
     */
    protected function debitWalletForTest(
        Wallet $wallet,
        string $amount,
        string $description,
        ?string $idempotencyKey = null,
        array $additionalOptions = [],
    ): FinancialTransaction {
        return app(WalletService::class)->debit(
            wallet: $wallet,
            amount: Money::fromDatabase($amount, $wallet->currency),
            type: FinancialTransactionType::Withdrawal,
            idempotencyKey: $idempotencyKey !== null && strlen($idempotencyKey) >= 16
                ? $idempotencyKey
                : 'test-debit-'.hash('sha256', $wallet->getKey().':'.($idempotencyKey ?? Str::uuid())),
            options: array_merge(['description' => $description], $additionalOptions),
        );
    }

    /**
     * Assert that a money column read with the RAW query builder holds an exact value.
     *
     * WHY THIS EXISTS
     * A value read through Eloquent carries the model's `decimal:2` cast, so it always
     * reads '9000.00'. A value read with DB::table(...)->value(...) bypasses the cast and
     * comes back in the driver's own spelling: MySQL/MariaDB return the decimal(20,2)
     * column as '9000.00', while SQLite's NUMERIC affinity returns '9000'. Both hold the
     * identical amount, so assertSame('9000.00', ...) was asserting the DRIVER's
     * formatting rather than the project's money handling, and eight settlement tests
     * failed on the very database phpunit.xml configures by default.
     *
     * The comparison is made with bccomp at scale 2 - the project's own money rule.
     */
    protected function assertStoredMoneySame(string $expected, mixed $actual, string $message = ''): void
    {
        $raw = is_string($actual) || is_numeric($actual) ? (string) $actual : '';

        $this->assertMatchesRegularExpression(
            '/^-?[0-9]+(\.[0-9]+)?$/',
            $raw,
            $message !== '' ? $message : 'A stored money column must read back as a plain decimal string.',
        );

        $this->assertSame(
            0,
            bccomp($expected, $raw, 2),
            $message !== '' ? $message : sprintf('Stored money must equal %s exactly; the column holds "%s".', $expected, $raw),
        );
    }
}
