<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Currency;
use App\Models\LedgerAccount;
use App\Services\Compliance\MoneyOperationControlService;
use App\Services\Compliance\MoneyShutdownControlService;
use App\Services\Compliance\RealMoneyTechnicalReadinessService;
use App\Services\Finance\LedgerPostingService;
use App\Services\Finance\Money;
use App\Services\Operations\ProviderReadinessService;
use App\Support\Ops\CheckResult;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BDT readiness: what a Bangladeshi-market deployment is actually told.
 *
 * WHY BDT GETS ITS OWN SUITE
 * --------------------------
 * THB is the primary currency, so a THB deployment can pass every readiness check
 * by configuration alone: PromptPay, the Thai bank payout rail and Stripe are all
 * implemented, and `ProviderReadinessService` will call one of them ready once its
 * credentials are present. BDT is not in that position, and a suite that only
 * exercised THB would hide the difference behind a green run.
 *
 * THE CENTRAL FINDING THIS SUITE PINS DOWN (§4)
 * ---------------------------------------------
 * `ProviderReadinessService::gateway()` adds `bkash_callback_authentication_unverified`
 * to bKash and Nagad **unconditionally**, with the comment "a legacy local HMAC
 * convention is not an authenticated merchant provider contract. Credentials alone
 * cannot establish readiness." Since `ready` means `issues === []`, **no BDT
 * provider lane can ever be ready, however complete its credentials are.**
 *
 * That is not a defect in the check — it is the check being honest about the
 * driver. But it has a consequence an operator must be told plainly rather than
 * discovering at launch: with the real-money gate open, a BDT-only deployment
 * reports `real_money.provider_lanes` FAIL and therefore is NOT technically ready,
 * and cannot become ready by editing an env file. It needs the bKash/Nagad
 * callback authentication implemented. These tests assert that as the correct
 * answer, name the reason, and prove it is the callback lane — not a missing
 * credential — that is doing the refusing.
 *
 * WHAT IS *NOT* TRUE, AND IS ALSO ASSERTED
 * ----------------------------------------
 * The BDT market guard is not the problem. With `payment.country = BD` and a BDT
 * site currency, bKash and Nagad are accepted as BDT rails and the Thai-only rails
 * are refused — the guard works in both directions (§1). The BDT chart of accounts
 * is seeded, suffixed and postable (§3). The BDT currency contract is consistent
 * (§2). Amount bounds are evaluated in BDT, not in the site default (§5).
 */
