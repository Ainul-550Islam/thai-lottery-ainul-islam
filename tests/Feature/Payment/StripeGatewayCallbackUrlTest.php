<?php

namespace Tests\Feature\Payment;

use App\DTOs\Payment\GatewayDepositResponse;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payment\Drivers\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * STRIPE CHECKOUT CALLBACK URLS (FINAL AUDIT #3).
 *
 * Stripe Checkout has a dedicated cancel_url. Sending the FAILURE url there
 * made every user abandonment land as an error; the cancel return must be
 * the CANCEL path (/payment/cancel), distinct from failure
 * (/payment/failure), so the browser-return page can tell the truth about
 * what happened. This test pins the request payload to the provider.
 */
class StripeGatewayCallbackUrlTest extends TestCase
{
    use RefreshDatabase;

    private function makeDeposit(Wallet $wallet): Deposit
    {
        $deposit = new Deposit();
        $deposit->fill([
            'reference_number' => 'DP-'.date('Ymd').'-TESTCXL',
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
        $deposit->status = DepositStatus::Pending;
        $deposit->save();

        return $deposit;
    }

    private function player(): Wallet
    {
        $user = User::factory()->create();

        return Wallet::factory()
            ->for($user)
            ->withBalance('1000.00')
            ->create(['currency' => 'THB']);
    }

    public function test_cancel_url_is_the_cancel_path_not_the_failure_path(): void
    {
        config([
            'payment.gateways.stripe.enabled' => true,
            'payment.gateways.stripe.secret' => 'sk_test_example',
            'payment.callback.success_url' => '/payment/success',
            'payment.callback.failure_url' => '/payment/failure',
            'payment.callback.cancel_url' => '/payment/cancel',
            'payment.callback.pending_url' => '/payment/pending',
        ]);

        Http::fake([
            'api.stripe.com/*' => Http::response([
                'id' => 'cs_test_CXL',
                'url' => 'https://checkout.stripe.com/pay/cs_test_CXL',
            ], 200),
        ]);

        $wallet = $this->player();
        $deposit = $this->makeDeposit($wallet);

        $response = $this->app->make(StripeGateway::class)->initiateDeposit($deposit);

        $this->assertTrue($response->successful);
        $this->assertSame('https://checkout.stripe.com/pay/cs_test_CXL', $response->redirectUrl);

        $captured = null;

        Http::assertSent(function ($request) use (&$captured): bool {
            if (str_contains($request->url(), 'checkout/sessions') === false) {
                return false;
            }

            $captured = $request->data();

            return true;
        });

        $this->assertNotNull($captured);
        $this->assertArrayHasKey('cancel_url', $captured);
        $this->assertArrayHasKey('success_url', $captured);

        // The regression the FINAL audit caught: cancel_url pointed at the
        // FAILURE return. It must be the CANCEL return now.
        $this->assertSame(
            rtrim(config('app.url'), '/').'/payment/cancel?reference='.$deposit->reference_number,
            $captured['cancel_url'],
        );
        $this->assertStringContainsString('/payment/cancel', (string) $captured['cancel_url']);
        $this->assertStringNotContainsString('/payment/failure', (string) $captured['cancel_url']);
        $this->assertStringContainsString('/payment/success', (string) $captured['success_url']);
    }

    public function test_missing_secret_key_fails_closed_without_calling_stripe(): void
    {
        config([
            'payment.gateways.stripe.enabled' => true,
            'payment.gateways.stripe.secret' => null,
        ]);

        Http::fake();

        $wallet = $this->player();
        $deposit = $this->makeDeposit($wallet);

        /** @var GatewayDepositResponse $response */
        $response = $this->app->make(StripeGateway::class)->initiateDeposit($deposit);

        $this->assertFalse($response->successful);
        $this->assertNull($response->redirectUrl);
        $this->assertStringContainsString('not configured', (string) $response->errorMessage);

        Http::assertNothingSent();
    }
}
