<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\PaymentOpsServiceProvider;
use App\Services\Compliance\MoneyOperationControlService;
use App\Services\Compliance\RealMoneyTechnicalReadinessService;
use App\Services\License\LicenseService;
use App\Support\Ops\CheckResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `money:technical-readiness` — the read-only audit, and the contract it publishes.
 *
 * WHY A SEPARATE SUITE FROM RealMoneyGateFeatureTest
 * --------------------------------------------------
 * That suite proves the service answers correctly. This one proves the COMMAND is
 * a faithful window onto it, and that the window has a stable shape: the exit
 * codes a deploy gate branches on, the JSON keys a script parses, the refusal an
 * operator reads when they typo an operation name. A correct service behind a
 * command that exits 0 on failure is still a release that ships broken.
 *
 * THE EXIT CODES ARE THE CONTRACT
 * -------------------------------
 *   0  no blocking failure
 *   1  at least one blocking failure, or the command could not answer
 *   2  warnings only, and `--strict` was passed
 *
 * These mirror `ops:preflight` exactly, so one deploy gate can read both.
 *
 * THE LAST TEST IS A REGRESSION GUARD
 * -----------------------------------
 * `payments:readiness` already publishes `real_money_enabled`, `ready`,
 * `gateways` and `thai_rails`, and a deploy script parses that output. Adding a
 * command must not change the one that was already there.
 */
final class PaymentReadinessTest extends TestCase
{
    /** @var list<string> */
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

    private const COMMAND = 'money:technical-readiness';

