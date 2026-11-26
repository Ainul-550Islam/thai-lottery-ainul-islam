<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Currency;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalService;
use Closure;
use Tests\TestCase;
use Throwable;

final class BdtLimitBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'finance.deposit.min' => '300.00',
            'finance.deposit.max' => '40000.00',
            'finance.withdrawal.min' => '1000.00',
            'finance.withdrawal.max' => '40000.00',
        ]);
    }

    public function test_deposit_limits_are_300_to_40000_bdt(): void
    {
        $service = app(DepositService::class);

        foreach (['300.00', '300.01', '39999.99', '40000.00'] as $ok) {
            $service->assertAmountWithinLimits(Money::of($ok, Currency::BDT));
        }

        foreach (['0.01', '299.99', '40000.01', '100000.00'] as $bad) {
            $this->assertRefused(static fn () => $service->assertAmountWithinLimits(Money::of($bad, Currency::BDT)), $bad);
        }

        $this->addToAssertionCount(4);
    }

    public function test_withdrawal_limits_are_1000_to_40000_bdt(): void
    {
        $service = app(WithdrawalService::class);

        foreach (['1000.00', '1000.01', '39999.99', '40000.00'] as $ok) {
            $service->assertAmountWithinLimits(Money::of($ok, Currency::BDT));
        }

        foreach (['0.01', '999.99', '40000.01', '100000.00'] as $bad) {
            $this->assertRefused(static fn () => $service->assertAmountWithinLimits(Money::of($bad, Currency::BDT)), $bad);
        }

        $this->addToAssertionCount(4);
    }

    private function assertRefused(Closure $call, string $amount): void
    {
        try {
            $call();
        } catch (Throwable) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail("Expected {$amount} BDT to be refused as out of range.");
    }
}
