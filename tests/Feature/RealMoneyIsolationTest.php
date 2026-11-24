<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Services\Compliance\MoneyOperationControlService;
use App\Services\Compliance\MoneyShutdownControlService;
use App\Services\Compliance\RealMoneyGate;
use App\Services\Compliance\RealMoneyTechnicalReadinessService;
use App\Services\License\LicenseService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Isolation: each control is separate from the others, and all of them are
 * separate from what the money paths already do.
 *
 * WHY THIS SUITE EXISTS
 * ---------------------
 * `MoneyOperationControlService` is a new admission surface sitting next to a
 * live real-money system whose money paths already call
 * `app(RealMoneyGate::class)->assertOpen('deposit')` and rely on the exact code
 * that throws. The one thing that must never happen is that adding a control
 * changes an answer somebody already depends on. So this suite is not primarily
 * about what the new service refuses — it is about the BOUNDARIES:
 *
 *   §1 the shipped defaults cannot widen or narrow an existing money path;
 *   §2 the master switch stays enforced in every mode, with the identical code;
 *   §3 a provider-lane finding reaches only the operations that dispatch one;
 *   §4 enforcing one control does not enforce the other;
 *   §5 the environment decides a finding's SEVERITY, not the finding itself;
 *   §6 a refusal writes nothing, so it is safe inside a money transaction;
 *   §7 a refusal's audit payload cannot carry anything but scalars;
 *   §8 the amount bounds are read from existing config, never invented here.
 *
 * NOTHING HERE ASSUMES A READY DEPLOYMENT
 * ---------------------------------------
 * The default test environment has the gate open but no licence and no gateway
 * credentials (see RealMoneyGateFeatureTest). That is used deliberately: an
 * isolation property proved against a deployment where everything passes proves
 * nothing, because there is nothing to isolate.
 */
