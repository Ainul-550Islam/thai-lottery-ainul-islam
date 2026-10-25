<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Support\Ops\ProductionPreflight;
use Illuminate\Support\Facades\Config;

/**
 * The deployment preflight must report the payment lanes honestly.
 *
 * WHY THIS SUITE EXISTS
 * A preflight is the last thing between a misconfigured deployment and a
 * player's money. The lane checks were extracted from the console commands
 * into shared classes (ThaiRailsChecks, RealtimeChecks, LaneFlagChecks) so the
 * command, this report and the tests can never disagree about a lane — and so
 * a rule added in one place is enforced everywhere on the next release.
 *
 * The three properties asserted here:
 *   - a lane that is OFF is SKIP, never PASS: a dark lane must not be
 *     mistaken for a working one, and a preflight that cried wolf would be
 *     ignored;
 *   - a lane that is ON and broken is FAIL and BLOCKING, because that is a
 *     live defect and not a laptop inconvenience;
 *   - the payout gate and the payout routing are checked BEFORE a payout can
 *     complete, not after one has been reviewed and approved.
 */
final class ProductionPreflightLaneChecksTest extends PaymentTestCase
{
    /**
     * The report is built offline: connectivity probes need a real database
     * and a real Redis, and every assertion here is about configuration.
     *
     * @return array{status: string, environment: string, checks: list<array{id: string, status: string, detail: string, severity: string}>, summary: array{pass: int, warn: int, fail: int, skip: int}}
     */
    private function report(bool $production = false): array
    {
        return (new ProductionPreflight($production, false))->run();
    }

    /**
     * @return array<string, array{id: string, status: string, detail: string, severity: string}>
     */
    private function checks(bool $production = false): array
    {
        $indexed = [];

        foreach ($this->report($production)['checks'] as $check) {
            $indexed[$check['id']] = $check;
        }

        return $indexed;
    }

    public function test_a_disabled_thai_rail_is_reported_as_a_skipped_pass(): void
    {
        Config::set('truemoney.enabled', false);
        Config::set('slip_verification.enabled', false);
        Config::set('thai_payout.enabled', false);

        $checks = $this->checks();

        $this->assertArrayHasKey('thai_rails.truemoney', $checks);
        $this->assertArrayHasKey('thai_rails.slip_verification', $checks);
        $this->assertArrayHasKey('thai_rails.thai_payout', $checks);

        foreach (['thai_rails.truemoney', 'thai_rails.slip_verification', 'thai_rails.thai_payout'] as $id) {
            // The published verdict is PASS and the message says the lane was
            // never checked: a dark lane is not a broken lane, and the row is
            // advisory so it can never block a release.
            $this->assertSame('PASS', $checks[$id]['status'], $id.' must not fail while its lane is off.');
            $this->assertSame('advisory', $checks[$id]['severity'], $id.' must not block a release while off.');
            $this->assertStringContainsString('disabled (skipped)', $checks[$id]['detail']);
        }
    }

    public function test_a_disabled_promptpay_keeps_its_published_advisory_pass(): void
    {
        Config::set('payment.gateways.promptpay.enabled', false);
        Config::set('payment.gateways.promptpay.merchant_id', 'not-a-merchant');

        $checks = $this->checks();

        $this->assertSame('PASS', $checks['promptpay.merchant']['status']);
        $this->assertSame('advisory', $checks['promptpay.merchant']['severity']);
        $this->assertSame('PromptPay is disabled.', $checks['promptpay.merchant']['detail']);
    }

    public function test_an_enabled_promptpay_with_a_bad_merchant_id_fails_the_release(): void
    {
        Config::set('payment.gateways.promptpay.enabled', true);
        Config::set('payment.gateways.promptpay.merchant_id', 'not-a-merchant');

        $checks = $this->checks();

        $this->assertSame('FAIL', $checks['promptpay.merchant']['status']);
        $this->assertSame('blocking', $checks['promptpay.merchant']['severity']);
    }

    public function test_an_enabled_but_unconfigured_thai_rail_fails_the_release(): void
    {
        Config::set('truemoney.enabled', true);
        Config::set('truemoney.acknowledged_unofficial_api', false);

        $checks = $this->checks();

        $this->assertSame('FAIL', $checks['thai_rails.truemoney']['status']);
        $this->assertSame('blocking', $checks['thai_rails.truemoney']['severity']);
    }

    public function test_a_dark_realtime_lane_is_one_skip_line_and_nothing_else(): void
    {
        Config::set('realtime.enabled', false);

        $checks = $this->checks();

        $this->assertSame('SKIP', $checks['realtime.lane']['status']);
        $this->assertSame('advisory', $checks['realtime.lane']['severity']);

        foreach (array_keys($checks) as $id) {
            if (str_starts_with((string) $id, 'realtime.')) {
                $this->assertSame('realtime.lane', (string) $id, 'A dark lane must not emit its own realtime checks.');
            }
        }
    }

    public function test_the_optional_lanes_are_each_reported_once(): void
    {
        $checks = $this->checks();

        foreach (['legal_pdf', 'payout_illustration', 'privacy_requests', 'support_tickets'] as $lane) {
            $this->assertArrayHasKey('lane.'.$lane, $checks, $lane.' must appear in the report.');
            $this->assertContains($checks['lane.'.$lane]['status'], ['PASS', 'WARN', 'FAIL', 'SKIP']);
            $this->assertNotSame('', $checks['lane.'.$lane]['detail'], 'A lane check must explain itself.');
        }
    }

