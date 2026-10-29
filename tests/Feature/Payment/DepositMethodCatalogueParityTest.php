<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Both authenticated catalogue URLs must derive from the same readiness checks. */
final class DepositMethodCatalogueParityTest extends TestCase
{
    use DatabaseTruncation;

    public static function currencies(): array
    {
        return [['THB'], ['USD'], ['BDT']];
    }

    #[DataProvider('currencies')]
    public function test_catalogues_agree_and_never_advertise_crypto(string $currency): void
    {
        config([
            'payment.currency.default' => $currency,
            'payment.gateways.crypto.enabled' => true,
            'payment.gateways.stripe.enabled' => true,
            'payment.gateways.stripe.key' => 'pk_test_catalogue_only',
            'payment.gateways.stripe.secret' => 'sk_test_catalogue_only',
            'payment.gateways.stripe.webhook_secret' => 'whsec_catalogue_only',
        ]);
        $this->actingAs(User::factory()->create(), 'sanctum');
        $canonical = $this->getJson('/api/v1/deposit/methods')->assertOk();
        $alias = $this->getJson('/api/v1/deposits/methods')->assertOk();

        self::assertSame($canonical->json('data'), $alias->json('data'));
        self::assertSame($currency, $alias->json('data.default_currency'));
        self::assertSame(['THB', 'USD', 'BDT'], $alias->json('data.currencies'));
        self::assertNotContains('crypto', array_column($alias->json('data.methods'), 'id'));
        foreach ($alias->json('data.methods') as $method) {
            self::assertArrayHasKey('enabled', $method);
            self::assertArrayHasKey('state', $method);
            self::assertSame($currency, $method['currency']);
        }
    }

    public function test_invalid_deployment_currency_refuses_both_catalogues(): void
    {
        config(['payment.currency.default' => 'NOT_A_CURRENCY']);
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->getJson('/api/v1/deposit/methods')->assertStatus(503)->assertJsonPath('status', 'NOT_CONFIGURED');
        $this->getJson('/api/v1/deposits/methods')->assertStatus(503)->assertJsonPath('status', 'NOT_CONFIGURED');
    }
}