final class RealMoneyIsolationTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        // The runtime operational control defaults CLOSED and fails closed, so
        // without this every test below would measure the halt latch and nothing
        // else. This suite examines the controls DOWNSTREAM of it - currency,
        // bounds, provider lane, technical readiness - so the latch is cleared
        // first and each test can reach the control it is actually about.
        //
        // Written straight to the table rather than through the service because
        // authorization is not what is under test here; MoneyShutdownControlTest
        // covers the permission, audit, default and concurrency rules directly.
        $this->openOperationControl();
    }

    /**
     * Clear the runtime halt latch for one test.
     *
     * `DatabaseTruncation` empties the table between tests, so this runs per
     * test and no state leaks from one into the next.
     */
    private function openOperationControl(): void
    {
        DB::table('money_operation_controls')->updateOrInsert(
            ['control_key' => MoneyShutdownControlService::CONTROL_KEY],
            [
                'state' => MoneyShutdownControlService::STATE_OPEN,
                'previous_state' => MoneyShutdownControlService::STATE_CLOSED,
                'reason' => 'test fixture: examining the controls downstream of the latch',
                'changed_by_user_id' => null,
                'changed_at' => now(),
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
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

    private function controls(): MoneyOperationControlService
    {
        return app(MoneyOperationControlService::class);
    }

    /**
     * A complete, unexpired licence. `LicenseService` is bound to a configuration
     * snapshot, so the binding is refreshed after the runtime write — the same
     * position a deployment is in when it edits REAL_MONEY_LICENSE_* without
     * rebuilding config:cache.
     */
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
     * One enabled, fully credentialed PromptPay lane in the Thai market, so a
     * provider-lane finding can be made to disappear without touching any other
     * control.
     */
    private function readyThaiLane(): void
    {
        config([
            'payment.currency.default' => 'THB',
            'payment.country' => 'TH',
            'payment.markets.promptpay' => ['currencies' => ['THB'], 'countries' => ['TH']],
            'payment.gateways.promptpay' => [
                'enabled' => true,
                'merchant_id' => '0900000000',
                'supported_currencies' => ['THB'],
            ],
        ]);
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
    // §1 The shipped defaults cannot change an existing money path.
    // =====================================================================

    public function test_the_shipped_report_mode_records_findings_without_refusing_anything(): void
    {
        $surface = $this->controls()->controls(RealMoneyTechnicalReadinessService::OP_DEPOSIT);

        // The master switch and the amount bound are the only controls that
        // enforce out of the box. Everything new is recorded, not enforced.
        self::assertSame(MoneyOperationControlService::MODE_ENFORCE, $surface['modes']['master_switch']);
        self::assertSame(MoneyOperationControlService::MODE_ENFORCE, $surface['modes']['amount_bounds']);
        self::assertSame(MoneyOperationControlService::MODE_ENFORCE, $surface['modes']['currency']);
        self::assertSame(MoneyOperationControlService::MODE_REPORT, $surface['modes']['technical_readiness']);
        self::assertSame(MoneyOperationControlService::MODE_REPORT, $surface['modes']['provider_lane']);
        self::assertSame(MoneyOperationControlService::MODE_REPORT, $surface['modes']['license']);

        $decision = $this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '100.00');

        // The deployment is genuinely NOT technically ready — no licence, no
        // gateway — and the decision still allows, because the new controls are
        // reporting. That is the whole point of the default.
        self::assertTrue($decision['allowed']);
        self::assertSame(MoneyOperationControlService::ALLOWED_WITH_WARNINGS, $decision['status']);
        self::assertSame([], $decision['blocking']);
        self::assertNotEmpty($decision['warnings'], 'A report-mode control found nothing to report on an unlicensed, gateway-less deployment.');
        self::assertArrayHasKey('real_money.license_contract', $decision['warnings']);
        self::assertArrayHasKey('provider_lane', $decision['warnings']);
        self::assertFalse($decision['gates']['technically_ready']);

        // ...and the findings are still recorded in full, so the answer is
        // visible without being load-bearing.
        self::assertFalse($decision['technical']['ready']);
        self::assertNotEmpty($decision['technical']['blocking']);

        // assertAllowed() must agree with evaluate(): a service whose two verbs
        // disagree would let a caller pick the permissive one by accident.
        $threw = false;
        try {
            $this->controls()->assertAllowed(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '100.00');
        } catch (FinancialException) {
            $threw = true;
        }
        self::assertFalse($threw, 'assertAllowed() refused an operation evaluate() allowed.');
    }

    public function test_an_unrecognised_mode_value_falls_back_to_report_rather_than_to_enforce(): void
    {
        // A typo in an env var must not silently start refusing live money
        // movements. The unsafe direction is enforce-by-accident, so every value
        // that is not the word "enforce" reports — including the truthy strings,
        // which a `filter_var(..., FILTER_VALIDATE_BOOLEAN)` reading would have
        // accepted and which would turn `MODE=true` into a live refusal.
        foreach (['enforced', 'yes', '1', 'true', 'on', 'strict', 'block', '', '  '] as $value) {
            config(['real_money.control.technical_readiness_mode' => $value]);
            config(['real_money.control.lane_readiness_mode' => $value]);

            self::assertSame(
                MoneyOperationControlService::MODE_REPORT,
                $this->controls()->mode('technical_readiness_mode'),
                'Mode "'.$value.'" was not normalised to report.',
            );
            self::assertSame(MoneyOperationControlService::MODE_REPORT, $this->controls()->mode('lane_readiness_mode'));
        }

        // Case and surrounding whitespace ARE normalised, because an operator
        // writing `MODE=Enforce` means the same thing as `MODE=enforce`.
        foreach (['enforce', 'ENFORCE', ' EnFoRcE ', 'enforce '] as $value) {
            config(['real_money.control.technical_readiness_mode' => $value]);
            self::assertSame(
                MoneyOperationControlService::MODE_ENFORCE,
                $this->controls()->mode('technical_readiness_mode'),
                'Mode "'.$value.'" was not recognised as enforce.',
            );
        }
    }

    // =====================================================================
    // §2 The master switch is isolated from the modes.
    // =====================================================================

    public function test_the_master_switch_is_enforced_in_every_mode_with_the_existing_refusal_code(): void
    {
        config(['real_money.enabled' => false]);

        foreach ([MoneyOperationControlService::MODE_REPORT, MoneyOperationControlService::MODE_ENFORCE] as $mode) {
            config([
                'real_money.control.technical_readiness_mode' => $mode,
                'real_money.control.lane_readiness_mode' => $mode,
            ]);

            $decision = $this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '100.00');

            self::assertFalse($decision['allowed'], 'Mode "'.$mode.'" let a deposit through a closed master switch.');
            self::assertSame(MoneyOperationControlService::REFUSED_MASTER_SWITCH, $decision['status']);

            // IDENTICAL to what every existing money path already produces, so
            // swapping RealMoneyGate::assertOpen() for this service cannot change
            // the code an API client or a log parser keys on.
            self::assertSame('real_money_disabled', $decision['reason']);

            try {
                $this->controls()->assertAllowed(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '100.00');
                self::fail('Mode "'.$mode.'" let a deposit through a closed master switch.');
            } catch (FinancialException $e) {
                self::assertSame('real_money_disabled', $e->errorCode());
                self::assertSame('deposit', $e->contextValue('operation'));
            }

            // And it is the same refusal the gate itself gives, byte for byte on
            // the code — report mode can never widen the master switch.
            try {
                app(RealMoneyGate::class)->assertOpen('deposit');
                self::fail('The master switch itself was open.');
            } catch (FinancialException $e) {
                self::assertSame('real_money_disabled', $e->errorCode());
            }
        }
    }

    public function test_the_master_switch_is_asked_before_anything_that_reads_the_database(): void
    {
        config(['real_money.enabled' => false]);
        config([
            'real_money.control.technical_readiness_mode' => MoneyOperationControlService::MODE_ENFORCE,
            'real_money.control.lane_readiness_mode' => MoneyOperationControlService::MODE_ENFORCE,
        ]);

        $decision = $this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, 'not-a-number');

        // A closed switch refuses before the amount is parsed, before the currency
        // is resolved and before the readiness probe touches the database — the
        // same ordering RealMoneyGate::assertOpen() publishes, so a refused
        // operation leaves balances, locks and the ledger exactly as it found them.
        self::assertSame(MoneyOperationControlService::REFUSED_MASTER_SWITCH, $decision['status']);
        self::assertSame([], $decision['technical'], 'The readiness probe ran for an operation the master switch had already refused.');
        self::assertNull($decision['lane']);
        self::assertArrayNotHasKey('amount_within_bounds', $decision['gates']);
    }

    // =====================================================================
    // §3 A lane finding reaches only the operations that dispatch a lane.
    // =====================================================================

    public function test_a_provider_lane_refusal_reaches_the_provider_operations_and_no_others(): void
    {
        $this->license();
        config(['real_money.control.lane_readiness_mode' => MoneyOperationControlService::MODE_ENFORCE]);

        foreach ([
            RealMoneyTechnicalReadinessService::OP_DEPOSIT,
            RealMoneyTechnicalReadinessService::OP_WITHDRAWAL,
            RealMoneyTechnicalReadinessService::OP_PAYOUT,
        ] as $operation) {
            $decision = $this->controls()->evaluate($operation, '100.00');

            self::assertFalse($decision['allowed'], $operation.' was allowed with no provider lane and the lane enforced.');
            self::assertTrue($decision['gates']['provider_lane_required']);
            self::assertFalse($decision['gates']['provider_lane_ready']);
            self::assertArrayHasKey('provider_lane', $decision['blocking']);
        }

        // A bet moves money between the player wallet and the platform ledger; a
        // conversion moves it between two pockets of the same player. Neither
        // dispatches a provider, so a dark gateway lane cannot refuse them — and
        // saying otherwise would take betting down on every deployment that has
        // not bought a rail.
        foreach ([
            RealMoneyTechnicalReadinessService::OP_BET,
            RealMoneyTechnicalReadinessService::OP_CONVERSION,
        ] as $operation) {
            $decision = $this->controls()->evaluate($operation, '100.00');

            self::assertFalse($decision['gates']['provider_lane_required'], $operation.' was told it needs a provider lane.');
            self::assertArrayNotHasKey('provider_lane', $decision['blocking']);
            self::assertSame(
                'this operation does not dispatch a provider lane',
                $decision['lane']['detail'],
            );
        }
    }

    public function test_a_lane_finding_disappears_when_a_lane_is_configured_without_touching_any_other_control(): void
    {
        $this->license();
        config([
            'real_money.control.lane_readiness_mode' => MoneyOperationControlService::MODE_ENFORCE,
            'real_money.control.technical_readiness_mode' => MoneyOperationControlService::MODE_ENFORCE,
        ]);

        self::assertFalse($this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '100.00')['allowed']);

        $this->readyThaiLane();

        $decision = $this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '100.00');

        self::assertTrue($decision['gates']['provider_lane_ready'], 'A configured PromptPay lane was still reported unready: '.json_encode($decision['lane']));
        self::assertArrayNotHasKey('provider_lane', $decision['blocking']);
        self::assertTrue($decision['gates']['technically_ready'], 'The technical verdict did not follow the lane it delegates to: '.json_encode($decision['technical']['blocking']));
        self::assertTrue($decision['allowed']);
        self::assertSame(MoneyOperationControlService::ALLOWED, $decision['status']);
    }

    // =====================================================================
    // §4 Enforcing one control does not enforce the other.
    // =====================================================================

    public function test_each_control_is_enforced_independently_and_the_broader_finding_names_the_refusal(): void
    {
        $this->license();

        // TECHNICAL only. `provider_lanes` is inside the technical scope for a
        // deposit, so the refusal is technical even though the underlying fact is
        // a missing rail — the broader question names the answer.
        config([
            'real_money.control.technical_readiness_mode' => MoneyOperationControlService::MODE_ENFORCE,
            'real_money.control.lane_readiness_mode' => MoneyOperationControlService::MODE_REPORT,
        ]);
        $technicalOnly = $this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '100.00');
        self::assertFalse($technicalOnly['allowed']);
        self::assertSame(MoneyOperationControlService::REFUSED_TECHNICAL_READINESS, $technicalOnly['status']);
        self::assertSame('real_money_technical_readiness_failed', $technicalOnly['reason']);
        self::assertArrayHasKey('real_money.provider_lanes', $technicalOnly['blocking']);
        self::assertArrayNotHasKey('provider_lane', $technicalOnly['blocking']);
        self::assertArrayHasKey('provider_lane', $technicalOnly['warnings'], 'A report-mode control stopped reporting once the other one was enforced.');

        // LANE only.
        config([
            'real_money.control.technical_readiness_mode' => MoneyOperationControlService::MODE_REPORT,
            'real_money.control.lane_readiness_mode' => MoneyOperationControlService::MODE_ENFORCE,
        ]);
        $laneOnly = $this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '100.00');
        self::assertFalse($laneOnly['allowed']);
        self::assertSame(MoneyOperationControlService::REFUSED_PROVIDER_LANE, $laneOnly['status']);
        self::assertSame('real_money_provider_lane_unready', $laneOnly['reason']);
        self::assertSame(['provider_lane'], array_keys($laneOnly['blocking']));
        // The technical finding is still recorded, and still not enforced.
        self::assertArrayHasKey('real_money.provider_lanes', $laneOnly['warnings']);
        self::assertArrayNotHasKey('real_money.provider_lanes', $laneOnly['blocking']);

        // BOTH. Every enforced finding is carried, so an operator who fixes the
        // first one immediately sees the next instead of re-running to find it.
        config([
            'real_money.control.technical_readiness_mode' => MoneyOperationControlService::MODE_ENFORCE,
            'real_money.control.lane_readiness_mode' => MoneyOperationControlService::MODE_ENFORCE,
        ]);
        $both = $this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '100.00');
        self::assertFalse($both['allowed']);
        self::assertSame(MoneyOperationControlService::REFUSED_TECHNICAL_READINESS, $both['status'], 'The refusal source depended on array order rather than on a fixed priority.');
        self::assertArrayHasKey('provider_lane', $both['blocking']);
        self::assertArrayHasKey('real_money.provider_lanes', $both['blocking']);
        self::assertGreaterThan(1, count($both['blocking']));
    }

    public function test_a_bet_is_decided_without_the_lane_even_when_the_lane_is_enforced(): void
    {
        $this->license();
        config([
            'real_money.control.lane_readiness_mode' => MoneyOperationControlService::MODE_ENFORCE,
            'real_money.control.technical_readiness_mode' => MoneyOperationControlService::MODE_ENFORCE,
        ]);

        $bet = $this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_BET, '100.00');

        self::assertTrue($bet['allowed'], 'A licensed bet was refused by a control that does not apply to it: '.json_encode($bet['blocking']));
        self::assertSame(MoneyOperationControlService::ALLOWED, $bet['status']);
        self::assertSame([], $bet['blocking']);

        // The same configuration, one operation apart, refuses — which is the
        // isolation being proved, not an inconsistency.
        self::assertFalse($this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '100.00')['allowed']);
    }

    // =====================================================================
    // §5 The environment decides severity, not the finding.
    // =====================================================================

    public function test_the_same_finding_is_advisory_before_launch_and_blocking_in_production(): void
    {
        // phpunit.xml sets QUEUE_CONNECTION=sync and no FIELD_ENCRYPTION_KEY.
        // Both are correct for a local box and unacceptable for production, and
        // the check must say which it is looking at rather than picking one.
        $testing = app(RealMoneyTechnicalReadinessService::class)->checks();

        self::assertSame('WARN', $testing[RealMoneyTechnicalReadinessService::CHECK_QUEUE_DRIVER]->status);
        self::assertSame('WARN', $testing[RealMoneyTechnicalReadinessService::CHECK_FIELD_ENCRYPTION_KEY]->status);

        $app = app();
        $previous = $app['env'];
        $app['env'] = 'production';

        try {
            $production = app(RealMoneyTechnicalReadinessService::class)->checks();

            self::assertSame('FAIL', $production[RealMoneyTechnicalReadinessService::CHECK_QUEUE_DRIVER]->status);
            self::assertSame('FAIL', $production[RealMoneyTechnicalReadinessService::CHECK_FIELD_ENCRYPTION_KEY]->status);

            // The finding is the same finding; only its weight changed.
            self::assertSame(
                $testing[RealMoneyTechnicalReadinessService::CHECK_SESSION_DRIVER]->status,
                $production[RealMoneyTechnicalReadinessService::CHECK_SESSION_DRIVER]->status,
                'A check that was already satisfied in testing changed verdict in production.',
            );
        } finally {
            $app['env'] = $previous;
        }

        self::assertSame('WARN', app(RealMoneyTechnicalReadinessService::class)->checks()[RealMoneyTechnicalReadinessService::CHECK_QUEUE_DRIVER]->status);
    }

    // =====================================================================
    // §6 A refusal writes nothing.
    // =====================================================================

    public function test_a_refusal_writes_nothing_so_it_is_safe_inside_an_open_money_transaction(): void
    {
        config([
            'real_money.enabled' => true,
            'real_money.control.technical_readiness_mode' => MoneyOperationControlService::MODE_ENFORCE,
            'real_money.control.lane_readiness_mode' => MoneyOperationControlService::MODE_ENFORCE,
        ]);

        $before = $this->moneyRowCounts();

        // Refused, allowed, refused again, and refused inside an open transaction.
        // FinancialComplianceGateService needed a dedicated
        // auditPayoutRefusalAfterRollback() method because a refusal audit written
        // inside a transaction that then rolls back is an audit that never
        // happened. This service writes nothing at all and hands the facts back
        // instead, so the caller decides when and where to persist them.
        foreach (['deposit', 'withdrawal', 'payout', 'bet', 'conversion'] as $operation) {
            $this->controls()->evaluate($operation, '100.00');

            try {
                $this->controls()->assertAllowed($operation, '100.00');
            } catch (FinancialException) {
                // Expected for the provider operations; the point is that nothing
                // was written on the way out.
            }
        }

        DB::transaction(function (): void {
            $decision = $this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '100.00');
            self::assertFalse($decision['allowed']);
            self::assertNotEmpty($decision['blocking']);
        });

        self::assertSame($before, $this->moneyRowCounts(), 'An operation control wrote to the database.');
    }

    // =====================================================================
    // §7 The audit payload is scalar-only.
    // =====================================================================

    public function test_describe_refusal_carries_only_scalars_so_nothing_can_be_smuggled_into_an_audit_row(): void
    {
        $facts = $this->controls()->describeRefusal([
            'operation' => 'withdrawal',
            'status' => MoneyOperationControlService::REFUSED_TECHNICAL_READINESS,
            'reason' => 'real_money_technical_readiness_failed',
            'currency' => 'THB',
            'amount' => '5000.00',
            'allowed' => false,
            // Everything below is the kind of thing a caller must not be able to
            // push into an audit row through this array: a destination, a
            // document reference, a nested payload, an object.
            'blocking' => [
                'real_money.provider_lanes' => 'no payment gateway is enabled for THB',
                'nested' => ['account_number' => '1234567890'],
                'object' => new \stdClass,
                'numeric' => 42,
            ],
            'gates' => ['master_switch_open' => true],
            'technical' => ['checks' => ['a', 'b']],
        ]);

        foreach ($facts as $key => $value) {
            self::assertTrue(
                is_scalar($value) || $value === null,
                sprintf('describeRefusal() leaked a %s under "%s".', get_debug_type($value), (string) $key),
            );
        }

        self::assertSame('money_operation_control', $facts['gate']);
        self::assertSame('withdrawal', $facts['operation']);
        self::assertSame('yes', $facts['refused']);
        self::assertSame(42, $facts['blocking_numeric']);
        self::assertArrayNotHasKey('blocking_nested', $facts);
        self::assertArrayNotHasKey('blocking_object', $facts);
        self::assertArrayNotHasKey('gates', $facts);
        self::assertArrayNotHasKey('technical', $facts);

        // The encoded form is what actually reaches the database, so prove it too.
        self::assertIsString(json_encode($facts, JSON_THROW_ON_ERROR));
    }

    public function test_an_allowed_decision_is_described_as_allowed_rather_than_as_a_refusal(): void
    {
        config(['real_money.enabled' => true]);
        $decision = $this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_BET, '100.00');

        self::assertTrue($decision['allowed']);
        self::assertSame('no', $this->controls()->describeRefusal($decision)['refused']);
        self::assertNull($this->controls()->describeRefusal($decision)['reason_code']);
    }

    // =====================================================================
    // §8 The bounds are read from existing config, never invented here.
    // =====================================================================

    public function test_the_amount_bounds_are_the_existing_config_keys_and_not_numbers_of_this_services_own(): void
    {
        $surface = $this->controls()->controls(RealMoneyTechnicalReadinessService::OP_DEPOSIT);

        self::assertSame(['min' => 'finance.deposit.min', 'max' => 'finance.deposit.max'], $surface['bound_sources']);
        self::assertSame((string) config('finance.deposit.min'), $surface['bounds']['min']);
        self::assertSame((string) config('finance.deposit.max'), $surface['bounds']['max']);

        // A payout is bounded by the four-eyes APPROVAL threshold in
        // finance.prize_payout, which PayoutApprovalService owns. Duplicating it
        // here would create a second opinion about the same money, so the bound is
        // declared unbounded rather than copied.
        $payout = $this->controls()->controls(RealMoneyTechnicalReadinessService::OP_PAYOUT);
        self::assertSame(['min' => null, 'max' => null], $payout['bounds']);
        self::assertSame(['min' => null, 'max' => null], $payout['bound_sources']);

        // One source of truth: moving the existing key moves the verdict.
        config(['finance.deposit.min' => '500.00']);
        self::assertSame('500.00', $this->controls()->controls(RealMoneyTechnicalReadinessService::OP_DEPOSIT)['bounds']['min']);

        $refused = $this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '100.00');
        self::assertFalse($refused['allowed']);
        self::assertSame(MoneyOperationControlService::REFUSED_AMOUNT_BOUNDS, $refused['status']);
        self::assertSame('real_money_amount_out_of_bounds', $refused['reason']);
        self::assertArrayHasKey('amount_bounds', $refused['blocking']);
        self::assertStringContainsString('minimum', $refused['message']);
        self::assertStringContainsString('500.00', $refused['message']);
        self::assertStringContainsString('THB', $refused['message']);

        self::assertTrue($this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '500.00')['allowed']);
        self::assertTrue($this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '500.01')['allowed']);

        config(['finance.deposit.max' => '1000.00']);
        $overMax = $this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '1000.01');
        self::assertFalse($overMax['allowed']);
        self::assertSame(MoneyOperationControlService::REFUSED_AMOUNT_BOUNDS, $overMax['status']);
        self::assertStringContainsString('maximum', $overMax['message']);
    }

    public function test_an_unparseable_bound_is_treated_as_no_bound_rather_than_as_zero(): void
    {
        // A mis-typed ceiling that read as 0.00 would refuse every operation and
        // look like a policy decision. This is the same principle that makes
        // risk.exposure.max_stake_per_number ship without a default.
        config(['finance.deposit.min' => 'not-a-number', 'finance.deposit.max' => '']);

        $surface = $this->controls()->controls(RealMoneyTechnicalReadinessService::OP_DEPOSIT);
        self::assertSame(['min' => null, 'max' => null], $surface['bounds']);
        self::assertSame(['min' => 'finance.deposit.min', 'max' => 'finance.deposit.max'], $surface['bound_sources']);

        self::assertTrue($this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '0.01')['allowed']);
    }

    public function test_an_amount_that_is_not_a_positive_decimal_is_refused_before_any_bound_is_consulted(): void
    {
        config(['finance.deposit.min' => '50.00']);

        foreach (['0.00' => 'money_not_positive', '-5.00' => 'money_not_positive', 'abc' => 'money_malformed_amount', '' => 'money_malformed_amount'] as $amount => $inner) {
            $decision = $this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, (string) $amount);

            self::assertFalse($decision['allowed'], 'Amount "'.$amount.'" was admitted.');
            self::assertSame(MoneyOperationControlService::REFUSED_AMOUNT_SHAPE, $decision['status']);
            self::assertSame('real_money_amount_invalid', $decision['reason']);
            self::assertSame($inner, $decision['blocking']['amount'], 'The underlying Money refusal was not carried.');
        }

        // No amount supplied means "no amount to bound" — the shape and bound
        // controls stay silent rather than inventing zero.
        $noAmount = $this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT);
        self::assertNull($noAmount['amount']);
        self::assertArrayNotHasKey('amount', $noAmount['blocking']);
    }

    public function test_an_unsupported_currency_is_refused_and_never_defaults_to_the_site_currency(): void
    {
        foreach (['XYZ', 'THBB', 'baht', '0'] as $code) {
            $decision = $this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '100.00', $code);

            self::assertFalse($decision['allowed'], 'Currency "'.$code.'" was admitted.');
            self::assertSame(MoneyOperationControlService::REFUSED_CURRENCY, $decision['status']);
            self::assertSame('real_money_currency_unsupported', $decision['reason']);
            self::assertSame(strtoupper(trim($code)), $decision['currency'], 'The refusal echoed a resolved currency instead of the one that was asked for.');
        }

        // An explicit currency is honoured, and null falls back to the site
        // currency — the two must not be confused, because a BDT deposit silently
        // evaluated as THB is a real-money defect, not a convenience.
        self::assertSame('BDT', $this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '100.00', Currency::BDT)['currency']);
        self::assertSame('BDT', $this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '100.00', 'bdt')['currency'], 'A lower-case currency code was not normalised.');
        self::assertSame('THB', $this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '100.00', null)['currency']);
        self::assertSame('THB', $this->controls()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '100.00')['currency']);
    }

    public function test_an_unknown_operation_is_refused_by_both_verbs_and_never_answers_for_the_estate(): void
    {
        $decision = $this->controls()->evaluate('deposits', '100.00');

        self::assertFalse($decision['allowed']);
        self::assertSame(MoneyOperationControlService::REFUSED_OPERATION_UNKNOWN, $decision['status']);
        self::assertSame('real_money_operation_unknown', $decision['reason']);
        self::assertSame('deposits', $decision['operation'], 'The refusal echoed a normalised name the caller never used.');
        self::assertSame([], $decision['technical'], 'An unknown operation ran the readiness probe.');

        try {
            $this->controls()->assertAllowed('deposits', '100.00');
            self::fail('An unknown operation was allowed.');
        } catch (FinancialException $e) {
            self::assertSame('real_money_operation_unknown', $e->errorCode());
        }

        // controls() answers for the whole estate when asked to, and says so.
        $all = $this->controls()->controls();
        self::assertNull($all['operation']);
        self::assertTrue($all['known']);
        self::assertSame(RealMoneyTechnicalReadinessService::CHECKS, $all['required_checks']);

        self::assertFalse($this->controls()->controls('deposits')['known']);
    }
}
