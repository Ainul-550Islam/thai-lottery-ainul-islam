<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Http\Requests\Payment\VerifySlipRequest;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ThaiRailsRoutesTest extends TestCase
{
    public function test_slip_image_validation_respects_the_selected_providers_documented_limit(): void
    {
        config(['slip_verification.provider' => 'easyslip']);
        self::assertContains('max:3906', (new VerifySlipRequest)->rules()['slip_image']);

        config(['slip_verification.provider' => 'slipok']);
        self::assertContains('max:5120', (new VerifySlipRequest)->rules()['slip_image']);
    }

    public function test_payout_callback_signature_headers_match_the_shared_replay_guard_configuration(): void
    {
        foreach (['scb', 'kbank'] as $bank) {
            self::assertSame(
                config('thai_payout.banks.'.$bank.'.callback_signature_header'),
                config('payment.gateways.'.$bank.'.signature_header'),
            );
        }
    }

    public function test_all_five_routes_are_registered_with_the_required_middleware(): void
    {
        $expected = [
            'api.v1.deposit.truemoney.redeem' => ['auth:sanctum', 'active', 'throttle:deposit', 'adult', 'legal.current'],
            'api.v1.deposit.slip.verify' => ['auth:sanctum', 'active', 'throttle:deposit', 'adult', 'legal.current'],
            'api.v1.deposit.slip.show' => ['auth:sanctum', 'active'],
            'api.v1.payments.payout-webhook.scb' => ['throttle:webhook', 'webhook.signature:scb'],
            'api.v1.payments.payout-webhook.kbank' => ['throttle:webhook', 'webhook.signature:kbank'],
        ];

        foreach ($expected as $name => $requiredMiddleware) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, 'Missing route '.$name);
            self::assertContains('api', $route->middleware());
            foreach ($requiredMiddleware as $middleware) {
                self::assertContains($middleware, $route->middleware(), 'Missing '.$middleware.' on '.$name);
            }
        }

        self::assertCount(5, array_filter(
            Route::getRoutes()->getRoutes(),
            static fn ($route): bool => str_starts_with((string) $route->getName(), 'api.v1.deposit.truemoney.')
                || str_starts_with((string) $route->getName(), 'api.v1.deposit.slip.')
                || str_starts_with((string) $route->getName(), 'api.v1.payments.payout-webhook.'),
        ));
    }

    public function test_player_endpoints_require_authentication_and_webhooks_reject_missing_or_invalid_signatures_generically(): void
    {
        Http::fake();
        $this->postJson(route('api.v1.deposit.truemoney.redeem'), ['voucher_url' => 'https://gift.truemoney.com/campaign/?v='.str_repeat('a', 32)])
            ->assertUnauthorized();
        $this->postJson(route('api.v1.deposit.slip.verify'), ['qr_payload' => 'QR', 'amount' => '1.00'])
            ->assertUnauthorized();
        $this->getJson(route('api.v1.deposit.slip.show', ['verification' => 999999]))
            ->assertUnauthorized();

        $this->postJson(route('api.v1.payments.payout-webhook.scb'), ['reference' => 'WD-UNKNOWN'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'payout_webhook_rejected');
        $this->postJson(route('api.v1.payments.payout-webhook.kbank'), ['reference' => 'WD-UNKNOWN'], [
            'X-Signature' => 'not-a-valid-signature',
            'X-Timestamp' => (string) time(),
        ])->assertUnauthorized()->assertJsonPath('error.code', 'payout_webhook_rejected');

        config([
            'thai_payout.banks.scb.webhook_secret' => 'route-test-secret',
            'thai_payout.banks.scb.callback_signature_header' => 'X-Signature',
            'thai_payout.banks.scb.callback_timestamp_header' => 'X-Timestamp',
        ]);
        $reference = 'WD-UNKNOWN-SIGNED-'.strtoupper(bin2hex(random_bytes(4)));
        $body = json_encode([
            'reference' => $reference,
            'state' => 'SETTLED',
            'providerReference' => 'SCB-UNKNOWN-SIGNED-'.strtoupper(bin2hex(random_bytes(4))),
            'amount' => '10.00',
            'currency' => 'THB',
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'route-test-secret');
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_SIGNATURE' => $signature,
        ];
        $this->call('POST', route('api.v1.payments.payout-webhook.scb'), [], [], [], $server, $body)
            ->assertOk()->assertJsonPath('data.accepted', true);
        $this->call('POST', route('api.v1.payments.payout-webhook.scb'), [], [], [], $server, $body)
            ->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('data.replayed', true);
        Http::assertNothingSent();
    }

    public function test_webhook_named_rate_limit_returns_429_after_its_configured_ceiling(): void
    {
        config([
            'thai_payout.banks.scb.webhook_secret' => 'route-throttle-secret',
            'thai_payout.banks.scb.callback_signature_header' => 'X-Signature',
            'thai_payout.banks.scb.callback_timestamp_header' => 'X-Timestamp',
        ]);
        RateLimiter::for('webhook', static fn (Request $request): Limit => Limit::perMinute(1)
            ->by('thai-rails-rate-test:'.$request->ip()));
        Http::fake();

        $body = json_encode([
            'reference' => 'WD-RATE-TEST-'.strtoupper(bin2hex(random_bytes(4))),
            'state' => 'ACCEPTED',
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'route-throttle-secret');
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '203.0.113.'.random_int(1, 254),
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_SIGNATURE' => $signature,
        ];
        $uri = route('api.v1.payments.payout-webhook.scb');

        $this->call('POST', $uri, [], [], [], $server, $body)->assertOk();
        $this->call('POST', $uri, [], [], [], $server, $body)->assertStatus(429);
        Http::assertNothingSent();
    }
}
