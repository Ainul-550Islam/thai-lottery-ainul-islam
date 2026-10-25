<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Http\Controllers\Api\V1\Payment\KBankWebhookController;
use App\Http\Controllers\Api\V1\Payment\ScbWebhookController;
use App\Models\WebhookReplayGuard;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

final class BankWebhookRoutesTest extends PaymentTestCase
{
    private const KBANK_SECRET = 'bank-route-kbank-secret';

    private const SCB_SECRET = 'bank-route-scb-secret';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.kbank.webhook_secret', self::KBANK_SECRET);
        Config::set('services.scb.webhook_secret', self::SCB_SECRET);
        Config::set('payment.webhook.replay_protection', true);
    }

    public function test_exact_bank_notification_urls_names_controllers_and_middleware_are_registered(): void
    {
        foreach ($this->lanes() as $bank => $lane) {
            $route = Route::getRoutes()->getByName('api.v1.payments.bank-webhook.'.$bank);
            $this->assertNotNull($route);
            $this->assertSame('api/v1/payments/bank-webhook/'.$bank, $route->uri());
            $this->assertContains('POST', $route->methods());
            $this->assertSame($lane['controller'].'@handle', $route->getActionName());
            $middleware = $route->gatherMiddleware();
            $this->assertContains('throttle:webhook', $middleware);
            $this->assertContains('webhook.signature:'.$bank, $middleware);
        }
    }

    public function test_unsigned_bad_signature_and_unconfigured_requests_have_one_generic_rejection(): void
    {
        foreach ($this->lanes() as $bank => $lane) {
            $payload = $this->payload($bank, 'REJECT-'.$bank);
            $url = '/api/v1/payments/bank-webhook/'.$bank;
            $expected = ['status' => 'ERROR', 'message' => 'Notification rejected.'];

            $this->postJson($url, $payload)->assertBadRequest()->assertExactJson($expected);
            $this->postJson($url, $payload, [$lane['header'] => str_repeat('0', 64)])
                ->assertBadRequest()->assertExactJson($expected);

            Config::set($lane['secret_key'], '');
            $this->postJson($url, $payload, [$lane['header'] => $this->signature($payload, $lane['secret'])])
                ->assertBadRequest()->assertExactJson($expected);
            Config::set($lane['secret_key'], $lane['secret']);
        }
    }

    public function test_accepted_replays_are_ignored_by_bank_transaction_id(): void
    {
        foreach ($this->lanes() as $bank => $lane) {
            $transactionId = 'REPLAY-'.$bank;
            $payload = $this->payload($bank, $transactionId);
            $signature = $this->signature($payload, $lane['secret']);
            $url = '/api/v1/payments/bank-webhook/'.$bank;

            $this->postJson($url, $payload, [$lane['header'] => $signature])
                ->assertOk()->assertJsonPath('result', 'ignored')->assertJsonPath('credited', false);
            $this->postJson($url, $payload, [$lane['header'] => $signature])
                ->assertOk()
                ->assertJsonPath('duplicate', true)
                ->assertJsonPath('data.gateway', $bank)
                ->assertJsonPath('data.replayed', true);

            $guard = WebhookReplayGuard::query()->where('gateway', $bank)->sole();
            $this->assertSame(2, $guard->deliveries);
            $this->assertSame(hash('sha256', $bank.'|bank_txn_id|'.$transactionId), $guard->nonce);

            $this->postJson($url, $payload, [$lane['header'] => str_repeat('0', 64)])
                ->assertBadRequest()->assertExactJson(['status' => 'ERROR', 'message' => 'Notification rejected.']);
            $this->assertSame(2, $guard->fresh()->deliveries);
        }
    }

    /**
     * @return array<string, array{controller: class-string, header: string, secret_key: string, secret: string}>
     */
    private function lanes(): array
    {
        return [
            'kbank' => [
                'controller' => KBankWebhookController::class,
                'header' => 'X-KBANK-Signature',
                'secret_key' => 'services.kbank.webhook_secret',
                'secret' => self::KBANK_SECRET,
            ],
            'scb' => [
                'controller' => ScbWebhookController::class,
                'header' => 'X-SCB-Signature',
                'secret_key' => 'services.scb.webhook_secret',
                'secret' => self::SCB_SECRET,
            ],
        ];
    }

    /** @return array<string, string> */
    private function payload(string $bank, string $transactionId): array
    {
        return [
            'event_type' => 'debit',
            $bank === 'kbank' ? 'bank_txn_id' : 'transactionId' => $transactionId,
            'amount' => '100.00',
            'currency' => 'THB',
            'reference' => 'DEP-ROUTE-ONLY',
            'timestamp' => (string) time(),
        ];
    }

    /** @param array<string, string> $payload */
    private function signature(array $payload, string $secret): string
    {
        return hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $secret);
    }
}
