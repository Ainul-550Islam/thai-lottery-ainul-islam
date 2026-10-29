<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Api\V1\Payment\PromptPayController;
use App\Models\AgeVerification;
use App\Models\Deposit;
use App\Models\User;
use App\Services\Legal\ConsentService;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;

final class DepositMethodsCompatRouteTest extends PaymentTestCase
{
    public function test_promptpay_compatibility_route_uses_canonical_checkout(): void
    {
        config([
            'payment.gateways.promptpay.enabled' => true,
            'payment.gateways.promptpay.merchant_id' => '0812345678',
        ]);
        $player = $this->createPlayer('0.00');
        $this->onboard($player['user']);
        Sanctum::actingAs($player['user']);

        $response = $this->postJson('/api/v1/deposit/promptpay/generate', [
            'amount' => '125.00',
            'idempotency_key' => 'compat-promptpay-checkout',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.amount', '125.00')
            ->assertJsonPath('data.currency', 'THB');
        $this->assertNotEmpty($response->json('data.deposit_id'));
        $this->assertNotEmpty($response->json('data.reference'));
        $this->assertNotEmpty($response->json('data.qr_payload'));
        $this->assertNotEmpty($response->json('data.expires_at'));
        $deposit = Deposit::query()->findOrFail($response->json('data.deposit_id'));
        $this->assertSame(PaymentMethod::PromptPay, $deposit->method);
        $this->assertSame($deposit->reference_number, $response->json('data.reference'));
    }

    public function test_disabled_or_misconfigured_promptpay_returns_honest_not_configured(): void
    {
        $player = $this->createPlayer('0.00');
        $this->onboard($player['user']);
        Sanctum::actingAs($player['user']);
        $player['wallet']->delete(); // Capability refusal must not depend on wallet existence.
        config([
            'payment.gateways.promptpay.enabled' => false,
            'payment.gateways.promptpay.merchant_id' => '0812345678',
        ]);

        $this->postJson('/api/v1/deposit/promptpay/generate', [
            'amount' => '125.00',
            'idempotency_key' => 'compat-promptpay-disabled',
        ])->assertStatus(503)->assertJsonPath('status', 'NOT_CONFIGURED');

        config([
            'payment.gateways.promptpay.enabled' => true,
            'payment.gateways.promptpay.merchant_id' => null,
        ]);
        $this->postJson('/api/v1/deposit/promptpay/generate', [
            'amount' => '125.00',
            'idempotency_key' => 'compat-promptpay-misconfigured',
        ])->assertStatus(503)->assertJsonPath('status', 'NOT_CONFIGURED');
        $this->assertSame(0, Deposit::query()->count());
    }

    public function test_promptpay_compatibility_route_requires_authentication(): void
    {
        config([
            'payment.gateways.promptpay.enabled' => true,
            'payment.gateways.promptpay.merchant_id' => '0812345678',
        ]);

        $this->postJson('/api/v1/deposit/promptpay/generate', [
            'amount' => '125.00',
            'idempotency_key' => 'compat-promptpay-anonymous',
        ])->assertUnauthorized();
        $this->assertSame(0, Deposit::query()->count());
    }

    public function test_client_cannot_choose_a_promptpay_recipient_for_a_checkout(): void
    {
        config(['payment.gateways.promptpay.enabled' => true, 'payment.gateways.promptpay.merchant_id' => '0812345678']);
        $player = $this->createPlayer('0.00');
        $this->onboard($player['user']);
        Sanctum::actingAs($player['user']);

        $this->postJson('/api/v1/deposit/promptpay/generate', [
            'amount' => '125.00', 'idempotency_key' => 'promptpay-no-client-recipient',
            'recipient_id' => '0899999999',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['recipient_id']]]]);
        $this->assertDatabaseCount('deposits', 0);
    }

    public function test_unregistered_legacy_promptpay_adapter_still_requires_a_real_actor(): void
    {
        $request = Request::create('/synthetic-legacy-promptpay', 'POST', ['amount' => '125.00']);
        $request->setUserResolver(fn () => null);
        $response = app(PromptPayController::class)->generate($request);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringNotContainsString('0812345678', $response->getContent());
        $this->assertDatabaseCount('deposits', 0);
    }

    public function test_environment_templates_do_not_supply_a_default_promptpay_recipient(): void
    {
        foreach (['.env.example', '.env.ci', '.env.production.example'] as $file) {
            $contents = file_get_contents(base_path($file));
            $this->assertIsString($contents);
            $this->assertMatchesRegularExpression('/^PROMPTPAY_DEFAULT_MERCHANT_ID=\\s*$/m', $contents);
        }
    }

    private function onboard(User $user): void
    {
        $user->forceFill(['date_of_birth' => now()->subYears(30)->toDateString()])->save();
        AgeVerification::query()->create([
            'user_id' => $user->id,
            'status' => 'eligible',
            'minimum_age' => (int) config('legal.minimum_age', 18),
            'method' => 'self_declaration',
            'attested_at' => now(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'source' => 'test',
        ]);
        $documents = collect(app(ConsentService::class)->currentDocuments())->keyBy('document_type');
        app(ConsentService::class)->acceptCurrentDocuments($user, Request::create('/test', 'POST', [
            'terms' => true,
            'privacy_notice' => true,
            'terms_version' => (string) ($documents->get('terms')['version'] ?? ''),
            'terms_hash' => (string) ($documents->get('terms')['content_hash'] ?? ''),
            'privacy_version' => (string) ($documents->get('privacy')['version'] ?? ''),
            'privacy_hash' => (string) ($documents->get('privacy')['content_hash'] ?? ''),
        ], server: ['REMOTE_ADDR' => '127.0.0.1']), 'api');
    }
}
