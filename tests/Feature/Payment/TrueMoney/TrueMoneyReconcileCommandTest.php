<?php

declare(strict_types=1);

namespace Tests\Feature\Payment\TrueMoney;

use App\Enums\Currency;
use App\Enums\TruemoneyRedemptionStatus;
use App\Models\TruemoneyVoucherRedemption;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Payment\PaymentTestCase;

final class TrueMoneyReconcileCommandTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['finance.deposit.min' => '0.01']);
        config([
            'truemoney.min_amount' => '0.01',
            'truemoney.max_amount' => '100000.00',
            'truemoney.per_user_daily_count' => 5,
            'truemoney.per_user_daily_amount' => '50000.00',
        ]);
    }

    public function test_dry_run_does_not_mutate_redemption_or_credit_a_wallet(): void
    {
        $player = $this->createPlayer('0.00', Currency::THB);
        $redemption = $this->eligibleRedemption($player['user']->getKey(), hash('sha256', 'dry-run-voucher'), '42.00');
        Http::fake();

        $this->artisan('truemoney:reconcile --dry-run')->assertExitCode(0);

        self::assertSame(TruemoneyRedemptionStatus::RedeemedUnconfirmed, $redemption->fresh()->status);
        self::assertNull($redemption->fresh()->deposit_id);
        self::assertNull($redemption->fresh()->failure_code);
        self::assertSame('0.00', $player['wallet']->fresh()->balance);
        self::assertDatabaseCount('deposits', 0);
        self::assertDatabaseCount('financial_transactions', 0);
        Http::assertNothingSent();
    }

    public function test_reconcile_credits_only_from_stored_success_evidence_and_is_idempotent(): void
    {
        $player = $this->createPlayer('0.00', Currency::THB);
        $redemption = $this->eligibleRedemption($player['user']->getKey(), hash('sha256', 'credit-voucher'), '42.37');
        Http::fake();

        $this->artisan('truemoney:reconcile')->assertExitCode(0);

        self::assertSame(TruemoneyRedemptionStatus::Credited, $redemption->fresh()->status);
        self::assertNotNull($redemption->fresh()->deposit_id);
        self::assertSame('42.37', $player['wallet']->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 1);
        self::assertDatabaseCount('ledger_entries', 2);
        Http::assertNothingSent();

        $this->artisan('truemoney:reconcile')->assertExitCode(0);
        self::assertSame('42.37', $player['wallet']->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 1);
        self::assertDatabaseCount('ledger_entries', 2);
    }

    public function test_closed_launch_switch_does_not_strand_a_previously_consumed_proven_voucher(): void
    {
        $player = $this->createPlayer('0.00', Currency::THB);
        $redemption = $this->eligibleRedemption($player['user']->getKey(), hash('sha256', 'prior-consumed-voucher'), '42.37');
        Http::fake();
        config(['real_money.enabled' => false]);
        $this->artisan('truemoney:reconcile')->assertExitCode(0);
        self::assertSame(TruemoneyRedemptionStatus::Credited, $redemption->fresh()->status);
        self::assertNotNull($redemption->fresh()->deposit_id);
        self::assertSame('42.37', (string) $player['wallet']->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 1);
        self::assertDatabaseCount('ledger_entries', 2);
        Http::assertNothingSent();
        $this->artisan('truemoney:reconcile')->assertExitCode(0);
        self::assertSame('42.37', (string) $player['wallet']->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 1);
    }

    public function test_rows_without_proving_evidence_and_rows_over_the_daily_attempt_limit_remain_uncredited(): void
    {
        $player = $this->createPlayer('0.00', Currency::THB);
        $incompletePlayer = $this->createPlayer('0.00', Currency::THB);
        $evidencePlayer = $this->createPlayer('0.00', Currency::THB);
        $incomplete = TruemoneyVoucherRedemption::factory()->create([
            'user_id' => $incompletePlayer['user']->getKey(),
            'voucher_hash' => hash('sha256', 'incomplete-voucher'),
            'status' => TruemoneyRedemptionStatus::RedeemedUnconfirmed,
            'provider_status' => 'unknown',
            'provider_payload' => ['amount' => '99.00', 'currency' => 'THB', 'http_status' => 200],
        ]);
        $riskReview = TruemoneyVoucherRedemption::factory()->create([
            'user_id' => $incompletePlayer['user']->getKey(),
            'voucher_hash' => hash('sha256', 'risk-review-voucher'),
            'status' => TruemoneyRedemptionStatus::RedeemedUnconfirmed,
            'provider_status' => 'SUCCESS',
            'provider_payload' => [
                'amount' => '30.00', 'currency' => 'THB', 'http_status' => 200,
                'provider_status' => 'SUCCESS', 'risk_decision' => 'review',
            ],
        ]);
        $wrongCurrency = $this->eligibleRedemption($evidencePlayer['user']->getKey(), hash('sha256', 'wrong-currency-voucher'), '35.00');
        $wrongCurrency->forceFill(['currency' => 'USD'])->save();
        $invalidHash = $this->eligibleRedemption($evidencePlayer['user']->getKey(), str_repeat('z', 64), '36.00');
        config(['truemoney.per_user_daily_count' => 2]);
        $limitRow = $this->eligibleRedemption($player['user']->getKey(), hash('sha256', 'limit-current-voucher'), '10.00');
        foreach (['limit-extra-voucher-a', 'limit-extra-voucher-b'] as $voucher) {
            TruemoneyVoucherRedemption::factory()->create([
                'user_id' => $player['user']->getKey(),
                'voucher_hash' => hash('sha256', $voucher),
                'status' => TruemoneyRedemptionStatus::Rejected,
                'provider_status' => 'REJECTED',
                'provider_payload' => [],
            ]);
        }
        Http::fake();

        $this->artisan('truemoney:reconcile')->assertExitCode(0);

        self::assertSame(TruemoneyRedemptionStatus::RedeemedUnconfirmed, $incomplete->fresh()->status);
        self::assertSame('provider_evidence_incomplete', $incomplete->fresh()->failure_code);
        self::assertSame(TruemoneyRedemptionStatus::RedeemedUnconfirmed, $riskReview->fresh()->status);
        self::assertSame('provider_evidence_incomplete', $riskReview->fresh()->failure_code);
        self::assertSame('provider_evidence_incomplete', $wrongCurrency->fresh()->failure_code);
        self::assertSame('provider_evidence_incomplete', $invalidHash->fresh()->failure_code);
        self::assertSame(TruemoneyRedemptionStatus::RedeemedUnconfirmed, $limitRow->fresh()->status);
        self::assertSame('daily_attempt_limit_exceeded', $limitRow->fresh()->failure_code);
        self::assertSame('0.00', $player['wallet']->fresh()->balance);
        self::assertDatabaseCount('deposits', 0);
        self::assertDatabaseCount('financial_transactions', 0);
        Http::assertNothingSent();
    }

    private function eligibleRedemption(int $userId, string $hash, string $amount): TruemoneyVoucherRedemption
    {
        return TruemoneyVoucherRedemption::factory()->create([
            'user_id' => $userId,
            'voucher_hash' => $hash,
            'voucher_last4' => 'cdef',
            'amount' => $amount,
            'currency' => 'THB',
            'status' => TruemoneyRedemptionStatus::RedeemedUnconfirmed,
            'provider_status' => 'SUCCESS',
            'provider_payload' => [
                'http_status' => 200,
                'provider_status' => 'SUCCESS',
                'amount' => $amount,
                'currency' => 'THB',
                'risk_decision' => 'allow',
            ],
        ]);
    }
}
