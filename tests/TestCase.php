<?php

namespace Tests;

use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
 * SAFETY BOUNDARY
 * Laravel's database traits can run migrate:fresh or truncate before a child
 * test's setUp() guard runs. All configured trait connections must therefore be
 * checked in createApplication, BEFORE either schema preparation or traits.
 * A recognised name is only a guardrail, not proof that a server is disposable:
 * use dedicated test-only credentials and an isolated database server as well.
 */
abstract class TestCase extends BaseTestCase
{
    /** Name of the explicitly disposable database for this test harness. */
    private const TEST_DATABASE = 'thai_lottery_test';

    /**
     * Guarded per process, not per test: the schema survives the application
     * refresh that happens between tests for every driver except in-memory
     * SQLite, and for in-memory SQLite the check below is re-evaluated because
     * the table genuinely disappears with the connection.
     */
    private static bool $schemaPrepared = false;

    /**
     * The transaction level a test begins at, which production code cannot read for
     * itself.
     *
     * A few money services refuse to run inside a caller transaction (a checkout
     * dispatch that could be rolled back after the provider was already called). Under
     * RefreshDatabase every test body runs inside the trait's wrapping transaction, so
     * the honest answer there is "1", not "0" — the service is not inside a transaction
     * that IT opened. DatabaseTruncation classes commit normally and stay at 0.
     *
     * This is the test suite publishing its own state; the service reads it only when
     * app()->environment('testing').
     */
    public static int $refreshDatabaseBaseDepth = 0;

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $this->assertDisposableTestConnections($app);
        $this->prepareTestSchema($app);

        return $app;
    }

    /** Refuse BEFORE Laravel's database traits can migrate, truncate or seed. */
    private function assertDisposableTestConnections(Application $app, bool $requireTestingEnvironment = true): void
    {
        $names = [null];
        foreach (['connectionsToTruncate', 'connectionsToTransact'] as $method) {
            if (method_exists($this, $method)) {
                $names = array_merge($names, (array) $this->{$method}());
            }
        }

        foreach (array_unique($names, SORT_REGULAR) as $name) {
            $connection = $app->make('db')->connection($name);
            $database = (string) $connection->getDatabaseName();
            $driver = $connection->getDriverName();
            $isAllowed = $driver === 'sqlite'
                ? ($database === ':memory:' || (basename($database) === self::TEST_DATABASE && ! is_link($database)))
                : $database === self::TEST_DATABASE;

            if (($requireTestingEnvironment && ! $app->environment('testing')) || ! $isAllowed) {
                throw new \RuntimeException(sprintf(
                    'ABORTED: tests may only use the disposable thai_lottery_test database or SQLite :memory: in the testing environment; connection "%s" is not allowed.',
                    $name ?? 'default',
                ));
            }
        }
    }

    protected function setUpTraits()
    {
        // Recheck after parallel-test setup callbacks and immediately before
        // any trait issues migrate:fresh, starts transactions or truncates.
        $this->assertDisposableTestConnections($this->app);

        return parent::setUpTraits();
    }

    private function prepareTestSchema(Application $app): void
    {
        $connection = $app->make('db')->connection();
        $database = (string) $connection->getDatabaseName();

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

        self::$schemaPrepared = true;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests exercise server-rendered behavior; compiled asset
        // availability is covered by deployment/build tests, not each request.
        $this->withoutVite();

        // Test flows run with the launch gate explicitly open; gate-specific tests close it.
        config(['real_money.enabled' => true]);

        self::$refreshDatabaseBaseDepth = in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)
            ? $this->app->make('db')->connection()->transactionLevel()
            : 0;

        (new LedgerAccountSeeder)->run();
    }

    /**
     * RefreshDatabase drops and rebuilds the disposable schema after the
     * application is created; reseed only the deterministic chart prerequisite
     * after that refresh. The allow-list remains the guard against production
     * writes.
     */
    protected function afterRefreshingDatabase()
    {
        (new LedgerAccountSeeder)->run();
    }

    protected function tearDown(): void
    {
        // DatabaseTruncation runs its table truncation at the START of a test,
        // so the last test in a DatabaseTruncation class would otherwise leave
        // its rows in a file-backed SQLite database for the next class.
        if (isset($this->app) && in_array(DatabaseTruncation::class, class_uses_recursive(static::class), true)) {
            // A test may have reconfigured a connection after setUp. Check all
            // target connections again before this manual teardown truncation.
            // Some tests temporarily simulate production in the application;
            // the initial guard already required a genuine testing bootstrap.
            $this->assertDisposableTestConnections($this->app, false);
            $this->truncateTablesForAllConnections();
            (new LedgerAccountSeeder)->run();
        }

        parent::tearDown();

        // Full financial suites repeatedly rebuild a large Laravel container.
        // Collect unreachable per-application cycles before the next bootstrap.
        gc_collect_cycles();
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

    protected function provisionBetLimitForTest(
        \App\Models\Draw $draw,
        string $market,
        string $rawNumber,
        string $maxAmount = '1000000.00',
    ): \App\Models\NumberLimit {
        $definition = config('lottery.markets.'.$market);
        $betType = is_array($definition)
            ? (\App\Enums\BetType::tryFrom((string) ($definition['bet_type'] ?? '')) ?? \App\Enums\BetType::ThreeD)
            : (\App\Enums\BetType::tryFrom($market) ?? \App\Enums\BetType::ThreeD);
        $digits = is_array($definition) ? (int) ($definition['digits'] ?? strlen($rawNumber)) : strlen($rawNumber);
        $number = $digits > 0 && ctype_digit($rawNumber)
            ? str_pad($rawNumber, $digits, '0', STR_PAD_LEFT)
            : $rawNumber;

        $existing = \App\Models\NumberLimit::query()
            ->where('draw_id', $draw->getKey())
            ->where('bet_type', $betType->value)
            ->where('number', $number)
            ->first();

        if ($existing instanceof \App\Models\NumberLimit) {
            return $existing;
        }

        $limit = new \App\Models\NumberLimit;
        $limit->draw_id = (int) $draw->getKey();
        $limit->bet_type = $betType;
        $limit->number = $number;
        $limit->max_amount = $maxAmount;
        $limit->current_amount = '0.00';
        $limit->maximum_payout_exposure = '1000000.00';
        $limit->current_payout_exposure = '0.00';
        $limit->status = \App\Enums\LimitStatus::Active;
        $limit->save();

        return $limit;
    }

    protected function verifyUserKycForTest(\App\Models\User $user): \App\Models\KycDocument
    {
        $doc = \App\Models\KycDocument::query()->create([
            'user_id' => $user->getKey(),
            'document_type' => 'national_id',
            'document_number' => 'TEST-'.strtoupper(bin2hex(random_bytes(4))),
            'file_path' => 'kyc/test-'.$user->getKey().'.png',
            'original_filename' => 'id.png',
            'mime_type' => 'image/png',
            'file_size' => 1024,
            'status' => \App\Enums\KycStatus::Verified,
            'verified_at' => now(),
        ]);

        $user->unsetRelation('kycDocuments');

        return $doc;
    }
}