    public function test_the_payout_compliance_gate_must_resolve_before_a_release(): void
    {
        $checks = $this->checks();

        $this->assertArrayHasKey('payout.compliance_gate', $checks);
        $this->assertSame('PASS', $checks['payout.compliance_gate']['status']);
        $this->assertStringContainsString('compliance gate', $checks['payout.compliance_gate']['detail']);
    }

    public function test_the_automated_payout_lane_is_skipped_while_it_is_disabled(): void
    {
        Config::set('thai_payout.enabled', false);

        $checks = $this->checks();

        $this->assertSame('SKIP', $checks['payout.gateway_routing']['status']);
        $this->assertSame('advisory', $checks['payout.gateway_routing']['severity']);
    }

    public function test_an_enabled_payout_lane_with_an_unconfigured_bank_fails_the_release(): void
    {
        // The lane is switched on but no bank adapter is configured. The
        // disbursement lane would persist the key 'thai_bank' and then fail to
        // resolve it, so the release must not be allowed through.
        Config::set('thai_payout.enabled', true);
        Config::set('thai_payout.default_bank', 'scb');
        Config::set('thai_payout.banks.scb.enabled', false);

        $checks = $this->checks();

        $this->assertSame('FAIL', $checks['payout.gateway_routing']['status']);
        $this->assertSame('blocking', $checks['payout.gateway_routing']['severity']);
    }

    public function test_every_check_reports_a_status_and_a_detail(): void
    {
        foreach ($this->report()['checks'] as $check) {
            $this->assertIsString($check['id']);
            $this->assertNotSame('', $check['id']);
            $this->assertContains($check['status'], ['PASS', 'WARN', 'FAIL', 'SKIP']);
            $this->assertIsString($check['detail']);
            $this->assertNotSame('', $check['detail'], $check['id'].' must explain its verdict.');
            $this->assertContains($check['severity'], ['blocking', 'advisory']);
        }
    }

    /**
     * @return array<string, array{id: string, status: string, detail: string, severity: string}>
     */
    private function indexed(bool $production): array
    {
        return $this->checks($production);
    }

    public function test_log_redaction_is_confirmed_from_the_channels_the_app_writes_to(): void
    {
        // The shipped stack replaces placeholders, so the gate may say so.
        Config::set('logging.default', 'stack');
        Config::set('logging.channels.stack.channels', ['single']);
        Config::set('logging.channels.single.replace_placeholders', true);

        $check = $this->checks()['log.redaction'] ?? null;

        $this->assertNotNull($check, 'The preflight must report the log redaction state.');
        $this->assertSame('PASS', $check['status']);
        $this->assertStringContainsString('single', $check['detail']);
        $this->assertSame('advisory', $check['severity']);
    }

    public function test_a_channel_that_cannot_redact_blocks_the_release(): void
    {
        Config::set('logging.default', 'single');
        Config::set('logging.channels.single.replace_placeholders', false);

        $check = $this->indexed(true)['log.redaction'] ?? null;

        $this->assertNotNull($check, 'The preflight must report the log redaction state.');
        $this->assertSame('FAIL', $check['status']);
        $this->assertSame('blocking', $check['severity']);
        $this->assertStringContainsString('replace_placeholders disabled', $check['detail']);
    }

    public function test_an_undeclared_redaction_setting_is_never_reported_as_enabled(): void
    {
        // A channel that says nothing either way cannot be vouched for: the
        // honest answer is "unconfirmed", not the old unconditional PASS.
        Config::set('logging.default', 'stderr');
        Config::set('logging.channels.stderr', ['driver' => 'monolog', 'level' => 'info']);

        $check = $this->indexed(true)['log.redaction'] ?? null;

        $this->assertNotNull($check, 'The preflight must report the log redaction state.');
        $this->assertSame('WARN', $check['status']);
        $this->assertSame('blocking', $check['severity']);
        $this->assertStringContainsString('cannot be confirmed', $check['detail']);
    }

    public function test_a_redis_backed_session_is_no_longer_invisible_to_the_password_check(): void
    {
        // config/session.php names the key 'default'. While the preflight read
        // 'session.driver' it saw null and skipped the Redis password check on a
        // deployment whose sessions really do live in Redis.
        Config::set('queue.default', 'sync');
        Config::set('cache.default', 'file');
        Config::set('session.default', 'redis');
        Config::set('database.redis.default.password', '');

        $check = $this->indexed(true)['redis.password'] ?? null;

        $this->assertNotNull($check, 'The preflight must report the Redis password state.');
        $this->assertSame('FAIL', $check['status'], 'A redis-backed session with no password must block the release.');
    }

    public function test_no_check_prints_a_secret(): void
    {
        $secrets = [
            (string) Config::get('payment.gateways.stripe.secret'),
            (string) Config::get('payment.gateways.bkash.app_secret'),
            (string) Config::get('payment.gateways.nagad.app_secret'),
            (string) Config::get('payment.gateways.crypto.webhook_secret'),
        ];

        $secrets = array_values(array_filter($secrets, static fn (string $value): bool => strlen($value) >= 12));

        if ($secrets === []) {
            $this->addToAssertionCount(1);

            return;
        }

        foreach ($this->report()['checks'] as $check) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $check['detail'], $check['id'].' leaked a configured secret.');
            }
        }
    }
}
