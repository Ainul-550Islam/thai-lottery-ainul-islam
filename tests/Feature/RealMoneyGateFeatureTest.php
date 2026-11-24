<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Models\LedgerAccount;
use App\Services\Compliance\MoneyOperationControlService;
use App\Services\Compliance\RealMoneyGate;
use App\Services\Compliance\RealMoneyTechnicalReadinessService;
use App\Services\Finance\LedgerPostingService;
use App\Services\License\LicenseService;
use App\Support\Ops\CheckResult;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * The technical half of the real-money launch decision, at application level.
 *
 * WHAT THIS SUITE PROVES
 * ----------------------
 * `RealMoneyGate` answers one bit: may this DEPLOYMENT move money. This suite is
 * about the question that had no owner — may this platform CARRY a monetary
 * movement end to end — and about the boundary between the two:
 *
 *   1. a closed gate reports honestly instead of reporting red (§2);
 *   2. an open gate with no licence and no provider lane is NOT ready (§3, §4);
 *   3. the KYC custody-disk gap is blocking exactly when money can move (§5);
 *   4. a hole in the chart of accounts is named, not summarised (§6);
 *   5. an operation is decided only by the checks that can stop it (§7);
 *   6. an unknown operation refuses instead of answering a broader question (§8);
 *   7. the whole audit is read-only (§9).
 *
 * WHY THE DEFAULT TEST ENVIRONMENT IS *NOT* READY
 * -----------------------------------------------
 * `Tests\TestCase::setUp()` opens the gate (`real_money.enabled = true`) so the
 * money-path suites can run, but phpunit.xml configures no licence, no gateway
 * credentials and no `FIELD_ENCRYPTION_KEY`, and `config/filesystems.php` declares
 * only `local` and `public`. An open gate over that configuration is therefore
 * genuinely not ready, and these tests assert that as the correct answer rather
 * than working around it. A suite that had to invent a fully licensed, fully
 * credentialed deployment in order to see a PASS would be testing its own fixture.
 */
final class RealMoneyGateFeatureTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * Tables a monetary readiness audit could plausibly write to. Counted before
     * and after so the read-only contract is proved against the real schema
     * rather than asserted from the docblock.
     *
     * @var list<string>
     */
    private const MONEY_TABLES = [
        'ledger_accounts',
        'ledger_entries',
        'financial_transactions',
        'financial_holds',
        'wallets',
        'deposits',
        'withdrawals',
        'payments',
        'payment_intents',
        'audit_logs',
    ];

    private function readiness(): RealMoneyTechnicalReadinessService
    {
        return app(RealMoneyTechnicalReadinessService::class);
    }

    private function check(string $report, string $id): CheckResult
    {
        $checks = $this->readiness()->checks();

        self::assertArrayHasKey($id, $checks, 'The readiness report did not publish '.$id.'.');

        return $checks[$id];
    }

    /**
     * Open the gate behind a complete, unexpired licence.
     *
     * `LicenseService` is bound to an explicit configuration SNAPSHOT (a primitive
     * array cannot be autowired), so a licence written at runtime is only visible
     * after the binding is refreshed. That is a property of the existing binding,
     * not a workaround this suite invented — and it is worth stating plainly,
     * because a deployment that edits `REAL_MONEY_LICENSE_*` without rebuilding
     * `config:cache` and restarting is in exactly this position.
     */
    private function openLicensedGate(): void
    {
        config([
            'real_money.enabled' => true,
            'real_money.license' => [
                'issuer' => 'Test Licensing Authority',
                'scope' => 'thai-lottery-betting',
                'expiry' => now()->addYears(2)->format('Y-m-d'),
                'countries' => 'TH,BD',
                'signature' => 'test-signature-digest',
            ],
            'real_money.authorized_countries' => ['TH', 'BD'],
        ]);

        app()->bind(LicenseService::class, static fn ($app): LicenseService =>
            new LicenseService((array) $app['config']->get('real_money', [])));
    }

    /**
     * @return array<string, int>
     */
    private function moneyRowCounts(): array
    {
        $counts = [];
        foreach (self::MONEY_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    // =====================================================================

    public function test_the_control_surface_resolves_and_is_not_a_shared_instance(): void
    {
        $first = app(RealMoneyTechnicalReadinessService::class);
        $second = app(RealMoneyTechnicalReadinessService::class);
        $controls = app(MoneyOperationControlService::class);

        self::assertInstanceOf(RealMoneyTechnicalReadinessService::class, $first);
        self::assertInstanceOf(MoneyOperationControlService::class, $controls);
        self::assertInstanceOf(RealMoneyGate::class, app(RealMoneyGate::class));

        // Both classes are deliberately uncached: a readiness answer must describe
        // the configuration as it is NOW. A shared instance would serve the first
        // caller's reading to every later one, which for a fail-closed gate means
        // one stale answer deciding money movement for the rest of the process.
        self::assertNotSame($first, $second, 'The readiness service was resolved as a shared instance.');
        self::assertNotSame($controls, app(MoneyOperationControlService::class), 'The control service was resolved as a shared instance.');
    }

    public function test_a_closed_gate_reports_no_blocking_failure_and_skips_the_launch_only_checks(): void
    {
        config(['real_money.enabled' => false]);

        $report = $this->readiness()->report();

        self::assertFalse($report['real_money_enabled']);
        self::assertSame([], $report['blocking'], 'A deployment that moves no money reported a blocking failure: '.json_encode($report['blocking']));
        self::assertTrue($this->readiness()->ready());

        // "A lane that is off is not a failing lane." A fresh deployment gets an
        // honest "nothing here is broken, and nothing here was checked either"
        // rather than a wall of red it has to learn to ignore — and a wall of red
        // that is ignored is how the one genuine FAIL gets missed.
        self::assertSame(CheckResult::PASS, $this->check('report', RealMoneyTechnicalReadinessService::CHECK_GATE_SWITCH)->status);
        self::assertSame(CheckResult::SKIP, $this->check('report', RealMoneyTechnicalReadinessService::CHECK_LICENSE_CONTRACT)->status);
        self::assertSame(CheckResult::SKIP, $this->check('report', RealMoneyTechnicalReadinessService::CHECK_PROVIDER_LANES)->status);
        self::assertSame(CheckResult::WARN, $this->check('report', RealMoneyTechnicalReadinessService::CHECK_UPLOAD_CUSTODY_DISKS)->status);

        self::assertGreaterThan(0, $report['summary']['skip'], 'A closed gate evaluated checks it has no obligation to satisfy.');
    }

    public function test_an_open_gate_with_no_licence_reports_the_refusal_by_reason(): void
    {
        config(['real_money.enabled' => true]);
        app()->bind(LicenseService::class, static fn ($app): LicenseService =>
            new LicenseService((array) $app['config']->get('real_money', [])));

        $check = $this->check('report', RealMoneyTechnicalReadinessService::CHECK_LICENSE_CONTRACT);

        self::assertSame(CheckResult::FAIL, $check->status);
        self::assertStringContainsString('missing_fields', $check->message, 'The licence refusal did not carry LicenseService\'s own reason.');

        // The verdict is LicenseService's, reported — not a second opinion about
        // the same fields. EnforceRealMoneyLicense stays the enforcing gate.
        self::assertSame(false, app(LicenseService::class)->status()['licensed']);
        self::assertFalse($this->readiness()->ready());
        self::assertArrayHasKey(RealMoneyTechnicalReadinessService::CHECK_LICENSE_CONTRACT, $this->readiness()->blockingFailures());
    }

    public function test_an_open_licensed_gate_with_no_enabled_provider_lane_is_not_ready(): void
    {
        $this->openLicensedGate();

        // phpunit.xml configures no gateway, so every lane in
        // config/payment.php defaults to disabled.
        $check = $this->check('report', RealMoneyTechnicalReadinessService::CHECK_PROVIDER_LANES);

        self::assertSame(CheckResult::FAIL, $check->status);
        self::assertStringContainsString('no payment gateway is enabled for THB', $check->message);
        self::assertFalse($this->readiness()->ready());

        // The licence alone must not make the deployment ready: an open switch, a
        // valid licence and no lane to carry the money is the exact state that
        // takes a deposit page live and then refuses every deposit on it.
        self::assertSame(CheckResult::PASS, $this->check('report', RealMoneyTechnicalReadinessService::CHECK_LICENSE_CONTRACT)->status);
    }

    public function test_the_kyc_custody_disk_gap_blocks_exactly_when_money_can_move(): void
    {
        // config/filesystems.php declares only `local` and `public`, while
        // UploadQuarantineService and UploadScanService resolve `quarantine` and
        // fall back to `private`. This check is what makes that visible.
        config(['real_money.enabled' => true]);
        $open = $this->check('report', RealMoneyTechnicalReadinessService::CHECK_UPLOAD_CUSTODY_DISKS);
        self::assertSame(CheckResult::FAIL, $open->status);
        self::assertStringContainsString('quarantine', $open->message);
        self::assertStringContainsString('private', $open->message);
        self::assertArrayHasKey(RealMoneyTechnicalReadinessService::CHECK_UPLOAD_CUSTODY_DISKS, $this->readiness()->blockingFailures());

        config(['real_money.enabled' => false]);
        $closed = $this->check('report', RealMoneyTechnicalReadinessService::CHECK_UPLOAD_CUSTODY_DISKS);
        self::assertSame(CheckResult::WARN, $closed->status, 'A closed gate must not be blocked by a custody obligation it does not yet have.');
        self::assertStringContainsString('blocking once real money is open', $closed->message);

        // Declaring the disks closes the finding without any other change, which
        // is the whole point: the check names a configuration gap, not a defect
        // that needs code.
        config(['filesystems.disks.quarantine' => ['driver' => 'local', 'root' => storage_path('app/quarantine')]]);
        config(['filesystems.disks.private' => ['driver' => 'local', 'root' => storage_path('app/private')]]);
        config(['real_money.enabled' => true]);

        $declared = $this->check('report', RealMoneyTechnicalReadinessService::CHECK_UPLOAD_CUSTODY_DISKS);
        self::assertSame(CheckResult::PASS, $declared->status);
        self::assertArrayNotHasKey(RealMoneyTechnicalReadinessService::CHECK_UPLOAD_CUSTODY_DISKS, $this->readiness()->blockingFailures());
    }

    public function test_a_hole_in_the_chart_of_accounts_is_named_rather_than_summarised(): void
    {
        $this->openLicensedGate();

        $code = LedgerPostingService::accountCodeFor('2000', Currency::primary());
        $account = LedgerAccount::query()->where('code', $code)->firstOrFail();

        self::assertSame(CheckResult::PASS, $this->check('report', RealMoneyTechnicalReadinessService::CHECK_CHART_OF_ACCOUNTS)->status);

        try {
            // Soft delete, because LedgerAccount uses SoftDeletes: a deleted
            // account cannot be posted to, and restoring it loses nothing — not
            // even the posting-derived balances the seeder deliberately never
            // writes.
            $account->delete();

            $check = $this->check('report', RealMoneyTechnicalReadinessService::CHECK_CHART_OF_ACCOUNTS);

            self::assertSame(CheckResult::FAIL, $check->status);
            self::assertStringContainsString($code, $check->message, 'The report summarised the gap instead of naming the code that cannot be posted to.');
            self::assertStringContainsString('Seed the chart of accounts', $check->message);
            self::assertFalse($this->readiness()->readyForOperation(RealMoneyTechnicalReadinessService::OP_BET));
        } finally {
            $account->restore();
        }

        self::assertSame(CheckResult::PASS, $this->check('report', RealMoneyTechnicalReadinessService::CHECK_CHART_OF_ACCOUNTS)->status);
    }

    public function test_an_operation_is_decided_only_by_the_checks_that_can_stop_it(): void
    {
        $this->openLicensedGate();

        // A bet moves money between the player wallet and the platform ledger. It
        // never dispatches a provider, so a dark gateway lane cannot refuse it —
        // and the technical scope for `bet` says so.
        $betScope = $this->readiness()->scopedIds(RealMoneyTechnicalReadinessService::OP_BET);
        self::assertNotContains(RealMoneyTechnicalReadinessService::CHECK_PROVIDER_LANES, $betScope);
        self::assertNotContains(RealMoneyTechnicalReadinessService::CHECK_UPLOAD_CUSTODY_DISKS, $betScope);
        self::assertContains(RealMoneyTechnicalReadinessService::CHECK_CHART_OF_ACCOUNTS, $betScope);

        self::assertTrue(
            $this->readiness()->readyForOperation(RealMoneyTechnicalReadinessService::OP_BET),
            'A licensed deployment with a seeded chart and no provider lane must be able to take a bet: '.json_encode($this->readiness()->blockingFailures(RealMoneyTechnicalReadinessService::OP_BET)),
        );

        // The estate as a whole is still not ready, because `deposit` needs a lane.
        self::assertFalse($this->readiness()->ready());
        self::assertFalse($this->readiness()->readyForOperation(RealMoneyTechnicalReadinessService::OP_DEPOSIT));

        // Scoping narrows the answer; it never widens it.
        self::assertSame([], $this->readiness()->blockingFailures(RealMoneyTechnicalReadinessService::OP_BET));
        self::assertNotEmpty($this->readiness()->blockingFailures(RealMoneyTechnicalReadinessService::OP_DEPOSIT));
    }

    public function test_an_unknown_operation_refuses_instead_of_answering_a_broader_question(): void
    {
        foreach (['deposits', 'withdraw', 'BET ', '', 'payout-batch'] as $operation) {
            if (trim($operation) === '') {
                continue;
            }

            $name = strtolower(trim($operation));
            if (in_array($name, RealMoneyTechnicalReadinessService::OPERATIONS, true)) {
                continue;
            }

            try {
                $this->readiness()->report($name);
                self::fail('An unknown operation "'.$name.'" was answered instead of refused.');
            } catch (FinancialException $e) {
                self::assertSame('real_money_operation_unknown', $e->errorCode());
                self::assertStringContainsString($name, (string) $e->contextValue('operation'));
                self::assertStringContainsString('deposit', (string) $e->contextValue('known'));
            }
        }

        // `BET ` normalises to a known operation, and case must not matter: the
        // money paths call assertOpen('bet') in lower case, but an operator typing
        // `--operation=BET` at a console is asking the same question.
        self::assertIsArray($this->readiness()->report('BET '));
        self::assertNull($this->readiness()->report()['operation'], 'A null operation must mean the whole estate, not a defaulted one.');
    }

    public function test_the_audit_writes_nothing_and_leaves_the_ledger_untouched(): void
    {
        $this->openLicensedGate();
        $before = $this->moneyRowCounts();

        // Everything an operator can ask for, twice: a readiness probe that
        // writes cannot be run twice without changing what it measures.
        $this->readiness()->report();
        $this->readiness()->report(RealMoneyTechnicalReadinessService::OP_PAYOUT);
        $this->readiness()->checks();
        $this->readiness()->blockingFailures();
        $this->readiness()->ready();
        $this->readiness()->readyForOperation(RealMoneyTechnicalReadinessService::OP_DEPOSIT);
        $this->readiness()->report();

        self::assertSame($before, $this->moneyRowCounts(), 'The readiness audit wrote to the database.');
    }

    public function test_the_report_publishes_the_preflight_shape_so_one_parser_reads_both(): void
    {
        $report = $this->readiness()->report();

        // ProductionPreflight::run() publishes status, environment, checks and
        // summary. An operator tool that already parses one must be able to parse
        // the other, so those four are present with the same names and the same
        // inner shape — and `operation`, `real_money_enabled` and `blocking` are
        // ADDED alongside them, never substituted for them.
        foreach (['status', 'environment', 'checks', 'summary'] as $key) {
            self::assertArrayHasKey($key, $report);
        }
        foreach (['pass', 'warn', 'fail', 'skip'] as $key) {
            self::assertArrayHasKey($key, $report['summary']);
        }

        self::assertContains($report['status'], [CheckResult::PASS, CheckResult::WARN, CheckResult::FAIL]);
        self::assertSame((string) config('app.env'), $report['environment']);

        foreach ($report['checks'] as $check) {
            self::assertSame(['id', 'status', 'detail'], array_keys($check), 'A check did not publish the CheckResult contract.');
        }

        // Every published check id, in the published order, so a report cannot
        // silently drop a check or reorder under an operator diffing two runs.
        self::assertSame(
            RealMoneyTechnicalReadinessService::CHECKS,
            array_column($report['checks'], 'id'),
        );

        self::assertSame(
            count($report['checks']),
            $report['summary']['pass'] + $report['summary']['warn'] + $report['summary']['fail'] + $report['summary']['skip'],
            'The summary does not account for every check.',
        );
    }

    public function test_a_configuration_drift_the_money_paths_would_not_notice_is_reported(): void
    {
        $this->openLicensedGate();

        // finance.currency.default and payment.currency.default both read
        // FINANCE_DEFAULT_CURRENCY, so they agree by construction — which means a
        // split can only come from a runtime override. That is exactly the drift
        // which makes a deposit admit in one currency and post in another.
        config(['payment.currency.default' => 'BDT']);

        $check = $this->check('report', RealMoneyTechnicalReadinessService::CHECK_CURRENCY_CONTRACT);
        self::assertSame(CheckResult::FAIL, $check->status);
        self::assertStringContainsString('disagree', $check->message);

        config(['payment.currency.default' => 'THB']);
        self::assertSame(CheckResult::PASS, $this->check('report', RealMoneyTechnicalReadinessService::CHECK_CURRENCY_CONTRACT)->status);

        // A ledger invariant is a boolean, so turning one off is a single-character
        // edit that no migration, no test and no provider credential would notice.
        config(['finance.ledger.require_balanced_entries' => false]);
        $invariants = $this->check('report', RealMoneyTechnicalReadinessService::CHECK_LEDGER_INVARIANTS);
        self::assertSame(CheckResult::FAIL, $invariants->status);
        self::assertStringContainsString('finance.ledger.require_balanced_entries', $invariants->message);

        config(['finance.ledger.balance_tolerance' => '0.01']);
        self::assertStringContainsString(
            'balance_tolerance',
            $this->check('report', RealMoneyTechnicalReadinessService::CHECK_LEDGER_INVARIANTS)->message,
            'A tolerance above zero lets an unbalanced posting settle quietly.',
        );

        config(['finance.ledger.require_balanced_entries' => true, 'finance.ledger.balance_tolerance' => '0.00']);
        self::assertSame(CheckResult::PASS, $this->check('report', RealMoneyTechnicalReadinessService::CHECK_LEDGER_INVARIANTS)->status);
    }

    /**
     * "We could not tell" is not a readiness answer.
     *
     * The schema probe is made to fail at the FACADE, not by repointing the
     * connection: `Tests\TestCase` refuses to tear down on any connection whose
     * database name is not the disposable `thai_lottery_test`, so a test that
     * edited `database.connections.*.database` would abort the harness rather than
     * prove anything. Swapping the schema builder leaves the real connection — and
     * the guard — completely alone.
     */
    public function test_an_unreadable_schema_refuses_rather_than_reporting_ready(): void
    {
        $this->openLicensedGate();
        $before = $this->moneyRowCounts();

        self::assertTrue($this->readiness()->readyForOperation(RealMoneyTechnicalReadinessService::OP_BET));

        Schema::shouldReceive('hasTable')->andThrow(new RuntimeException('database is down'));

        try {
            self::assertFalse($this->readiness()->ready());

            $blocking = $this->readiness()->blockingFailures();
            self::assertArrayHasKey(RealMoneyTechnicalReadinessService::CHECK_DATABASE_SCHEMA, $blocking);
            self::assertStringContainsString('could not be reached', $blocking[RealMoneyTechnicalReadinessService::CHECK_DATABASE_SCHEMA]);

            // The refusal must reach the operation verdict too, or a bet would be
            // admitted on a deployment whose schema could not be read.
            self::assertFalse($this->readiness()->readyForOperation(RealMoneyTechnicalReadinessService::OP_BET));
        } finally {
            // Facade::swap() — which shouldReceive() uses — writes the mock into
            // BOTH the facade's resolved-instance cache and the container, so
            // clearing one alone leaves the other serving the mock to every later
            // test in the process.
            Facade::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
        }

        // The probe is read-only, so clearing the swap is enough to restore the
        // true answer: nothing was written that could keep the verdict red.
        self::assertSame(CheckResult::PASS, $this->check('report', RealMoneyTechnicalReadinessService::CHECK_DATABASE_SCHEMA)->status);
        self::assertTrue($this->readiness()->readyForOperation(RealMoneyTechnicalReadinessService::OP_BET));
        self::assertSame($before, $this->moneyRowCounts(), 'A refused probe wrote to the database.');
    }
}
