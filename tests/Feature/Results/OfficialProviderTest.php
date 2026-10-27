<?php

declare(strict_types=1);

namespace Tests\Feature\Results;

use App\Services\Lottery\GloOfficialResultProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The official GLO result provider must be honest about every failure.
 *
 * ============================================================================
 * THE THREE STATES IT IS ALLOWED TO REPORT
 * ============================================================================
 *   configured + a documented payload  → parsed rows
 *   configured + a broken payload      → `failed`, never an empty success
 *   not configured / unreachable       → `not_configured`, never a guess
 *
 * The middle one is the reason this suite exists. An upstream that answers 200
 * with a renamed field, or with a first prize of "12345", must not produce an
 * empty-but-successful draw: "the draw happened and nobody won" is a claim about
 * money, and a schema change is not evidence for it.
 *
 * Every HTTP call here is faked. Nothing in this file reaches the network.
 */
final class OfficialProviderTest extends TestCase
{
    private function provider(): GloOfficialResultProvider
    {
        return $this->app->make(GloOfficialResultProvider::class);
    }

    /**
     * A payload in the documented catalogue shape: `first`, `second`, `third`,
     * and the optional tail tiers. The provider maps this shape and only this
     * shape; inventing a different one here would test nothing.
     *
     * @return array<string, mixed>
     */
    private function documentedPayload(string $first = '123456', string $tail = '56'): array
    {
        return [
            'draw' => '2026-10-16-01',
            'first' => $first,
            'second' => ['111111', '222222'],
            'third' => ['333333'],
            'last_two' => $tail === '' ? [] : [$tail],
        ];
    }

    private function configureOfficial(): void
    {
        config([
            'glo.official_source.mode' => 'official',
            'glo.official_source.base_url' => 'https://official.example.test/api',
            'glo.official_source.latest_lottery_path' => '/latest',
            'glo.official_source.lottery_result_path' => '/result',
            'glo.official_source.provenance' => 'glo-official',
        ]);
    }

    public function test_it_is_not_configured_until_the_mode_is_official_and_a_base_url_exists(): void
    {
        config(['glo.official_source.mode' => 'fixture', 'glo.official_source.base_url' => 'https://official.example.test/api']);
        $this->assertFalse($this->provider()->isConfigured(), 'a fixture mode must never look configured');

        // Both keys, because the provider falls back to services.glo.base_url —
        // that fallback is deliberate (older deployments only set the services
        // key), so "no base URL anywhere" is what an unconfigured lane looks like.
        config([
            'glo.official_source.mode' => 'official',
            'glo.official_source.base_url' => '',
            'services.glo.base_url' => '',
        ]);
        $this->assertFalse($this->provider()->isConfigured(), 'no base URL anywhere is not a configured lane');

        $this->configureOfficial();
        $this->assertTrue($this->provider()->isConfigured());
    }

    public function test_an_unconfigured_lane_reports_not_configured_and_makes_no_request(): void
    {
        Http::fake();

        config(['glo.official_source.mode' => 'fixture', 'glo.official_source.base_url' => 'https://official.example.test/api']);

        $result = $this->provider()->fetch();

        Http::assertNothingSent();

        $this->assertSame('not_configured', $result['status']);
        $this->assertArrayHasKey('failure_reason', $result);
        $this->assertNotSame('', (string) $result['failure_reason']);
    }

    public function test_a_documented_payload_is_parsed_into_rows(): void
    {
        $this->configureOfficial();

        Http::fake([
            '*' => Http::response($this->documentedPayload(), 200),
        ]);

        $result = $this->provider()->fetch();

        $this->assertSame('imported', $result['status'], 'a valid documented payload must parse');
        $this->assertSame('123456', $result['first_prize'], 'the first prize must survive parsing');
        $this->assertSame(['111111', '222222'], $result['second_prize']);
        $this->assertSame(['333333'], $result['third_prize']);
        $this->assertSame(['56'], $result['tiers']['last_two'], 'a two-digit tail is preserved verbatim');
        $this->assertNull($result['failure_reason']);
        $this->assertIsString($result['fingerprint']);
        $this->assertSame(64, strlen((string) $result['fingerprint']), 'the fingerprint is a sha256 digest');
    }

    public function test_a_payload_with_a_malformed_first_prize_fails_instead_of_reporting_no_winner(): void
    {
        $this->configureOfficial();

        Http::fake([
            '*' => Http::response($this->documentedPayload('12345'), 200),
        ]);

        $result = $this->provider()->fetch();

        $this->assertSame(
            'failed',
            $result['status'],
            'five digits is not a six-digit first prize; reporting it as an unreachable upstream would hide a schema change',
        );
        $this->assertNull($result['first_prize']);
        $this->assertStringContainsString('did not validate', (string) $result['failure_reason']);
    }

    public function test_a_payload_with_no_two_digit_tail_is_imported_without_inventing_one(): void
    {
        // The tail tiers are optional in the documented shape. What matters is
        // that the provider does not fabricate a tail to fill the gap: an empty
        // list is the honest representation of "the feed did not include one".
        $this->configureOfficial();

        Http::fake([
            '*' => Http::response($this->documentedPayload('123456', ''), 200),
        ]);

        $result = $this->provider()->fetch();

        $this->assertSame('imported', $result['status']);
        $this->assertSame([], $result['tiers']['last_two']);
    }

    public function test_a_non_json_body_fails_rather_than_yielding_an_empty_draw(): void
    {
        $this->configureOfficial();

        Http::fake([
            '*' => Http::response('<html>maintenance</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $result = $this->provider()->fetch();

        $this->assertSame('failed', $result['status']);
        $this->assertNull($result['first_prize']);
    }

    public function test_an_upstream_error_is_reported_as_not_configured_and_never_as_a_draw(): void
    {
        $this->configureOfficial();

        Http::fake([
            '*' => Http::response(['error' => 'unavailable'], 503),
        ]);

        $result = $this->provider()->fetch();

        $this->assertSame('not_configured', $result['status']);
        $this->assertNull($result['first_prize']);
    }

    public function test_a_connection_failure_does_not_echo_the_endpoint(): void
    {
        $this->configureOfficial();
        config(['glo.official_source.base_url' => 'https://official.example.test/api?token=super-secret']);

        Http::fake([
            '*' => static fn () => throw new \Illuminate\Http\Client\ConnectionException('connect failed for https://official.example.test/api?token=super-secret'),
        ]);

        $result = $this->provider()->fetch();

        $this->assertSame('not_configured', $result['status']);

        $encoded = json_encode($result);

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('super-secret', $encoded, 'a refusal must never leak the endpoint or its token');
    }

    public function test_the_provider_never_reports_the_fixture_lane_as_configured_in_production(): void
    {
        config([
            'glo.official_source.mode' => 'fixture',
            'glo.official_source.base_url' => 'https://official.example.test/api',
        ]);

        // Simulate the production environment without booting one: the provider
        // asks the application, so the environment has to change for the answer
        // to change.
        $this->app['env'] = 'production';

        $this->assertTrue($this->provider()->fixtureRefused(), 'production must always refuse a fixture lane');
        $this->assertFalse($this->provider()->isConfigured());
    }
}
