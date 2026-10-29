<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\TruemoneyVoucherRedemption;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

final class RealMoneyAdmissionBoundaryTest extends PaymentTestCase
{
    public function test_closed_launch_switch_cannot_create_deposit_or_call_stripe(): void
    {
        $player = $this->createAdmittedPlayer('500.00', Currency::THB);
        Sanctum::actingAs($player['user']);
        Http::preventStrayRequests();
        Http::fake(['api.stripe.com/*' => Http::response([
            'id' => 'cs_test_closed_switch', 'url' => 'https://example.invalid/closed-switch-checkout',
        ], 200)]);
        config(['real_money.enabled' => false]);
        $response = $this->postJson('/api/v1/deposits', [
            'idempotency_key' => 'closed-real-money-deposit-0001', 'amount' => '100.00',
            'method' => 'stripe', 'currency' => 'THB',
        ]);
        self::assertSame(['deposits' => 0, 'payments' => 0, 'provider_requests' => 0], [
            'deposits' => Deposit::query()->count(), 'payments' => Payment::query()->count(),
            'provider_requests' => Http::recorded()->count(),
        ], 'A closed deployment must not create new paper or call the provider.');
        self::assertSame('500.00', (string) $player['wallet']->fresh()->balance);
        Http::assertNothingSent();
        $response->assertStatus(503)->assertJsonPath('error.code', 'real_money_disabled');
    }

    public function test_closed_switch_keeps_exact_deposit_request_replay_without_new_paper(): void
    {
        $player = $this->createPlayer('500.00', Currency::THB);
        $service = app(DepositService::class);
        $amount = Money::of('100.00', Currency::THB);
        $first = $service->request($player['wallet'], $amount, PaymentMethod::BankTransfer, 'closed-switch-deposit-replay');
        config(['real_money.enabled' => false]);
        $replay = $service->request($player['wallet'], $amount, PaymentMethod::BankTransfer, 'closed-switch-deposit-replay');
        self::assertSame($first->id, $replay->id);
        self::assertSame(1, Deposit::query()->count());
        self::assertSame(0, Payment::query()->count());
        self::assertSame('500.00', (string) $player['wallet']->fresh()->balance);
    }

    public function test_caller_metadata_cannot_impersonate_a_consumed_voucher(): void
    {
        $player = $this->createPlayer('0.00', Currency::THB);
        config(['real_money.enabled' => false]);
        $caught = null;
        try {
            app(DepositService::class)->request($player['wallet'], Money::of('50.00', Currency::THB),
                PaymentMethod::BankTransfer, 'truemoney-'.str_repeat('a', 64), [
                    'provider_reference' => 'TMN-'.str_repeat('a', 16),
                    'metadata' => ['thai_rails' => ['provider_kind' => 'truemoney_voucher', 'voucher_hash' => str_repeat('a', 64)]],
                ]);
        } catch (FinancialException $exception) {
            $caught = $exception;
        }
        self::assertInstanceOf(FinancialException::class, $caught);
        self::assertSame('real_money_disabled', $caught->errorCode());
        self::assertSame(0, Deposit::query()->count());
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
    }

    public function test_consumed_voucher_exception_rechecks_locked_receipt_identity_amount_and_risk(): void
    {
        $player = $this->createPlayer('0.00', Currency::THB);
        $other = $this->createPlayer('0.00', Currency::THB);
        $receipt = TruemoneyVoucherRedemption::factory()->create([
            'user_id' => $player['user']->id, 'voucher_hash' => hash('sha256', 'locked-voucher-proof'),
            'amount' => '50.00', 'currency' => 'THB',
            'status' => \App\Enums\TruemoneyRedemptionStatus::RedeemedUnconfirmed,
            'provider_status' => 'SUCCESS', 'provider_payload' => [
                'http_status' => 200, 'provider_status' => 'SUCCESS', 'amount' => '50.00',
                'currency' => 'THB', 'risk_decision' => 'allow',
            ],
        ]);
        config(['real_money.enabled' => false]);
        $service = app(DepositService::class);
        $calls = [
            // Without a transaction, no row lock can protect the receipt.
            fn () => $service->requestFromConfirmedTrueMoneyVoucher($player['wallet'], Money::of('50.00', Currency::THB), $receipt),
            fn () => DB::transaction(fn () => $service->requestFromConfirmedTrueMoneyVoucher($other['wallet'], Money::of('50.00', Currency::THB), $receipt)),
            fn () => DB::transaction(fn () => $service->requestFromConfirmedTrueMoneyVoucher($player['wallet'], Money::of('51.00', Currency::THB), $receipt)),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('Unproven voucher identity or amount was allowed.');
            } catch (FinancialException $exception) {
                self::assertSame('truemoney_receipt_unproven', $exception->errorCode());
            }
        }
        $stale = $receipt;
        $payload = $receipt->provider_payload;
        $payload['risk_decision'] = 'review';
        TruemoneyVoucherRedemption::query()->whereKey($receipt->id)->update(['provider_payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        try {
            DB::transaction(fn () => $service->requestFromConfirmedTrueMoneyVoucher($player['wallet'], Money::of('50.00', Currency::THB), $stale));
            self::fail('A stale in-memory allow decision was accepted after the stored receipt changed.');
        } catch (FinancialException $exception) {
            self::assertSame('truemoney_receipt_unproven', $exception->errorCode());
        }
        self::assertSame(0, Deposit::query()->count());
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame('0.00', (string) $other['wallet']->fresh()->balance);
    }

    public function test_dormant_agent_pos_entry_point_refuses_before_lookup_or_wallet_mutation_when_closed(): void
    {
        $player = $this->createPlayer('500.00', Currency::THB);
        config(['real_money.enabled' => false]);
        $caught = null;
        try {
            app(\App\Services\Agent\AgentPosTicketIssuanceService::class)->issuePosTickets(
                'nonexistent-agent', 'nonexistent-draw', [['number' => '12', 'bet_type' => 'two_up', 'amount' => '10.00']], '127.0.0.1',
            );
        } catch (FinancialException $exception) {
            $caught = $exception;
        }
        self::assertInstanceOf(FinancialException::class, $caught);
        self::assertSame('real_money_disabled', $caught->errorCode());
        self::assertSame('500.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
    }

    public function test_low_level_new_deposit_request_respects_the_same_launch_switch(): void
    {
        $player = $this->createPlayer('500.00', Currency::THB);
        config(['real_money.enabled' => false]);
        $caught = null;
        try {
            app(DepositService::class)->request($player['wallet'], Money::of('100.00', Currency::THB),
                PaymentMethod::BankTransfer, 'closed-real-money-direct-0001');
        } catch (FinancialException $exception) {
            $caught = $exception;
        }
        self::assertSame(0, Deposit::query()->count());
        self::assertSame('500.00', (string) $player['wallet']->fresh()->balance);
        self::assertInstanceOf(FinancialException::class, $caught);
        self::assertSame('real_money_disabled', $caught->errorCode());
    }
}
