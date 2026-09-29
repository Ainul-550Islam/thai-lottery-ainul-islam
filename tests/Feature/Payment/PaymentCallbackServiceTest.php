<?php

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payment\PaymentCallbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * READ-ONLY BROWSER-RETURN PROJECTION (FINAL AUDIT #2).
 *
 * browserReturnProjection() is a VIEW over the payments paper, never a
 * state transition: it resolves only safe references, only for the owner,
 * and reports the internally recorded state. The tests pin both halves —
 * what it resolves, and that it cannot change anything.
 */
class PaymentCallbackServiceTest extends TestCase
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

    private function makePayment(Wallet $wallet, PaymentStatus $status, ?string $gatewayReference = null): Payment
    {
        $deposit = new Deposit();
        $deposit->fill([
            'reference_number' => 'DP-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => $wallet->user_id,
            'wallet_id' => $wallet->id,
            'method' => PaymentMethod::Stripe,
            'provider' => 'stripe',
            'currency' => $wallet->currency,
            'amount' => '500.00',
            'fee' => '0.00',
            'net_amount' => '500.00',
            'metadata' => [],
        ]);
        $deposit->uuid = (string) \Illuminate\Support\Str::uuid();
        $deposit->status = \App\Enums\DepositStatus::Pending;
        $deposit->save();

        $payment = new Payment();
        $payment->fill([
            'reference_number' => 'PAY-'.strtoupper(bin2hex(random_bytes(8))),
            'user_id' => $wallet->user_id,
            'payable_type' => Deposit::class,
            'payable_id' => $deposit->getKey(),
            'method' => PaymentMethod::Stripe,
            'status' => $status,
            'currency' => Currency::THB,
            'amount' => '500.00',
            'fee' => '0.00',
            'gateway' => 'stripe',
            'gateway_reference' => $gatewayReference ?? 'cs_test_'.strtoupper(bin2hex(random_bytes(6))),
            'metadata' => ['deposit_reference' => $deposit->reference_number],
        ]);
        $payment->save();

        return $payment;
    }

    public function test_resolves_by_payment_reference_and_reports_the_internal_state(): void
    {
        ['user' => $user, 'wallet' => $wallet] = $this->player();
        $payment = $this->makePayment($wallet, PaymentStatus::Pending);

        $projection = $this->app->make(PaymentCallbackService::class)
            ->browserReturnProjection($payment->reference_number, null, null, (int) $user->id);

        $this->assertTrue($projection['found']);
        $this->assertSame('pending', $projection['state']);
        $this->assertFalse($projection['paid']);
        $this->assertSame($payment->getKey(), $projection['payment']->getKey());
    }

    public function test_resolves_by_deposit_reference_and_by_gateway_session_reference(): void
    {
        ['user' => $user, 'wallet' => $wallet] = $this->player();
        $payment = $this->makePayment($wallet, PaymentStatus::Captured, 'cs_test_LOOKUP');

        $service = $this->app->make(PaymentCallbackService::class);

        $byDeposit = $service->browserReturnProjection(null, (string) $payment->metadata['deposit_reference'], null, (int) $user->id);
        $this->assertTrue($byDeposit['found']);
        $this->assertTrue($byDeposit['paid']);
        $this->assertSame('confirmed', $byDeposit['state']);

        $bySession = $service->browserReturnProjection(null, null, 'cs_test_LOOKUP', (int) $user->id);
        $this->assertTrue($bySession['found']);
        $this->assertTrue($bySession['paid']);
    }

    public function test_captured_is_the_only_state_reported_as_paid(): void
    {
        ['user' => $user, 'wallet' => $wallet] = $this->player();

        $service = $this->app->make(PaymentCallbackService::class);

        foreach ([PaymentStatus::Pending, PaymentStatus::Authorized, PaymentStatus::Failed, PaymentStatus::Cancelled] as $status) {
            $payment = $this->makePayment($wallet, $status);
            $projection = $service->browserReturnProjection($payment->reference_number, null, null, (int) $user->id);
            $this->assertFalse($projection['paid'], $status->value.' must never read as paid.');
        }
    }

    public function test_state_vocabulary_covers_failed_cancelled_and_refunded(): void
    {
        ['user' => $user, 'wallet' => $wallet] = $this->player();

        $service = $this->app->make(PaymentCallbackService::class);

        $failed = $this->makePayment($wallet, PaymentStatus::Failed);
        $this->assertSame('failed', $service->browserReturnProjection($failed->reference_number, null, null, (int) $user->id)['state']);

        $cancelled = $this->makePayment($wallet, PaymentStatus::Cancelled);
        $this->assertSame('cancelled', $service->browserReturnProjection($cancelled->reference_number, null, null, (int) $user->id)['state']);

        $refunded = $this->makePayment($wallet, PaymentStatus::Refunded);
        $this->assertSame('updated', $service->browserReturnProjection($refunded->reference_number, null, null, (int) $user->id)['state']);
    }

    public function test_another_players_reference_is_reported_as_not_found(): void
    {
        ['user' => $owner, 'wallet' => $ownerWallet] = $this->player();
        ['user' => $intruder] = $this->player();

        $payment = $this->makePayment($ownerWallet, PaymentStatus::Captured);

        $projection = $this->app->make(PaymentCallbackService::class)
            ->browserReturnProjection($payment->reference_number, null, null, (int) $intruder->id);

        $this->assertFalse($projection['found']);
        $this->assertSame('reference_not_found', $projection['reason']);
        unset($owner);
    }

    public function test_unknown_references_are_reported_as_not_found(): void
    {
        ['user' => $user] = $this->player();

        $service = $this->app->make(PaymentCallbackService::class);

        $this->assertFalse($service->browserReturnProjection('PAY-DOESNOTEXIST', null, null, (int) $user->id)['found']);
        $this->assertFalse($service->browserReturnProjection(null, 'DP-DOESNOTEXIST', null, (int) $user->id)['found']);
        $this->assertFalse($service->browserReturnProjection(null, null, 'cs_unknown_session', (int) $user->id)['found']);
        $this->assertFalse($service->browserReturnProjection(null, null, null, (int) $user->id)['found']);
    }

    public function test_the_projection_is_read_only_and_changes_nothing(): void
    {
        ['user' => $user, 'wallet' => $wallet] = $this->player();
        $payment = $this->makePayment($wallet, PaymentStatus::Pending);

        $before = [
            'payments' => Payment::query()->count(),
            'deposits' => Deposit::query()->count(),
            'payment_status' => $payment->status,
            'deposit_status' => $payment->payable->status,
            'wallet_balance' => (string) $wallet->fresh()->balance,
        ];

        for ($i = 0; $i < 3; $i++) {
            $this->app->make(PaymentCallbackService::class)
                ->browserReturnProjection($payment->reference_number, null, null, (int) $user->id);
        }

        $this->assertSame($before['payments'], Payment::query()->count());
        $this->assertSame($before['deposits'], Deposit::query()->count());
        $this->assertSame($before['payment_status'], $payment->fresh()->status);
        $this->assertSame($before['deposit_status'], $payment->payable->fresh()->status);
        $this->assertSame($before['wallet_balance'], (string) $wallet->fresh()->balance);
    }
}
