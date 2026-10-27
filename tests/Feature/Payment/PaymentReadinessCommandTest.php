<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Illuminate\Support\Facades\Artisan;

final class PaymentReadinessCommandTest extends Batch01TestCase
{
    public function test_readiness_command_passes_for_a_configured_gateway_in_the_active_market(): void
    {
        config([
            'payment.currency.default' => 'THB',
            'payment.country' => 'TH',
            'payment.markets.stripe' => ['currencies' => ['THB'], 'countries' => ['TH']],
            'payment.gateways.stripe' => [
                'enabled' => true,
                'key' => 'test-publishable-key',
                'secret' => 'test-secret',
                'webhook_secret' => 'test-webhook-secret',
                'supported_currencies' => ['THB'],
            ],
            'real_money.enabled' => false,
        ]);

        self::assertSame(0, Artisan::call('payments:readiness', ['--json' => true, '--strict' => true]));
        self::assertStringContainsString('"ready": true', Artisan::output());
    }

    public function test_real_money_readiness_command_fails_when_an_enabled_gateway_is_missing_credentials_or_base_url(): void
    {
        config([
            'payment.currency.default' => 'BDT',
            'payment.country' => 'BD',
            'payment.markets.bkash' => ['currencies' => ['BDT'], 'countries' => ['BD']],
            'payment.gateways.bkash' => [
                'enabled' => true,
                'app_key' => 'test-app-key',
                'app_secret' => '',
                'username' => 'test-user',
                'password' => 'test-password',
                'base_url' => '',
                'webhook_secret' => '',
                'supported_currencies' => ['BDT'],
            ],
            'real_money.enabled' => true,
        ]);

        self::assertSame(1, Artisan::call('payments:readiness', ['--json' => true, '--strict' => true]));
        self::assertStringContainsString('"ready": false', Artisan::output());
    }
}