    /**
     * Run the audit and decode its JSON. Every assertion in this suite goes
     * through here so the JSON contract is exercised by every test rather than by
     * one test that happens to be named after it.
     *
     * Named `auditJson`, not `json`: `Illuminate\Foundation\Testing\TestCase::json()`
     * is the public HTTP-verb helper, and a private method of the same name is a
     * fatal "access level must be public" error at class-load time — the whole file
     * dies before a single test runs.
     *
     * @param  array<string, mixed>  $options
     * @return array{exit: int, report: array<string, mixed>}
     */
    private function auditJson(array $options = []): array
    {
        $exit = Artisan::call(self::COMMAND, $options + ['--json' => true]);
        $output = Artisan::output();

        self::assertNotSame('', trim($output), 'The command produced no output.');

        return [
            'exit' => $exit,
            'report' => json_decode($output, true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    private function license(): void
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

    public function test_the_audit_is_registered_on_the_money_ops_console_surface(): void
    {
        // Registered explicitly by PaymentOpsServiceProvider rather than left to
        // the app/Console/Commands discovery, so the money-ops console surface is
        // readable in one place.
        self::assertNotNull(app()->getProvider(PaymentOpsServiceProvider::class));
        self::assertArrayHasKey(self::COMMAND, Artisan::all());

        // The command that was already there is untouched.
        self::assertArrayHasKey('payments:readiness', Artisan::all());
        self::assertArrayHasKey('thai-rails:preflight', Artisan::all());

        self::assertSame(
            'Audit the technical readiness of the real-money path without writing, and without contacting any provider.',
            Artisan::all()[self::COMMAND]->getDescription(),
        );
    }

    public function test_the_json_report_publishes_the_documented_contract(): void
    {
        $result = $this->auditJson();
        $report = $result['report'];

        // ProductionPreflight publishes status, environment, checks and summary;
        // this report carries those four with the same names and the same inner
        // shape, and ADDS operation, real_money_enabled, ready and blocking.
        foreach (['status', 'environment', 'operation', 'real_money_enabled', 'ready', 'checks', 'summary', 'blocking'] as $key) {
            self::assertArrayHasKey($key, $report, 'The JSON report dropped "'.$key.'".');
        }

        self::assertNull($report['operation']);
        self::assertTrue($report['real_money_enabled'], 'Tests\TestCase opens the gate; the report disagreed.');
        self::assertSame((string) config('app.env'), $report['environment']);
        self::assertContains($report['status'], [CheckResult::PASS, CheckResult::WARN, CheckResult::FAIL]);

        self::assertSame(RealMoneyTechnicalReadinessService::CHECKS, array_column($report['checks'], 'id'));

        foreach ($report['checks'] as $check) {
            self::assertSame(['id', 'status', 'detail'], array_keys($check));
            self::assertContains($check['status'], [CheckResult::PASS, CheckResult::WARN, CheckResult::FAIL, CheckResult::SKIP]);
        }

        foreach (['pass', 'warn', 'fail', 'skip'] as $key) {
            self::assertIsInt($report['summary'][$key]);
        }
        self::assertSame(
            count($report['checks']),
            array_sum($report['summary']),
            'The summary does not account for every check.',
        );
    }

    public function test_an_unlicensed_open_deployment_exits_non_zero_and_names_what_blocks_it(): void
    {
        $result = $this->auditJson();

        // The default test environment has the gate open but no licence and no
        // gateway credentials. That is genuinely not ready, and a deploy gate
        // reading this command must be told so by the exit code, not only by the
        // body.
        self::assertSame(1, $result['exit']);
        self::assertFalse($result['report']['ready']);
        self::assertNotEmpty($result['report']['blocking']);
        self::assertArrayHasKey(RealMoneyTechnicalReadinessService::CHECK_LICENSE_CONTRACT, $result['report']['blocking']);
        self::assertArrayHasKey(RealMoneyTechnicalReadinessService::CHECK_PROVIDER_LANES, $result['report']['blocking']);

        // The text form says the same thing, for the operator reading a console.
        // `Artisan::output()` is `BufferedOutput::fetch()`, which EMPTIES the
        // buffer, so it is captured once and asserted against twice.
        self::assertSame(1, Artisan::call(self::COMMAND));
        $text = Artisan::output();
        self::assertStringContainsString('Money technical readiness FAILED', $text);
        self::assertStringContainsString('Blocking:', $text);
        self::assertStringContainsString('real_money.license_contract', $text);
    }

    public function test_a_closed_gate_exits_zero_because_nothing_evaluated_is_broken(): void
    {
        config(['real_money.enabled' => false]);

        $result = $this->auditJson();

        self::assertSame(0, $result['exit'], 'A deployment that moves no money was reported as failing: '.json_encode($result['report']['blocking']));
        self::assertTrue($result['report']['ready']);
        self::assertFalse($result['report']['real_money_enabled']);
        self::assertSame([], $result['report']['blocking']);
        self::assertGreaterThan(0, $result['report']['summary']['skip']);

        self::assertSame(0, Artisan::call(self::COMMAND));
        $text = Artisan::output();
        self::assertStringContainsString('real money is CLOSED', $text);
        self::assertStringContainsString('nothing evaluated here is broken', $text);
        self::assertStringContainsString('SKIP', $text, 'A closed gate did not report its unevaluated checks as skipped.');
    }

    public function test_the_operation_option_scopes_the_verdict_and_the_exit_code(): void
    {
        $this->license();

        // A licensed deployment with a seeded chart can take a bet: the bet scope
        // excludes the provider lane and the KYC custody disks, because neither can
        // stop a bet.
        $bet = $this->auditJson(['--operation' => 'bet']);
        self::assertSame(0, $bet['exit'], 'A licensed bet was refused: '.json_encode($bet['report']['blocking']));
        self::assertSame('bet', $bet['report']['operation']);
        self::assertTrue($bet['report']['ready']);
        self::assertSame([], $bet['report']['blocking']);

        // The same deployment cannot take a deposit: there is no rail to carry it.
        $deposit = $this->auditJson(['--operation' => 'deposit']);
        self::assertSame(1, $deposit['exit']);
        self::assertSame('deposit', $deposit['report']['operation']);
        self::assertFalse($deposit['report']['ready']);
        self::assertArrayHasKey(RealMoneyTechnicalReadinessService::CHECK_PROVIDER_LANES, $deposit['report']['blocking']);

        // Scoping narrows the verdict but never the report: an operator auditing
        // `deposit` still sees the payout lane, because the next question is
        // usually "and what about the others".
        self::assertSame(RealMoneyTechnicalReadinessService::CHECKS, array_column($deposit['report']['checks'], 'id'));
        self::assertCount(count(RealMoneyTechnicalReadinessService::CHECKS), $deposit['report']['checks']);

        // Case and surrounding space are normalised: an operator typing
        // `--operation=BET` at a console is asking the same question as the money
        // paths' assertOpen('bet').
        self::assertSame(0, $this->auditJson(['--operation' => ' BET '])['exit']);
    }

    public function test_an_unknown_operation_exits_non_zero_with_a_readable_refusal(): void
    {
        $result = $this->auditJson(['--operation' => 'deposits']);

        // A command that cannot answer must never exit 0, or a deploy gate would
        // read a typo as a pass.
        self::assertSame(1, $result['exit']);
        self::assertFalse($result['report']['ready']);
        self::assertSame('FAIL', $result['report']['status']);
        self::assertStringContainsString('Unknown operation "deposits"', $result['report']['checks'][0]['detail']);

        // The refusal names the vocabulary instead of leaving the operator to grep
        // for it.
        foreach (RealMoneyTechnicalReadinessService::OPERATIONS as $operation) {
            self::assertStringContainsString($operation, $result['report']['checks'][0]['detail']);
        }

        self::assertSame(1, Artisan::call(self::COMMAND, ['--operation' => 'deposits']));
        self::assertStringContainsString('Unknown operation "deposits"', Artisan::output());

        // --controls refuses the same way rather than printing a blank surface.
        self::assertSame(1, Artisan::call(self::COMMAND, ['--controls' => true, '--operation' => 'deposits']));
        self::assertStringContainsString('Unknown operation', Artisan::output());
    }

    public function test_strict_gives_warnings_their_own_exit_code(): void
    {
        config(['real_money.enabled' => false]);

        // A closed gate has no blocking failure but does have advisories: the KYC
        // custody disks, the sync queue and the missing field-encryption key.
        $plain = $this->auditJson();
        self::assertSame(0, $plain['exit']);
        self::assertGreaterThan(0, $plain['report']['summary']['warn']);

        $strict = $this->auditJson(['--strict' => true]);
        self::assertSame(2, $strict['exit'], 'Warnings under --strict did not get their own exit code.');
        self::assertTrue($strict['report']['ready'], '--strict changed the verdict instead of only the exit code.');

        // --strict must not soften a real failure into a warning: blocking still
        // wins, and still exits 1.
        config(['real_money.enabled' => true]);
        self::assertSame(1, $this->auditJson(['--strict' => true])['exit']);
    }

    public function test_the_controls_option_prints_the_control_surface_and_runs_no_check(): void
    {
        $before = $this->moneyRowCounts();

        $all = $this->auditJson(['--controls' => true]);
        self::assertSame(0, $all['exit']);
        self::assertNull($all['report']['operation']);
        self::assertTrue($all['report']['known']);
        self::assertSame(RealMoneyTechnicalReadinessService::CHECKS, $all['report']['required_checks']);

        // The master switch and the amount bound enforce out of the box; every new
        // control reports. That is the single most important line an operator reads
        // before wiring this into a money path.
        self::assertSame(MoneyOperationControlService::MODE_ENFORCE, $all['report']['modes']['master_switch']);
        self::assertSame(MoneyOperationControlService::MODE_ENFORCE, $all['report']['modes']['amount_bounds']);
        self::assertSame(MoneyOperationControlService::MODE_REPORT, $all['report']['modes']['technical_readiness']);
        self::assertSame(MoneyOperationControlService::MODE_REPORT, $all['report']['modes']['provider_lane']);
        self::assertSame(MoneyOperationControlService::MODE_REPORT, $all['report']['modes']['license']);

        $deposit = $this->auditJson(['--controls' => true, '--operation' => 'deposit']);
        self::assertSame(0, $deposit['exit']);
        self::assertSame('deposit', $deposit['report']['operation']);
        self::assertTrue($deposit['report']['lane_required']);
        self::assertSame(['min' => 'finance.deposit.min', 'max' => 'finance.deposit.max'], $deposit['report']['bound_sources']);
        self::assertSame((string) config('finance.deposit.min'), $deposit['report']['bounds']['min']);

        // A bet dispatches no provider, so the surface says so rather than
        // implying a rail it will never use.
        $bet = $this->auditJson(['--controls' => true, '--operation' => 'bet']);
        self::assertFalse($bet['report']['lane_required']);
        self::assertNotContains(RealMoneyTechnicalReadinessService::CHECK_PROVIDER_LANES, $bet['report']['required_checks']);

        // A payout is bounded by the four-eyes approval threshold that
        // PayoutApprovalService owns, so the bound is declared unbounded rather
        // than copied — one owner per rule.
        $payout = $this->auditJson(['--controls' => true, '--operation' => 'payout']);
        self::assertSame(['min' => null, 'max' => null], $payout['report']['bounds']);
        self::assertTrue($payout['report']['lane_required']);

        self::assertSame(0, Artisan::call(self::COMMAND, ['--controls' => true]));
        $surface = Artisan::output();
        self::assertStringContainsString('Money operation controls', $surface);
        self::assertStringContainsString('report', $surface);
        self::assertStringContainsString('enforce', $surface);
        self::assertStringContainsString('Deciding checks', $surface);

        self::assertSame(0, Artisan::call(self::COMMAND, ['--controls' => true, '--operation' => 'deposit']));
        $depositSurface = Artisan::output();
        self::assertStringContainsString('finance.deposit.min', $depositSurface, 'The bound source was not named, so an operator cannot see where the number comes from.');
        self::assertStringContainsString('finance.deposit.max', $depositSurface);
        self::assertStringContainsString('required for this operation', $depositSurface);

        // --controls runs no check at all, so it cannot write either.
        self::assertSame($before, $this->moneyRowCounts(), '--controls wrote to the database.');
    }

    public function test_all_operations_reports_one_verdict_per_operation(): void
    {
        $this->license();

        $exit = Artisan::call(self::COMMAND, ['--all-operations' => true, '--json' => true]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(1, $exit, 'Every operation was reported ready on a deployment with no provider lane.');
        self::assertTrue($report['real_money_enabled']);
        self::assertSame(RealMoneyTechnicalReadinessService::OPERATIONS, array_keys($report['operations']));

        foreach ($report['operations'] as $operation => $verdict) {
            self::assertSame($operation, $verdict['operation']);
            self::assertIsBool($verdict['ready']);
            self::assertIsArray($verdict['blocking']);
            foreach (['pass', 'warn', 'fail', 'skip'] as $key) {
                self::assertArrayHasKey($key, $verdict['summary']);
            }
        }

        // The point of the view: which operations may be turned on. A licensed
        // deployment with a seeded chart can bet and convert, and cannot deposit,
        // withdraw or pay out until a rail exists and the KYC custody disks are
        // declared.
        self::assertTrue($report['operations']['bet']['ready']);
        self::assertTrue($report['operations']['conversion']['ready']);
        self::assertFalse($report['operations']['deposit']['ready']);
        self::assertFalse($report['operations']['withdrawal']['ready']);
        self::assertFalse($report['operations']['payout']['ready']);

        // The text form exits 1 for the same reason the JSON form does: three of
        // the five operations are not ready on a deployment with no provider lane
        // and no KYC custody disks.
        self::assertSame(1, Artisan::call(self::COMMAND, ['--all-operations' => true]));
        $text = Artisan::output();
        self::assertStringContainsString('per operation', $text);
        self::assertStringContainsString('READY', $text);
        self::assertStringContainsString('NOT READY', $text);
        self::assertStringContainsString('bet', $text);
        self::assertStringContainsString('deposit', $text);
    }

    public function test_every_variant_of_the_audit_is_read_only(): void
    {
        $this->license();
        $before = $this->moneyRowCounts();

        Artisan::call(self::COMMAND);
        Artisan::call(self::COMMAND, ['--json' => true]);
        Artisan::call(self::COMMAND, ['--strict' => true]);
        Artisan::call(self::COMMAND, ['--operation' => 'deposit']);
        Artisan::call(self::COMMAND, ['--operation' => 'payout']);
        Artisan::call(self::COMMAND, ['--all-operations' => true]);
        Artisan::call(self::COMMAND, ['--controls' => true]);
        Artisan::call(self::COMMAND, ['--operation' => 'deposits']);

        // A readiness audit that writes cannot be run twice without changing what
        // it measures, and cannot be pointed at a production database at all
        // without somebody first having to reason about its side effects.
        self::assertSame($before, $this->moneyRowCounts(), 'money:technical-readiness wrote to the database.');
    }

    public function test_the_audit_never_prints_a_configuration_value_it_was_asked_to_keep_quiet(): void
    {
        // A credential-shaped value in the licence signature and in a gateway
        // credential. Nothing in the output may contain either: these lines end up
        // in CI transcripts, deploy logs and incident tickets.
        config([
            'real_money.enabled' => true,
            'real_money.license.signature' => 'SUPER-SECRET-SIGNATURE-DO-NOT-PRINT',
            'payment.gateways.stripe.enabled' => true,
            'payment.gateways.stripe.secret' => 'sk_test_SUPER_SECRET_DO_NOT_PRINT',
            'payment.gateways.stripe.key' => 'pk_test_PUBLISHABLE_DO_NOT_PRINT',
        ]);
        app()->bind(LicenseService::class, static fn ($app): LicenseService =>
            new LicenseService((array) $app['config']->get('real_money', [])));

        foreach ([[], ['--all-operations' => true], ['--controls' => true], ['--operation' => 'deposit']] as $options) {
            Artisan::call(self::COMMAND, $options);
            $output = Artisan::output();

            self::assertStringNotContainsString('SUPER-SECRET-SIGNATURE-DO-NOT-PRINT', $output);
            self::assertStringNotContainsString('sk_test_SUPER_SECRET_DO_NOT_PRINT', $output);
            self::assertStringNotContainsString('pk_test_PUBLISHABLE_DO_NOT_PRINT', $output);
            self::assertStringNotContainsString('sk_test_', $output);
        }
    }

    public function test_the_existing_payments_readiness_contract_is_unchanged(): void
    {
        // A regression guard for the command that was already published. Adding an
        // audit must not change the output a deploy script already parses.
        self::assertSame(1, Artisan::call('payments:readiness', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        foreach (['real_money_enabled', 'ready', 'gateways', 'thai_rails'] as $key) {
            self::assertArrayHasKey($key, $report, 'payments:readiness dropped "'.$key.'" from its published JSON.');
        }

        self::assertTrue($report['real_money_enabled']);
        self::assertIsList($report['gateways']);
        self::assertIsList($report['thai_rails']);

        foreach ($report['gateways'] as $gateway) {
            self::assertSame(['gateway', 'enabled', 'ready', 'status', 'issues', 'currencies', 'countries'], array_keys($gateway));
        }
        foreach ($report['thai_rails'] as $check) {
            self::assertSame(['id', 'status', 'detail'], array_keys($check));
        }

        // The two commands answer different questions and must not be conflated:
        // payments:readiness is the provider and Thai-rail gate, this one is the
        // money-path technical gate. Both exit 1 here, for different reasons.
        self::assertArrayNotHasKey('blocking', $report);
        self::assertArrayHasKey('blocking', $this->auditJson()['report']);
    }
}
