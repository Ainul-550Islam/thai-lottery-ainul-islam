<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Support\Ops\CheckResult;
use App\Support\Ops\ProductionPreflight;
use App\Support\Ops\ThaiRailsChecks;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * The CROSS-LANE contract of the Thai payment rails.
 *
 * `ThaiRailsPreflightCommandTest` drives the `thai-rails:preflight` command and
 * so proves what an operator sees. This suite proves the agreements underneath
 * it, which are the ones that break silently:
 *
 *   1. WHICH lanes `run()` reports, under WHICH names, in WHICH order. A deploy
 *      gate parses `PASS [truemoney] disabled (skipped)` out of a transcript, so
 *      a renamed or reordered lane does not fail a test that only checks "it
 *      printed something" - it fails a release, later, in production.
 *   2. That `promptpay` is NOT one of them. It is a public check but not part of
 *      `run()`, and folding it in would add a fourth line to a transcript a gate
 *      already parses positionally.
 *   3. That a DARK lane reports PASS and never FAIL. Reporting an un-bought rail
 *      as broken fails every deployment that has not purchased it, which is how
 *      a safety check gets switched off by the people it was blocking.
 *   4. That an ENABLED-but-unconfigured lane reports FAIL. The mirror image of
 *      (3), and the more dangerous direction: a lane that is on and not ready
 *      must never be waved through because "skipped" and "not configured" were
 *      allowed to mean the same thing.
 *   5. That a message describes the SHAPE of what was found and never the value.
 *      These strings end up in CI transcripts, deploy logs and incident tickets.
 *   6. That ProductionPreflight's severity split follows (3) and (4): a skipped
 *      lane is advisory and cannot block, a live lane is blocking and must.
 *
 * Like ProductionPreflightTest this extends the framework's TestCase rather than
 * Tests\TestCase: the shared base migrates the database on every boot, and every
 * case here is a pure configuration gate with no schema involved.
 */
final class ThaiRailsCrossLaneChecksTest extends BaseTestCase
{
    /**
     * The lanes `run()` reports, in order. This is a published contract, not a
     * detail: the preflight command prints one line per entry and a deploy gate
     * parses those lines.
     */
    private const RUN_LANES = ['truemoney', 'slip_verification', 'thai_payout'];

    /**
     * Every rail this suite knows about, including the one that is deliberately
     * outside `run()`.
     */
    private const ALL_LANES = ['truemoney', 'slip_verification', 'thai_payout', 'promptpay'];

    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing here renders a view, and the preflight inspects compiled assets.
        $this->withoutVite();

