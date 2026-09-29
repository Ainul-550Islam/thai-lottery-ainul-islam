<?php

namespace Tests\Feature\Web;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BROWSER PAYMENT RETURN PAGES (FINAL AUDIT #2).
 *
 * /payment/success, /payment/failure, /payment/cancel and /payment/pending
 * are presentation only. The tests pin the security contract:
 *  - landing on /payment/success can NEVER display a paid state unless the
 *    internal payments paper already says captured;
 *  - query parameters are lookup keys at most — never proof;
 *  - another player's reference does not exist as far as this viewer is
 *    concerned;
 *  - unauthenticated visitors are sent to login, and nothing is mutated.
 */
class BrowserPaymentCallbackTest extends TestCase
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

    public function test_all_four_return_routes_require_authentication(): void
    {
        foreach (['/payment/success', '/payment/failure', '/payment/cancel', '/payment/pending'] as $path) {
            $response = $this->get($path);
            $response->assertRedirect();
            $this->assertStringContainsString('/login', $response->headers->get('Location'));
        }
    }

    public function test_all_four_return_routes_render_for_the_authenticated_owner(): void
    {
        ['user' => $user] = $this->player();

        foreach (['success', 'failure', 'cancel', 'pending'] as $context) {
            $this->actingAs($user)
                ->get('/payment/'.$context)
                ->assertOk();
        }
    }

    public function test_success_route_shows_pending_when_the_payment_is_not_confirmed(): void
    {
        ['user' => $user, 'wallet' => $wallet] = $this->player();
        $payment = $this->makePayment($wallet, PaymentStatus::Pending);

        $page = (string) $this->actingAs($user)
            ->get('/payment/success?reference='.$payment->metadata['deposit_reference'])
            ->assertOk()
            ->getContent();

        // The landing URL says success; the record says pending. The page
        // must side with the record — never display the confirmed state.
        $this->assertStringContainsString('Pending confirmation', $page);
        $this->assertStringNotContainsString('This payment is confirmed', $page);
    }

    public function test_success_route_shows_confirmed_only_when_the_record_is_captured(): void
    {
        ['user' => $user, 'wallet' => $wallet] = $this->player();
        $payment = $this->makePayment($wallet, PaymentStatus::Captured);

        $page = (string) $this->actingAs($user)
            ->get('/payment/success?reference='.$payment->metadata['deposit_reference'])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Confirmed', $page);
        $this->assertStringContainsString('This payment is confirmed', $page);
    }

    public function test_forged_query_flags_cannot_manufacture_a_paid_state(): void
    {
        ['user' => $user, 'wallet' => $wallet] = $this->player();
        $payment = $this->makePayment($wallet, PaymentStatus::Pending);

        $page = (string) $this->actingAs($user)
            ->get('/payment/success?reference='.$payment->metadata['deposit_reference'].'&status=paid&success=1&paid=true')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Pending confirmation', $page);
        $this->assertStringNotContainsString('This payment is confirmed', $page);
    }

    public function test_a_reference_without_a_matching_payment_shows_the_not_found_copy(): void
    {
        ['user' => $user] = $this->player();

        $page = (string) $this->actingAs($user)
            ->get('/payment/success?reference=DP-NOTHING-HERE')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Payment reference not found', $page);
        $this->assertStringNotContainsString('This payment is confirmed', $page);
    }

    public function test_another_players_reference_is_not_found_for_this_viewer(): void
    {
        ['user' => $owner, 'wallet' => $ownerWallet] = $this->player();
        ['user' => $intruder] = $this->player();

        $payment = $this->makePayment($ownerWallet, PaymentStatus::Captured);

        $page = (string) $this->actingAs($intruder)
            ->get('/payment/success?reference='.$payment->metadata['deposit_reference'])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Payment reference not found', $page);
        $this->assertStringNotContainsString('Confirmed', $page);
        $this->assertStringNotContainsString((string) $payment->reference_number, $page);
        unset($owner);
    }

    public function test_cancel_route_reports_a_cancelled_payment_honestly(): void
    {
        ['user' => $user, 'wallet' => $wallet] = $this->player();
        $payment = $this->makePayment($wallet, PaymentStatus::Cancelled);

        $page = (string) $this->actingAs($user)
            ->get('/payment/cancel?reference='.$payment->metadata['deposit_reference'])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Cancelled', $page);
        $this->assertStringNotContainsString('This payment is confirmed', $page);
    }

    public function test_failure_route_reports_a_failed_payment_honestly(): void
    {
        ['user' => $user, 'wallet' => $wallet] = $this->player();
        $payment = $this->makePayment($wallet, PaymentStatus::Failed);

        $page = (string) $this->actingAs($user)
            ->get('/payment/failure?reference='.$payment->metadata['deposit_reference'])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Failed', $page);
        $this->assertStringNotContainsString('This payment is confirmed', $page);
    }

    public function test_the_return_pages_never_mutate_payment_state(): void
    {
        ['user' => $user, 'wallet' => $wallet] = $this->player();
        $payment = $this->makePayment($wallet, PaymentStatus::Pending);

        foreach (['success', 'failure', 'cancel', 'pending'] as $context) {
            $this->actingAs($user)
                ->get('/payment/'.$context.'?reference='.$payment->metadata['deposit_reference']);
        }

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame('1000.00', (string) $wallet->fresh()->balance);
    }
}
