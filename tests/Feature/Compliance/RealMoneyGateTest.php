<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Enums\PaymentMethod;
use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Models\User;
use App\Services\Compliance\RealMoneyGate;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * The real-money launch gate defaults closed. Tests in this suite run with the gate open (see
 * Tests\TestCase); these tests close it deliberately and prove each money path refuses.
 */
final class RealMoneyGateTest extends TestCase
{
    use DatabaseTruncation;

    public function test_the_gate_defaults_closed_in_config(): void
    {
        // config/real_money.php reads env('REAL_MONEY_ENABLED', false). With no env var set, the
        // shipped default must be closed.
        if (env('REAL_MONEY_ENABLED') === null) {
            $shipped = require __DIR__.'/../../../config/real_money.php';
            $this->assertFalse($shipped['enabled']);
        }
    }

    public function test_the_gate_refuses_unless_exactly_true(): void
    {
        config(['real_money.enabled' => false]);
        $gate = app(RealMoneyGate::class);
        $this->assertFalse($gate->isOpen());

        try {
            $gate->assertOpen('withdrawal');
            $this->fail('The gate let a withdrawal through while closed.');
        } catch (FinancialException $e) {
            $this->assertSame('real_money_disabled', $e->errorCode());
        }

        config(['real_money.enabled' => 'yes']);
        $this->assertFalse(app(RealMoneyGate::class)->isOpen(), 'A truthy string must not open the gate.');
    }

    public function test_a_withdrawal_is_refused_while_the_gate_is_closed_and_moves_nothing(): void
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::THB);
        app(WalletService::class)->credit($wallet, '500.00', 'seed', 'gate-seed-0000000001', 'Seed');

        config(['real_money.enabled' => false]);

        try {
            app(WithdrawalService::class)->request(
                wallet: $wallet->refresh(),
                amount: Money::of('100.00', Currency::THB),
                method: PaymentMethod::BankTransfer,
                options: ['payout_details' => ['bank_name' => 'SCB', 'account_number' => '1234567890', 'account_name' => 'Player']],
            );
            $this->fail('A withdrawal was accepted while real money is disabled.');
        } catch (FinancialException $e) {
            $this->assertSame('real_money_disabled', $e->errorCode());
        }

        $this->assertSame('500.00', (string) $wallet->refresh()->balance);
        $this->assertSame('0.00', (string) $wallet->locked_balance);
    }
}