        // Start every case from "all rails dark" so a test that enables one rail
        // cannot inherit state from the one before it.
        config([
            'truemoney.enabled' => false,
            'slip_verification.enabled' => false,
            'thai_payout.enabled' => false,
            'payment.gateways.promptpay.enabled' => false,
        ]);
    }

    private function checks(): ThaiRailsChecks
    {
        return app(ThaiRailsChecks::class);
    }

    /**
     * Assert a lane's verdict and return the message for further inspection.
     */
    private function assertLane(CheckResult $check, string $name, string $status): string
    {
        $this->assertSame($name, $check->name, 'lane name is part of the parsed transcript');
        $this->assertSame($status, $check->status);

        return $check->message;
    }

    // ── 1. the lane set ─────────────────────────────────────────────────────

    public function test_run_reports_exactly_the_three_thai_rail_lanes_in_contract_order(): void
    {
        $names = array_map(
            static fn (CheckResult $check): string => $check->name,
            $this->checks()->run(),
        );

        $this->assertSame(self::RUN_LANES, $names);
    }

    public function test_promptpay_is_a_public_check_but_is_not_part_of_run(): void
    {
        $this->assertNotContains('promptpay', array_map(
            static fn (CheckResult $check): string => $check->name,
            $this->checks()->run(),
        ));

        // It is still reachable on its own, so the omission is a contract and not
        // an oversight: promptpay is a deposit rail whose merchant identity is
        // worth checking before a payout, but it is not one of the three lanes
        // the preflight transcript is parsed for.
        $check = $this->checks()->promptpay();
        $this->assertInstanceOf(CheckResult::class, $check);
        $this->assertSame('promptpay', $check->name);
    }

    public function test_every_lane_reports_one_of_the_four_published_verdicts(): void
    {
        $verdicts = [CheckResult::PASS, CheckResult::WARN, CheckResult::FAIL, CheckResult::SKIP];

        $checks = [...$this->checks()->run(), $this->checks()->promptpay()];
        $this->assertCount(4, $checks);

        foreach ($checks as $check) {
            $this->assertContains($check->status, $verdicts, $check->name.' used an unpublished verdict');
            $this->assertNotSame('', trim($check->message), $check->name.' reported no message');
        }
    }

    // ── 2. a dark lane is not a failing lane ────────────────────────────────

    public function test_disabled_lanes_pass_and_carry_the_skip_in_the_message(): void
    {
        foreach ($this->checks()->run() as $check) {
            $this->assertLane($check, $check->name, CheckResult::PASS);
            $this->assertSame('disabled (skipped)', $check->message);
        }

        $this->assertLane($this->checks()->promptpay(), 'promptpay', CheckResult::PASS);
    }

    public function test_disabled_lanes_record_no_failures_so_the_preflight_status_is_not_fail(): void
    {
        $report = (new ProductionPreflight(production: true, withConnectivity: false))->run();

        $railRows = array_values(array_filter(
            $report['checks'],
            static fn (array $row): bool => str_starts_with($row['id'], 'thai_rails.'),
        ));

        $this->assertCount(3, $railRows, 'every run() lane must reach the report');
        foreach ($railRows as $row) {
            $this->assertSame(CheckResult::PASS, $row['status'], $row['id'].' failed while dark');
        }
    }

    // ── 3. an enabled-but-unconfigured lane must fail closed ────────────────

    public function test_enabled_truemoney_without_configuration_fails_closed(): void
    {
        config(['truemoney.enabled' => true]);

        $message = $this->assertLane($this->checks()->truemoney(), 'truemoney', CheckResult::FAIL);
        $this->assertStringContainsString('HTTPS endpoint', $message);
    }

    public function test_enabled_slip_verification_without_configuration_fails_closed(): void
    {
        config(['slip_verification.enabled' => true]);

        $message = $this->assertLane($this->checks()->slips(), 'slip_verification', CheckResult::FAIL);
        $this->assertStringContainsString('receiving accounts', $message);
    }

    public function test_enabled_thai_payout_without_configuration_fails_closed(): void
    {
        config(['thai_payout.enabled' => true]);

        $message = $this->assertLane($this->checks()->payouts(), 'thai_payout', CheckResult::FAIL);
        $this->assertStringContainsString('bank credentials', $message);
    }

    public function test_enabled_promptpay_without_a_merchant_identifier_fails_closed(): void
    {
        config([
            'payment.gateways.promptpay.enabled' => true,
            'payment.gateways.promptpay.merchant_id' => null,
        ]);

        $message = $this->assertLane($this->checks()->promptpay(), 'promptpay', CheckResult::FAIL);
        $this->assertStringContainsString('PROMPTPAY_MERCHANT_ID', $message);
    }

    // ── 4. partial configuration must not be mistaken for readiness ─────────

    public function test_truemoney_missing_only_the_unofficial_api_acknowledgement_still_fails(): void
    {
        // Everything else about this lane is correct. The acknowledgement is the
        // operator's explicit statement that they know this is not an official
        // API, so it is the one field that cannot be inferred from a well-formed
        // configuration - and a check that passed without it would be a check
        // that had stopped asking the question it exists to ask.
        config([
            'truemoney.enabled' => true,
            'truemoney.acknowledged_unofficial_api' => false,
            'truemoney.base_url' => 'https://gift.truemoney.test',
            'truemoney.allowed_host' => 'gift.truemoney.test',
            'truemoney.merchant_mobile' => '0812345678',
            'truemoney.redeem_path' => '/v1/redeem/{voucher_code}',
            'truemoney.timeout_seconds' => 10,
            'truemoney.per_user_daily_count' => 3,
            'truemoney.min_amount' => '10.00',
            'truemoney.max_amount' => '5000.00',
            'truemoney.per_user_daily_amount' => '20000.00',
        ]);

        $this->assertLane($this->checks()->truemoney(), 'truemoney', CheckResult::FAIL);

        // And flipping only that one field makes the same lane pass, which is
        // what proves the acknowledgement was the thing being refused.
        config(['truemoney.acknowledged_unofficial_api' => true]);
        $this->assertLane($this->checks()->truemoney(), 'truemoney', CheckResult::PASS);
    }

    public function test_slip_verification_with_a_provider_but_no_receiving_accounts_fails(): void
    {
        config([
            'slip_verification.enabled' => true,
            'slip_verification.provider' => 'easyslip',
            'slip_verification.max_age_minutes' => 30,
            'slip_verification.receiving_accounts' => [],
            'slip_verification.providers.easyslip.base_url' => 'https://api.easyslip.com',
            'slip_verification.providers.easyslip.verify_path' => '/v1/verify',
            'slip_verification.providers.easyslip.token' => 'easyslip-token-sentinel',
            'slip_verification.providers.easyslip.timeout' => 10,
        ]);

        $this->assertLane($this->checks()->slips(), 'slip_verification', CheckResult::FAIL);
    }

    public function test_thai_payout_whose_default_bank_is_not_among_the_enabled_banks_fails(): void
    {
        // SCB is fully enabled, but the rail is told to default to KBank, which is
        // off. A check that only asked "is some bank configured" would pass this,
        // and the first payout would then be routed to a bank with no credentials.
        config([
            'thai_payout.enabled' => true,
            'thai_payout.default_bank' => 'kbank',
            'thai_payout.banks.kbank.enabled' => false,
            'thai_payout.banks.scb.enabled' => true,
            'thai_payout.min_amount' => '100.00',
            'thai_payout.max_amount' => '50000.00',
            'thai_payout.daily_limit' => '200000.00',
            'thai_payout.poll_after_seconds' => 30,
            'thai_payout.poll_max_age_hours' => 24,
            'thai_payout.callback_tolerance_seconds' => 300,
        ]);

        $this->assertLane($this->checks()->payouts(), 'thai_payout', CheckResult::FAIL);
    }

    public function test_slip_verification_with_an_expired_window_of_zero_minutes_fails(): void
    {
        // max_age_minutes is what makes a replayed slip unacceptable, so a
        // provider configured with everything but a usable age window is not
        // ready - it is a lane that would accept a slip from any point in history.
        config([
            'slip_verification.enabled' => true,
            'slip_verification.provider' => 'slipok',
            'slip_verification.max_age_minutes' => 0,
            'slip_verification.receiving_accounts' => [[
                'bank' => 'SCB',
                'account_last_digits' => '1234',
                'name' => 'Thai Lottery Co',
            ]],
            'slip_verification.providers.slipok.base_url' => 'https://api.slipok.com',
            'slip_verification.providers.slipok.verify_path' => '/v1/verify',
            'slip_verification.providers.slipok.branch_id' => 'branch-sentinel',
            'slip_verification.providers.slipok.api_key' => 'slipok-key-sentinel',
            'slip_verification.providers.slipok.timeout' => 10,
        ]);

        $this->assertLane($this->checks()->slips(), 'slip_verification', CheckResult::FAIL);
    }

    // ── 5. promptpay readiness needs the merchant identity and nothing more ─

    public function test_promptpay_is_ready_with_only_a_merchant_identifier_configured(): void
    {
        // No webhook secret, no signing key. PromptPay deposits are confirmed by
        // the operator matching a payment reference, so a check that demanded a
        // secret here would report a working rail as un-deployable - which is
        // exactly what the removed `missing_bank_webhook_secret` gate did.
        config([
            'payment.gateways.promptpay.enabled' => true,
            'payment.gateways.promptpay.merchant_id' => '0812345678',
        ]);

        $message = $this->assertLane($this->checks()->promptpay(), 'promptpay', CheckResult::PASS);
        $this->assertStringContainsString('merchant identifier format valid', $message);
    }

    public function test_promptpay_accepts_every_recipient_identifier_shape_the_generator_accepts(): void
    {
        // The check delegates to PromptPayQrGeneratorService, so the shapes that
        // are valid here are exactly the shapes that can be paid: a 10-digit
        // mobile, a 13-digit national or tax ID, and a 15-digit e-wallet ID.
        foreach (['0812345678', '1234567890123', '123456789012345'] as $identifier) {
            config([
                'payment.gateways.promptpay.enabled' => true,
                'payment.gateways.promptpay.merchant_id' => $identifier,
            ]);

            $this->assertLane(
                $this->checks()->promptpay(),
                'promptpay',
                CheckResult::PASS,
            );
        }
    }

    public function test_promptpay_rejects_a_malformed_merchant_identifier(): void
    {
        foreach (['not-a-merchant-id', '081234567', '12345678901234'] as $identifier) {
            config([
                'payment.gateways.promptpay.enabled' => true,
                'payment.gateways.promptpay.merchant_id' => $identifier,
            ]);

            $this->assertLane($this->checks()->promptpay(), 'promptpay', CheckResult::FAIL);
        }
    }

    public function test_promptpay_rejects_a_non_string_merchant_identifier(): void
    {
        // `merchant_id` arrives from env() and can be an int if a template omits
        // the quotes. The check must refuse that rather than coerce it, because a
        // coerced identifier that happens to be 10 digits would route money to
        // somebody the operator never named.
        config([
            'payment.gateways.promptpay.enabled' => true,
            'payment.gateways.promptpay.merchant_id' => 812345678,
        ]);

        $this->assertLane($this->checks()->promptpay(), 'promptpay', CheckResult::FAIL);
    }

    // ── 6. a message describes the shape, never the value ───────────────────

    public function test_no_lane_message_leaks_a_configured_value_or_secret(): void
    {
        // Every sentinel is planted in a config key the checks actually READ, and
        // is then asserted absent from every message. Planting one under an
        // invented key would prove nothing: the check never sees it, so it could
        // never leak it either. TrueMoney carries no secret of its own - the
        // merchant is identified by mobile number, which is itself the sensitive
        // value, so the mobile is the sentinel for that lane.
        $sentinels = [
            'EASYSLIP-TOKEN-SENTINEL-7a1d',   // slip_verification.providers.easyslip.token
            'SLIPOK-KEY-SENTINEL-c42b',       // slip_verification.providers.slipok.api_key
            'branch-sentinel-4417',           // slip_verification.providers.slipok.branch_id
            '0899999999',                     // truemoney.merchant_mobile + promptpay.merchant_id
            'https://gift.truemoney-leak.test', // truemoney.base_url
        ];

        config([
            'truemoney.enabled' => true,
            'truemoney.base_url' => $sentinels[4],
            'truemoney.allowed_host' => 'gift.truemoney-leak.test',
            'truemoney.merchant_mobile' => $sentinels[3],
            'truemoney.redeem_path' => '/v1/redeem/{voucher_code}',
            'slip_verification.enabled' => true,
            'slip_verification.provider' => 'easyslip',
            'slip_verification.providers.easyslip.token' => $sentinels[0],
            'slip_verification.providers.slipok.api_key' => $sentinels[1],
            'slip_verification.providers.slipok.branch_id' => $sentinels[2],
            'thai_payout.enabled' => true,
            'payment.gateways.promptpay.enabled' => true,
            'payment.gateways.promptpay.merchant_id' => $sentinels[3],
        ]);

        $checks = [...$this->checks()->run(), $this->checks()->promptpay()];

        foreach ($checks as $check) {
            foreach ($sentinels as $sentinel) {
                $this->assertStringNotContainsString(
                    $sentinel,
                    $check->message,
                    $check->name.' interpolated a configured value into a message that is printed to CI transcripts and deploy logs',
                );
            }
        }
    }

    // ── 7. severity: a skipped lane advises, a live lane blocks ─────────────

    public function test_a_disabled_lane_is_advisory_in_the_preflight_report(): void
    {
        $report = (new ProductionPreflight(production: true, withConnectivity: false))->run();

        foreach (self::RUN_LANES as $lane) {
            $row = $this->preflightRow($report, 'thai_rails.'.$lane);
            $this->assertSame('advisory', $row['severity'], 'a rail nobody has bought must not block a release');
            $this->assertSame(CheckResult::PASS, $row['status']);
        }
    }

    public function test_an_enabled_lane_is_blocking_in_the_preflight_report(): void
    {
        config(['truemoney.enabled' => true]);

        $report = (new ProductionPreflight(production: true, withConnectivity: false))->run();

        $row = $this->preflightRow($report, 'thai_rails.truemoney');
        $this->assertSame('blocking', $row['severity'], 'a live rail that is not configured must block');
        $this->assertSame(CheckResult::FAIL, $row['status']);

        // The other two are still dark, so they are still advisory: the split is
        // per-lane and follows each lane's own state.
        $this->assertSame('advisory', $this->preflightRow($report, 'thai_rails.slip_verification')['severity']);
        $this->assertSame('advisory', $this->preflightRow($report, 'thai_rails.thai_payout')['severity']);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function preflightRow(array $report, string $id): array
    {
        foreach ($report['checks'] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        $this->fail("preflight report has no row for {$id}");
    }

    // ── 8. the rails are documented, and the retired gate stays retired ─────

    public function test_the_bank_webhook_secret_gate_is_not_required_anywhere(): void
    {
        // PromptPay and the Thai bank rails are confirmed by matching a payment
        // reference, not by verifying an inbound webhook signature, so a readiness
        // check that demanded a webhook secret reported a working deployment as
        // un-deployable. The gate was removed; this keeps it from coming back in
        // any of the four places that could reintroduce it.
        foreach (['app', 'config', 'tests', 'resources/lang', 'routes'] as $directory) {
            $root = dirname(__DIR__, 3).'/'.$directory;
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'json', 'yml', 'yaml'], true)) {
                    continue;
                }
                // This test file necessarily names the string, so it is excluded.
                if ($file->getFilename() === 'ThaiRailsCrossLaneChecksTest.php') {
                    continue;
                }

                // Comments are stripped before scanning, because the string is
                // ALLOWED to appear in one: ProviderReadinessService carries a NOTE
                // explaining why promptpay readiness deliberately does not demand a
                // bank webhook secret, and naming the retired code there is what
                // stops somebody re-adding it. Asserting on the raw file would
                // forbid the explanation that prevents the regression - the same
                // trap CssJsParityTest fell into when a docblock describing a
                // removed behaviour failed the assertion enforcing its absence.
                // What must not exist is a LIVE gate, and a live gate is code.
                $contents = $file->getExtension() === 'php'
                    ? php_strip_whitespace($file->getPathname())
                    : (string) file_get_contents($file->getPathname());

                $this->assertStringNotContainsString(
                    'missing_bank_webhook_secret',
                    $contents,
                    $file->getPathname().' reintroduces the retired webhook-secret readiness gate',
                );
            }
        }
    }

    public function test_every_thai_rail_environment_key_is_documented_in_all_three_templates(): void
    {
        // The rails are configured entirely through env(), so a key that is not
        // written down cannot be set: an operator copying a template would have no
        // way to discover the rail exists. This is the env() half of the same
        // contract EnvTemplateCompletenessTest asserts over the whole app, pinned
        // here per rail family so that removing one family is a visible decision.
        $families = [
            'SLIP_' => 'slip_verification',
            'EASYSLIP_' => 'slip_verification',
            'SLIPOK_' => 'slip_verification',
            'TRUEMONEY_' => 'truemoney',
            'THAI_PAYOUT_' => 'thai_payout',
        ];

        $root = dirname(__DIR__, 3);
        $templates = ['.env.example', '.env.ci', '.env.production.example'];

        foreach ($families as $prefix => $configFile) {
            $source = (string) file_get_contents($root.'/config/'.$configFile.'.php');
            preg_match_all('/\benv\(\s*[\'"]('.$prefix.'[A-Z0-9_]*)[\'"]/', $source, $matches);
            $keys = array_values(array_unique($matches[1]));

            $this->assertNotSame([], $keys, 'config/'.$configFile.'.php reads no '.$prefix.'* key; the family has moved or been renamed');

            foreach ($templates as $template) {
                $contents = (string) file_get_contents($root.'/'.$template);
                preg_match_all('/^\s*([A-Z][A-Z0-9_]*)=/m', $contents, $documented);

                $this->assertSame(
                    [],
                    array_values(array_diff($keys, $documented[1])),
                    $template.' does not document every '.$prefix.'* key read by config/'.$configFile.'.php',
                );
            }
        }
    }

    public function test_the_check_class_is_the_single_source_of_the_lane_names(): void
    {
        // The command, the preflight report and this suite must all read the same
        // names from the same place. If a name were duplicated as a literal in the
        // command, the transcript and the report could drift apart without either
        // failing.
        $command = (string) file_get_contents(dirname(__DIR__, 3).'/app/Console/Commands/Ops/ThaiRailsPreflightCommand.php');

        foreach (self::RUN_LANES as $lane) {
            $this->assertStringNotContainsString(
                "'".$lane."'",
                $command,
                'the preflight command hard-codes a lane name instead of printing whatever ThaiRailsChecks reports',
            );
        }

        $this->assertStringContainsString('ThaiRailsChecks', $command);
    }

    public function test_all_lanes_are_covered_by_the_suite_itself(): void
    {
        // A guard against this suite quietly rotting: if a lane is added to
        // ThaiRailsChecks it must be added to ALL_LANES here, and then every case
        // above that iterates the lanes picks it up automatically.
        $this->assertSame(
            [...self::RUN_LANES, 'promptpay'],
            self::ALL_LANES,
        );

        $reported = array_map(
            static fn (CheckResult $check): string => $check->name,
            [...$this->checks()->run(), $this->checks()->promptpay()],
        );
        sort($reported);
        $expected = self::ALL_LANES;
        sort($expected);
        $this->assertSame($expected, $reported);
    }
}