final class BdtCurrencyReadinessTest extends TestCase
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
    /** A complete bKash lane: every credential `ProviderReadinessService` requires. */
    private const BKASH_CREDENTIALS = [
        'enabled' => true,
        'app_key' => 'test-app-key',
        'app_secret' => 'test-app-secret',
        'username' => 'test-username',
        'password' => 'test-password',
        'base_url' => 'https://tokenized.pay.bkash.com',
        'webhook_secret' => 'test-webhook-secret',
        'currency' => 'BDT',
        'supported_currencies' => ['BDT'],
        'supports_deposit' => true,
        'supports_withdrawal' => true,
    ];

    /**
     * Put the whole deployment in the Bangladeshi market, with a fully
     * credentialed bKash lane.
     */
    private function bdtMarket(): void
    {
        config([
            'real_money.enabled' => true,
            'finance.currency.default' => 'BDT',
            'payment.currency.default' => 'BDT',
            'payment.country' => 'BD',
            'payment.markets.bkash' => ['currencies' => ['BDT'], 'countries' => ['BD']],
            'payment.markets.nagad' => ['currencies' => ['BDT'], 'countries' => ['BD']],
            'payment.gateways.bkash' => self::BKASH_CREDENTIALS,
        ]);
    }

    private function providers(): ProviderReadinessService
    {
        return app(ProviderReadinessService::class);
    }

    private function readiness(): RealMoneyTechnicalReadinessService
    {
        return app(RealMoneyTechnicalReadinessService::class);
    }

    // =====================================================================
    // §1 The market guard works in both directions.
    // =====================================================================

    public function test_the_market_guard_accepts_bangladeshi_rails_in_the_bdt_market(): void
    {
        $this->bdtMarket();

        foreach (['bkash', 'nagad'] as $gateway) {
            if ($gateway === 'nagad') {
                config(['payment.gateways.nagad' => self::BKASH_CREDENTIALS]);
            }

            $issues = $this->providers()->gateway($gateway, 'deposit', Currency::BDT)->issues;

            // The guard is what is under test, not the credentials: a BDT rail in
            // a BDT market must not be told it is in the wrong market.
            self::assertNotContains('bdt_market_refused', $issues, $gateway.' was refused in its own market.');
            self::assertNotContains('site_currency_not_in_market', $issues);
            self::assertNotContains('site_country_not_in_market', $issues);
            self::assertNotContains('site_currency_unsupported', $issues);
        }
    }

    public function test_the_market_guard_refuses_thai_only_rails_in_the_bdt_market_and_the_reverse(): void
    {
        $this->bdtMarket();

        // PromptPay and the Thai bank payout rail are Thai-only. Presenting either
        // as a BDT provider is how a withdrawal gets dispatched to a rail that
        // cannot receive it.
        foreach (['promptpay', 'thai_bank'] as $gateway) {
            self::assertContains(
                'thai_market_refused',
                $this->providers()->gateway($gateway, 'withdrawal', Currency::BDT)->issues,
                $gateway.' was offered as a BDT rail.',
            );
        }

        // And in the other direction: a BDT-only rail must never be treated as a
        // THB rail.
        config(['payment.currency.default' => 'THB', 'payment.country' => 'TH']);

        self::assertContains(
            'bdt_market_refused',
            $this->providers()->gateway('bkash', 'deposit', Currency::THB)->issues,
            'bKash was offered as a THB rail.',
        );
    }

    public function test_a_fully_credentialed_bkash_lane_is_configured_but_never_ready(): void
    {
        $this->bdtMarket();

        $readiness = $this->providers()->gateway('bkash', 'deposit', Currency::BDT);

        self::assertTrue($readiness->enabled);
        self::assertSame(['BDT'], $readiness->currencies);
        self::assertSame(['BD'], $readiness->countries);

        // Every credential is present, the base URL is HTTPS with no userinfo,
        // query or fragment, and the market guard is satisfied — so the ONLY
        // remaining issue is the one that configuration cannot fix.
        self::assertSame(['bkash_callback_authentication_unverified'], $readiness->issues);
        self::assertFalse($readiness->ready, 'A bKash lane was called ready on credentials alone.');
    }

    // =====================================================================
    // §2 The BDT currency contract.
    // =====================================================================

    public function test_a_bdt_deployment_reports_a_consistent_currency_contract(): void
    {
        $this->bdtMarket();

        $check = $this->readiness()->checks()[RealMoneyTechnicalReadinessService::CHECK_CURRENCY_CONTRACT];

        self::assertSame(CheckResult::PASS, $check->status, $check->message);
        self::assertStringContainsString('default BDT', $check->message);
        self::assertStringContainsString('THB/USD/BDT', $check->message);
    }

    public function test_splitting_the_finance_and_payment_defaults_is_reported_as_drift(): void
    {
        $this->bdtMarket();

        // Both keys read FINANCE_DEFAULT_CURRENCY, so a split can only come from a
        // runtime override — which is exactly the drift that makes a deposit admit
        // in one currency and post in another.
        config(['finance.currency.default' => 'THB']);

        $check = $this->readiness()->checks()[RealMoneyTechnicalReadinessService::CHECK_CURRENCY_CONTRACT];

        self::assertSame(CheckResult::FAIL, $check->status);
        self::assertStringContainsString('disagree', $check->message);
        self::assertStringContainsString('THB', $check->message);
        self::assertStringContainsString('BDT', $check->message);
        self::assertArrayHasKey(
            RealMoneyTechnicalReadinessService::CHECK_CURRENCY_CONTRACT,
            $this->readiness()->blockingFailures(RealMoneyTechnicalReadinessService::OP_DEPOSIT),
        );
    }

    public function test_an_unsupported_site_currency_refuses_the_provider_lane_rather_than_guessing_a_market(): void
    {
        $this->bdtMarket();
        config(['payment.currency.default' => 'XYZ']);

        $check = $this->readiness()->checks()[RealMoneyTechnicalReadinessService::CHECK_PROVIDER_LANES];

        self::assertSame(CheckResult::FAIL, $check->status);
        self::assertStringContainsString('not supported', $check->message);
        self::assertStringNotContainsString('XYZ is ready', $check->message);

        // The gateway evaluator says the same thing about the same configuration.
        self::assertContains(
            'site_currency_unsupported',
            $this->providers()->gateway('bkash', 'deposit', Currency::BDT)->issues,
        );
    }

    // =====================================================================
    // §3 The BDT chart of accounts.
    // =====================================================================

    public function test_the_bdt_chart_of_accounts_is_seeded_with_the_currency_suffix(): void
    {
        $this->bdtMarket();

        // The primary currency keeps the bare code; every other currency is
        // suffixed, so a BDT movement can never resolve the THB account.
        self::assertSame('2000', LedgerPostingService::accountCodeFor('2000', Currency::THB));
        self::assertSame('2000:BDT', LedgerPostingService::accountCodeFor('2000', Currency::BDT));
        self::assertSame('1000:BDT', LedgerPostingService::accountCodeFor('1000', Currency::BDT));

        self::assertTrue(
            LedgerAccount::query()->where('code', '2000:BDT')->where('is_active', true)->exists(),
            'The BDT player-liability account was not seeded, so a BDT wallet movement would be refused with ledger_account_missing.',
        );
        self::assertSame(
            Currency::BDT,
            LedgerAccount::query()->where('code', '2000:BDT')->firstOrFail()->currency,
        );

        self::assertSame(
            CheckResult::PASS,
            $this->readiness()->checks()[RealMoneyTechnicalReadinessService::CHECK_CHART_OF_ACCOUNTS]->status,
        );
    }

    public function test_a_bdt_hole_in_the_chart_is_named_in_bdt_terms(): void
    {
        $this->bdtMarket();

        $account = LedgerAccount::query()->where('code', '1000:BDT')->firstOrFail();

        try {
            // Soft delete, because LedgerAccount uses SoftDeletes: restoring loses
            // nothing, not even the posting-derived balances the seeder deliberately
            // never writes.
            $account->delete();

            $check = $this->readiness()->checks()[RealMoneyTechnicalReadinessService::CHECK_CHART_OF_ACCOUNTS];

            self::assertSame(CheckResult::FAIL, $check->status);
            self::assertStringContainsString('1000:BDT', $check->message, 'The report did not name the BDT account that cannot be posted to.');
            self::assertFalse($this->readiness()->readyForOperation(RealMoneyTechnicalReadinessService::OP_DEPOSIT));
        } finally {
            $account->restore();
        }

        self::assertSame(
            CheckResult::PASS,
            $this->readiness()->checks()[RealMoneyTechnicalReadinessService::CHECK_CHART_OF_ACCOUNTS]->status,
        );
    }

    // =====================================================================
    // §4 The finding a BDT operator must be told plainly.
    // =====================================================================

    public function test_a_bdt_deployment_cannot_reach_provider_readiness_by_configuration_alone(): void
    {
        $this->bdtMarket();

        $check = $this->readiness()->checks()[RealMoneyTechnicalReadinessService::CHECK_PROVIDER_LANES];

        // The lane is enabled and fully credentialed, and the verdict is still
        // FAIL — because the only remaining issue is the callback authentication
        // that does not exist in the driver. No env file changes that.
        self::assertSame(CheckResult::FAIL, $check->status);
        self::assertStringContainsString('not ready', $check->message);
        self::assertStringContainsString('BDT', $check->message);
        self::assertStringContainsString('bkash', $check->message);
        self::assertStringNotContainsString('no payment gateway is enabled', $check->message, 'The refusal blamed a missing lane instead of an unauthenticated one.');

        self::assertFalse($this->readiness()->ready());
        self::assertArrayHasKey(
            RealMoneyTechnicalReadinessService::CHECK_PROVIDER_LANES,
            $this->readiness()->blockingFailures(RealMoneyTechnicalReadinessService::OP_DEPOSIT),
        );

        // A credential is never echoed, whatever the verdict.
        self::assertStringNotContainsString('test-app-secret', $check->message);
        self::assertStringNotContainsString('test-webhook-secret', $check->message);
    }

    public function test_the_bdt_refusal_names_the_callback_lane_and_not_a_missing_credential(): void
    {
        $this->bdtMarket();

        // Remove one credential and prove the report distinguishes the two causes:
        // a gap an operator can close tonight, and one that needs code.
        config(['payment.gateways.bkash.app_secret' => '']);

        $issues = $this->providers()->gateway('bkash', 'deposit', Currency::BDT)->issues;

        self::assertContains('missing_app_secret', $issues);
        self::assertContains('bkash_callback_authentication_unverified', $issues);
        self::assertCount(2, $issues, 'A fully credentialed BDT lane should have exactly one issue, and this one has that plus the removed credential.');

        // Restoring the credential leaves only the one that configuration cannot fix.
        config(['payment.gateways.bkash' => self::BKASH_CREDENTIALS]);
        self::assertSame(
            ['bkash_callback_authentication_unverified'],
            $this->providers()->gateway('bkash', 'deposit', Currency::BDT)->issues,
        );
    }

    public function test_a_bdt_only_deployment_with_no_enabled_lane_is_told_there_is_no_lane_at_all(): void
    {
        $this->bdtMarket();
        config(['payment.gateways.bkash.enabled' => false]);

        $check = $this->readiness()->checks()[RealMoneyTechnicalReadinessService::CHECK_PROVIDER_LANES];

        self::assertSame(CheckResult::FAIL, $check->status);
        self::assertStringContainsString('no payment gateway is enabled for BDT', $check->message);

        // The two BDT failures are different findings and must not be collapsed
        // into one message: "no lane" is a configuration task, "lane not ready" is
        // an engineering one.
        config(['payment.gateways.bkash' => self::BKASH_CREDENTIALS]);
        self::assertStringNotContainsString(
            'no payment gateway is enabled',
            $this->readiness()->checks()[RealMoneyTechnicalReadinessService::CHECK_PROVIDER_LANES]->message,
        );
    }

    public function test_the_closed_gate_spares_a_bdt_deployment_the_lane_it_cannot_yet_satisfy(): void
    {
        $this->bdtMarket();
        config(['real_money.enabled' => false]);

        // "A lane that is off is not a failing lane." A BDT deployment that has not
        // launched is told the truth — nothing was checked — instead of being
        // handed a FAIL it cannot act on until the callback lane is built.
        self::assertSame(
            CheckResult::SKIP,
            $this->readiness()->checks()[RealMoneyTechnicalReadinessService::CHECK_PROVIDER_LANES]->status,
        );
        self::assertArrayNotHasKey(
            RealMoneyTechnicalReadinessService::CHECK_PROVIDER_LANES,
            $this->readiness()->blockingFailures(),
        );
    }

    // =====================================================================
    // §5 Operation-level controls in BDT.
    // =====================================================================

    public function test_amount_bounds_are_evaluated_in_bdt_and_not_in_the_site_default(): void
    {
        $this->bdtMarket();
        config(['finance.deposit.min' => '500.00']);

        $controls = app(MoneyOperationControlService::class);

        $refused = $controls->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '100.00', Currency::BDT);

        self::assertFalse($refused['allowed']);
        self::assertSame(MoneyOperationControlService::REFUSED_AMOUNT_BOUNDS, $refused['status']);
        self::assertSame('BDT', $refused['currency'], 'A BDT deposit was evaluated in another currency.');
        self::assertStringContainsString('BDT', $refused['message'], 'The refusal did not name the currency the bound is expressed in.');
        self::assertStringContainsString('500.00', $refused['message']);
        self::assertStringContainsString('minimum', $refused['message']);

        self::assertTrue($controls->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '500.00', Currency::BDT)['allowed']);
        self::assertTrue($controls->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '500.01', 'bdt')['allowed']);

        // The bound is read from the existing config key, so it is the same number
        // the deposit lane and the fees page already show.
        self::assertSame(
            ['min' => 'finance.deposit.min', 'max' => 'finance.deposit.max'],
            $controls->controls(RealMoneyTechnicalReadinessService::OP_DEPOSIT)['bound_sources'],
        );
    }

    public function test_the_bdt_operation_verdict_records_the_lane_finding_without_refusing_by_default(): void
    {
        $this->bdtMarket();

        $decision = app(MoneyOperationControlService::class)
            ->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '1000.00', Currency::BDT);

        // The shipped default is `report`, so a BDT deployment that has not built
        // the callback lane is told about it rather than silently switched off —
        // and switching it on is one env var, not a code change.
        self::assertTrue($decision['allowed']);
        self::assertSame(MoneyOperationControlService::ALLOWED_WITH_WARNINGS, $decision['status']);
        self::assertSame('BDT', $decision['currency']);
        self::assertFalse($decision['gates']['provider_lane_ready']);
        self::assertTrue($decision['gates']['provider_lane_required']);
        self::assertArrayHasKey('provider_lane', $decision['warnings']);
        self::assertStringContainsString('bkash', $decision['warnings']['provider_lane']);

        // Enforcing the lane turns the same finding into a refusal, with the
        // callback lane named as the reason.
        config(['real_money.control.lane_readiness_mode' => MoneyOperationControlService::MODE_ENFORCE]);
        $enforced = app(MoneyOperationControlService::class)
            ->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '1000.00', Currency::BDT);

        self::assertFalse($enforced['allowed']);
        self::assertSame(MoneyOperationControlService::REFUSED_PROVIDER_LANE, $enforced['status']);
        self::assertSame('real_money_provider_lane_unready', $enforced['reason']);

        // A bet in BDT is still decided without the lane: it moves money between
        // the player wallet and the platform ledger and dispatches no provider.
        self::assertFalse(
            app(MoneyOperationControlService::class)
                ->evaluate(RealMoneyTechnicalReadinessService::OP_BET, '1000.00', Currency::BDT)['gates']['provider_lane_required'],
        );
    }

    // =====================================================================
    // §6 The currency contract the money paths rely on.
    // =====================================================================

    public function test_the_bdt_currency_carries_the_contract_the_money_paths_rely_on(): void
    {
        self::assertSame('BDT', Currency::BDT->value);
        self::assertSame('Bangladeshi Taka', Currency::BDT->label());
        self::assertSame('৳', Currency::BDT->symbol());
        self::assertSame(2, Currency::BDT->scale());
        self::assertFalse(Currency::BDT->isPrimary(), 'BDT must not become the primary currency by accident.');
        self::assertSame(Currency::THB, Currency::primary());

        // Case- and space-insensitive resolution, because a BDT code arrives from
        // an env file, a wallet row and a request body.
        self::assertSame(Currency::BDT, Currency::tryFromCode('bdt'));
        self::assertSame(Currency::BDT, Currency::tryFromCode(' BDT '));
        self::assertSame(Currency::BDT, Currency::fromCode('Bdt'));
        self::assertNull(Currency::tryFromCode('BD'));
        self::assertNull(Currency::tryFromCode(''));
        self::assertNull(Currency::tryFromCode(null));

        self::assertContains('BDT', Currency::codes());
        self::assertSame('Bangladeshi Taka', Currency::options()['BDT']);
    }

    public function test_bdt_amounts_stay_exact_decimal_strings_end_to_end(): void
    {
        $amount = Money::of('1234.5', Currency::BDT);

        // Never a float: the value stays a decimal string from end to end, so no
        // precision can be lost between admission, posting and display.
        self::assertSame('1234.50', $amount->toString());
        self::assertSame('৳1,234.50', $amount->format());
        self::assertSame(Currency::BDT, $amount->currency());
        self::assertSame(2, $amount->scale());

        self::assertSame('1234.51', $amount->plus(Money::of('0.01', Currency::BDT))->toString());
        self::assertTrue($amount->isGreaterThan(Money::of('500.00', Currency::BDT)));
        self::assertFalse($amount->isGreaterThan(Money::of('500000.00', Currency::BDT)));

        // A BDT amount must not be compared against a THB bound.
        $threw = false;
        try {
            $amount->assertSameCurrency(Money::of('1234.50', Currency::THB));
        } catch (\App\Exceptions\FinancialException) {
            $threw = true;
        }
        self::assertTrue($threw, 'A cross-currency comparison was allowed.');

        self::assertSame('৳0.00', Money::zero(Currency::BDT)->format());
    }
}
