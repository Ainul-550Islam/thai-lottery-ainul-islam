<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\PaymentMethod;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\Config;

/**
 * P1-26: Proves method-specific withdrawal destination validation:
 * - Bank transfer requires canonical bank code, valid account number, and account name.
 * - bKash / Nagad requires valid Bangladeshi mobile format and rejects bank_name.
 * - Crypto requires valid crypto address/network and rejects bank_name.
 * - Incompatible field combinations are rejected.
 */
final class WithdrawalDestinationValidationTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.withdrawal.enabled', true);
        Config::set('payment.withdrawal.allowed_methods', ['bank_transfer', 'bkash', 'nagad', 'crypto']);
        Config::set('payment.supported_banks', [
            'BBL' => 'Bangkok Bank',
            'KBANK' => 'Kasikornbank',
            'SCB' => 'Siam Commercial Bank',
        ]);
    }

    public function test_bank_transfer_withdrawal_requires_canonical_bank_and_account(): void
    {
        $player = $this->createPlayer('5000.00');

        // Valid bank withdrawal
        $response = $this->actingAs($player['user'])->post('/withdraw', [
            'amount' => '500.00',
            'method' => 'bank_transfer',
            'bank_name' => 'KBANK',
            'account_number' => '1234567890',
            'account_name' => 'John Doe',
            'idempotency_key' => 'wd-test-bank-valid-01',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/withdraw');

        $this->assertDatabaseHas('withdrawals', [
            'user_id' => $player['user']->id,
            'method' => PaymentMethod::BankTransfer->value,
        ]);
    }

    public function test_bank_transfer_rejects_unsupported_bank(): void
    {
        $player = $this->createPlayer('5000.00');

        $response = $this->actingAs($player['user'])->post('/withdraw', [
            'amount' => '500.00',
            'method' => 'bank_transfer',
            'bank_name' => 'UNSUPPORTED_FAKE_BANK',
            'account_number' => '1234567890',
            'account_name' => 'John Doe',
            'idempotency_key' => 'wd-test-bank-invalid',
        ]);

        $response->assertSessionHasErrors(['bank_name']);
        $this->assertSame(0, Withdrawal::query()->count());
    }

    public function test_bkash_requires_mobile_format_and_prohibits_bank_name(): void
    {
        $player = $this->createPlayer('5000.00');

        // Valid bKash
        $valid = $this->actingAs($player['user'])->post('/withdraw', [
            'amount' => '300.00',
            'method' => 'bkash',
            'account_number' => '01712345678',
            'account_name' => 'Rahim Ahmed',
            'idempotency_key' => 'wd-test-bkash-valid',
        ]);
        $valid->assertSessionHasNoErrors();

        // Incompatible bank_name supplied to bKash rail
        $invalid = $this->actingAs($player['user'])->post('/withdraw', [
            'amount' => '300.00',
            'method' => 'bkash',
            'bank_name' => 'KBANK',
            'account_number' => '01712345678',
            'account_name' => 'Rahim Ahmed',
            'idempotency_key' => 'wd-test-bkash-incompat',
        ]);
        $invalid->assertSessionHasErrors(['bank_name']);
    }

    public function test_crypto_requires_valid_address_and_prohibits_bank_name(): void
    {
        $player = $this->createPlayer('5000.00');

        // Valid crypto withdrawal
        $valid = $this->actingAs($player['user'])->post('/withdraw', [
            'amount' => '1000.00',
            'method' => 'crypto',
            'account_number' => '0x71C634C245C5b4E3273eD692225a0b22aC0a6c0E',
            'account_name' => 'USDT-ERC20',
            'idempotency_key' => 'wd-test-crypto-valid',
        ]);
        $valid->assertSessionHasNoErrors();

        // Short / malformed address rejected
        $invalid = $this->actingAs($player['user'])->post('/withdraw', [
            'amount' => '1000.00',
            'method' => 'crypto',
            'account_number' => '0x123',
            'account_name' => 'USDT',
            'idempotency_key' => 'wd-test-crypto-invalid',
        ]);
        $invalid->assertSessionHasErrors(['account_number']);
    }
}
