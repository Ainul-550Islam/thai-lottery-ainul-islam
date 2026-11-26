<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards;

use App\Enums\Currency;
use App\Enums\FreeBetVoucherStatus;
use App\Exceptions\FinancialException;
use App\Models\Bet;
use App\Models\User;
use App\Services\Finance\Money;
use App\Services\Rewards\FreeBetVoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class FreeBetVoucherServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_issues_validates_consumes_and_rejects_expired_or_foreign_vouchers(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $service = app(FreeBetVoucherService::class);

        $voucher = $service->issue(
            member: $owner,
            claim: null,
            amount: Money::of('150.00', Currency::THB),
            expiresAt: Carbon::now()->addDays(7),
        );

        $this->assertSame(FreeBetVoucherStatus::Active, $voucher->status);

        // Wrong owner rejected
        try {
            $service->validateUsable($stranger, $voucher->code, Money::of('100.00', Currency::THB));
            $this->fail('Expected foreign voucher validation to fail.');
        } catch (FinancialException $e) {
            $this->assertSame('free_bet_voucher_not_found', $e->errorCode());
        }

        // Stake exceeding voucher amount rejected
        try {
            $service->validateUsable($owner, $voucher->code, Money::of('200.00', Currency::THB));
            $this->fail('Expected insufficient voucher amount to fail.');
        } catch (FinancialException $e) {
            $this->assertSame('free_bet_voucher_insufficient_amount', $e->errorCode());
        }

        // Valid consumption
        $validated = $service->validateUsable($owner, $voucher->code, Money::of('150.00', Currency::THB));
        $bet = Bet::factory()->active()->create([
            'user_id' => $owner->getKey(),
            'stake_amount' => '150.00',
            'currency' => Currency::THB,
        ]);

        $consumed = $service->consume($validated, $bet);
        $this->assertSame(FreeBetVoucherStatus::Consumed, $consumed->status);
        $this->assertSame($bet->getKey(), $consumed->consumed_bet_id);

        // Cannot consume twice for a different bet
        $otherBet = Bet::factory()->active()->create([
            'user_id' => $owner->getKey(),
            'stake_amount' => '150.00',
            'currency' => Currency::THB,
        ]);
        try {
            $service->consume($consumed, $otherBet);
            $this->fail('Expected already-consumed voucher to fail.');
        } catch (FinancialException $e) {
            $this->assertSame('free_bet_voucher_already_consumed', $e->errorCode());
        }

        // Expired voucher rejected and swept by expireDueVouchers
        $expiredVoucher = $service->issue(
            member: $owner,
            claim: null,
            amount: Money::of('50.00', Currency::THB),
            expiresAt: Carbon::now()->subMinute(),
        );

        try {
            $service->validateUsable($owner, $expiredVoucher->code, Money::of('50.00', Currency::THB));
            $this->fail('Expected expired voucher to fail.');
        } catch (FinancialException $e) {
            $this->assertSame('free_bet_voucher_not_usable', $e->errorCode());
        }

        $this->assertSame(1, $service->expireDueVouchers());
        $this->assertSame(FreeBetVoucherStatus::Expired, $expiredVoucher->fresh()?->status);
    }
}
