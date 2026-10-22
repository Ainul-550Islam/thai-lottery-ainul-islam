<?php

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\Money;
use App\Services\Payment\PaymentInitiationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ORCHESTRATED DEPOSIT INITIATION (FINAL AUDIT #1, #5).
 *
 * The initiation service is the single doorway every deposit goes through:
 * capability gate FIRST (method allowed, gateway enabled + deposit-capable,
 * currency supported) — so a refusal leaves ZERO rows — then the deposit
 * intent, the provider session and the Payment aggregate, idempotently.
 */
class PaymentInitiationServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{user: User, wallet: Wallet}
     */
    private function player(): array
    {
        $user = User::factory()->create();

        $wallet = Wallet::factory()
            ->for($user)
            ->withBalance('1000.00')
            ->create(['currency' => 'THB']);

        return ['user' => $user, 'wallet' => $wallet];
    }

    private function service(): PaymentInitiationService
    {
        return $this->app->make(PaymentInitiationService::class);
    }

    /**
     * The capability refusals must persist NOTHING: the counts of deposits
     * and payments are captured before the attempt and asserted unchanged
     * after it (order-independent under the shared file-backed test
     * database, and exactly the contract the audit asks for).
     *
     * @return array{deposits: int, payments: int}
     */
    private function paperCounts(): array
    {
        return [
            'deposits' => Deposit::query()->count(),
            'payments' => Payment::query()->count(),
        ];
    }

    private function assertPaperUnchanged(array $before): void
    {
        $this->assertSame($before['deposits'], Deposit::query()->count(), 'A refused initiation must not persist a deposit.');
        $this->assertSame($before['payments'], Payment::query()->count(), 'A refused initiation must not persist a payment.');
    }

    private function enableStripe(): void
    {
        config([
            'payment.deposit.allowed_methods' => ['stripe', 'bkash', 'nagad', 'crypto'],
            'payment.gateways.stripe.enabled' => true,
            'payment.gateways.stripe.secret' => 'sk_test_example',
            'payment.gateways.stripe.supported_currencies' => ['THB', 'USD'],
        ]);
    }

    public function test_happy_path_creates_deposit_and_payment_with_provider_reference(): void
    {
        ['wallet' => $wallet] = $this->player();
        $this->enableStripe();

        Http::fake([
            'api.stripe.com/*' => Http::response([
                'id' => 'cs_test_SESSION123',
                'url' => 'https://checkout.stripe.com/pay/cs_test_SESSION123',
            ], 200),
        ]);

        $result = $this->service()->initiateDeposit(
            wallet: $wallet,
            amount: Money::of('500.00', Currency::THB),
            method: PaymentMethod::Stripe,
            idempotencyKey: 'key-'.Str::uuid()->toString(),
        );

        $this->assertInstanceOf(Deposit::class, $result['deposit']);
        $this->assertInstanceOf(Payment::class, $result['payment']);
        $this->assertTrue($result['gateway_response']->successful);
        $this->assertSame('https://checkout.stripe.com/pay/cs_test_SESSION123', $result['gateway_response']->redirectUrl);
        $this->assertSame('cs_test_SESSION123', (string) $result['payment']->gateway_reference);

        $this->assertDatabaseHas('deposits', [
            'wallet_id' => $wallet->id,
            'method' => 'stripe',
            'amount' => '500.00',
            'currency' => 'THB',
            'status' => DepositStatus::Pending->value,
        ]);

        $this->assertDatabaseHas('payments', [
            'payable_type' => Deposit::class,
            'payable_id' => $result['deposit']->getKey(),
            'status' => PaymentStatus::Pending->value,
            'gateway' => 'stripe',
            'gateway_reference' => 'cs_test_SESSION123',
        ]);
    }

    public function test_disabled_gateway_is_refused_before_any_record_exists(): void
    {
        ['wallet' => $wallet] = $this->player();

        config([
            'payment.deposit.allowed_methods' => ['stripe'],
            'payment.gateways.stripe.enabled' => false,
        ]);

        $before = $this->paperCounts();

        try {
            $this->service()->initiateDeposit(
                wallet: $wallet,
                amount: Money::of('500.00', Currency::THB),
                method: PaymentMethod::Stripe,
                idempotencyKey: 'key-'.Str::uuid()->toString(),
            );
            $this->fail('A disabled gateway must be refused.');
        } catch (FinancialException $exception) {
            $this->assertSame('payment_gateway_disabled', $exception->errorCode());
        }

        $this->assertPaperUnchanged($before);
    }

    public function test_method_outside_the_allowed_list_is_refused_before_any_record_exists(): void
    {
        ['wallet' => $wallet] = $this->player();

        config([
            'payment.deposit.allowed_methods' => ['stripe'],
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.gateways.bank_transfer.supports_deposit' => true,
        ]);

        $before = $this->paperCounts();

        try {
            $this->service()->initiateDeposit(
                wallet: $wallet,
                amount: Money::of('500.00', Currency::THB),
                method: PaymentMethod::BankTransfer,
                idempotencyKey: 'key-'.Str::uuid()->toString(),
            );
            $this->fail('A method outside the allowed list must be refused.');
        } catch (FinancialException $exception) {
            $this->assertSame('payment_method_not_allowed', $exception->errorCode());
        }

        $this->assertPaperUnchanged($before);
    }

    public function test_unsupported_currency_is_refused_before_any_record_exists(): void
    {
        ['wallet' => $wallet] = $this->player();

        config([
            'payment.deposit.allowed_methods' => ['stripe'],
            'payment.gateways.stripe.enabled' => true,
            'payment.gateways.stripe.supported_currencies' => ['USD'],
        ]);

        $before = $this->paperCounts();

        try {
            $this->service()->initiateDeposit(
                wallet: $wallet,
                amount: Money::of('500.00', Currency::THB),
                method: PaymentMethod::Stripe,
                idempotencyKey: 'key-'.Str::uuid()->toString(),
            );
            $this->fail('An unsupported currency must be refused.');
        } catch (FinancialException $exception) {
            $this->assertSame('unsupported_gateway_currency', $exception->errorCode());
        }

        $this->assertPaperUnchanged($before);
    }

    public function test_replaying_the_same_idempotency_key_returns_the_same_deposit_and_payment(): void
    {
        ['wallet' => $wallet] = $this->player();
        $this->enableStripe();

        Http::fake([
            'api.stripe.com/*' => Http::response([
                'id' => 'cs_test_SESSION_REPLAY',
                'url' => 'https://checkout.stripe.com/pay/cs_test_SESSION_REPLAY',
            ], 200),
        ]);

        $before = $this->paperCounts();
        $key = 'key-'.Str::uuid()->toString();

        $first = $this->service()->initiateDeposit(
            wallet: $wallet,
            amount: Money::of('500.00', Currency::THB),
            method: PaymentMethod::Stripe,
            idempotencyKey: $key,
        );

        $second = $this->service()->initiateDeposit(
            wallet: $wallet,
            amount: Money::of('500.00', Currency::THB),
            method: PaymentMethod::Stripe,
            idempotencyKey: $key,
        );

        $this->assertSame($first['deposit']->getKey(), $second['deposit']->getKey());
        $this->assertSame($first['payment']->getKey(), $second['payment']->getKey());

        // Exactly ONE new deposit and ONE new payment for the two calls.
        $this->assertSame($before['deposits'] + 1, Deposit::query()->count());
        $this->assertSame($before['payments'] + 1, Payment::query()->count());
    }
}
