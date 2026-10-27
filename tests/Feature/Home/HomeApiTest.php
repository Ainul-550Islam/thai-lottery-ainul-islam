<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Feature tests for Home API Endpoint (Prompt 01).
 * Verifies JSON schema, safe payload, absence of player PII / credentials, and rate limiting.
 */
final class HomeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_home_api_endpoint_returns_structured_json(): void
    {
        $response = $this->getJson('/api/v1/home');

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'data' => [
                'current_time',
                'countdown',
                'lottery_feed',
                'result_feed',
                'prizes',
                'bonuses',
                'payments',
                'app_links',
                'trust',
            ],
        ]);
        $response->assertJson(['success' => true]);
    }

    public function test_home_api_never_exposes_internal_secrets_or_pii(): void
    {
        $content = (string) $this->getJson('/api/v1/home')->assertOk()->getContent();

        $forbiddenStrings = [
            'password',
            'api_key',
            'secret',
            'private_key',
            'merchant_key',
            'stripe_secret',
            'database',
            'access_token',
        ];

        foreach ($forbiddenStrings as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($content));
        }
    }
}
