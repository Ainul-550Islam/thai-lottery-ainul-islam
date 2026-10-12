<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSite;

use App\Enums\DrawStatus;
use App\Enums\GloSourceState;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Glo\Concerns\CreatesTrustedGloResult;
use Tests\TestCase;

/**
 * P1: public / and /results are anonymous; source labels never say "official"
 * for fixtures; metrics is not financial-public; published version immutability
 * is enforced by the publish command fingerprint rule.
 */
final class PublicResultsAnonymousTest extends TestCase
{
    use CreatesTrustedGloResult;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_home_is_public_without_login(): void
    {
        $response = $this->get('/');
        $response->assertOk();
        $response->assertSee('results', false);
    }

    public function test_results_page_is_public_without_login(): void
    {
        $response = $this->get('/results');
        $response->assertOk();
        $response->assertSee('Public Results Hub', false);
    }

    public function test_results_page_never_labels_fixture_as_official(): void
    {
        $draw = Draw::factory()->create([
            // A published result belongs to a draw that has been held.
            'scheduled_at' => now()->subDay(),
            'status' => DrawStatus::ResultPublished,
            'draw_number' => 'fixture-pub-1',
        ]);
        DrawResult::query()->create([
            'draw_id' => $draw->getKey(),
            'first_prize' => '123456',
            'second_prize' => [],
            'third_prize' => [],
            'consolation_prizes' => [],
            'published_at' => now(),
            'metadata' => $this->trustedFixtureMetadata($draw, '123456', 'fixture-pub-import', str_repeat('a', 64)),
        ]);

        $response = $this->get('/results');
        $response->assertOk();
        $content = (string) $response->getContent();
        $response->assertSee(GloSourceState::FixtureOnly->value, false);
        $this->assertStringContainsString('FIXTURE_ONLY', $content);
        // Fixture rows must never carry the official badge on the same row block.
        $this->assertStringNotContainsString('OFFICIAL_SOURCE_VERIFIED', str_replace('FIXTURE_ONLY', '', $content));
    }

    public function test_metrics_route_requires_auth_and_staff_gate(): void
    {
        $guest = $this->get('/metrics');
        $this->assertTrue(in_array($guest->status(), [302, 401, 403], true), 'guest metrics must not be 200');

        $player = User::factory()->create();
        $this->actingAs($player);

        $asPlayer = $this->get('/metrics');
        $this->assertTrue(in_array($asPlayer->status(), [403, 404], true), 'non-staff metrics must not be 200, got '.$asPlayer->status());
    }

    public function test_publish_command_refuses_missing_fingerprint(): void
    {
        $draw = Draw::factory()->create([
            // A published result belongs to a draw that has been held.
            'scheduled_at' => now()->subDay(),
            'status' => DrawStatus::ResultPublished,
            'draw_number' => 'no-fp-99',
        ]);
        DrawResult::query()->create([
            'draw_id' => $draw->getKey(),
            'first_prize' => '999999',
            'second_prize' => [],
            'third_prize' => [],
            'consolation_prizes' => [],
            'metadata' => [],
        ]);

        $this->artisan('glo:publish-public-result', ['--draw' => (string) $draw->getKey()])
            ->assertFailed();
    }

    public function test_publish_command_publishes_fingerprinted_result_once(): void
    {
        $draw = Draw::factory()->create([
            // A published result belongs to a draw that has been held.
            'scheduled_at' => now()->subDay(),
            'status' => DrawStatus::ResultPublished,
            'draw_number' => 'fp-100',
        ]);
        DrawResult::query()->create([
            'draw_id' => $draw->getKey(),
            'first_prize' => '100100',
            'second_prize' => [],
            'third_prize' => [],
            'consolation_prizes' => [],
            'published_at' => now(),
            'metadata' => $this->trustedFixtureMetadata($draw, '100100', 'fixture-import-fp-v1-abc', str_repeat('b', 64)),
        ]);

        $this->artisan('glo:publish-public-result', ['--draw' => (string) $draw->getKey()])
            ->assertSuccessful();

        // Source state must be fixture_only, never official, for fixture provider.
        $this->artisan('glo:publish-public-result', ['--draw' => (string) $draw->getKey(), '--json' => true])
            ->expectsOutputToContain('fixture_only')
            ->assertSuccessful();
    }

    public function test_latest_results_from_fixture_carry_version_stamp(): void
    {
        Cache::flush();
        $draw = Draw::factory()->create([
            'status' => DrawStatus::Completed,
            'draw_number' => 'ver-1',
            'scheduled_at' => now()->subDay(),
        ]);
        DrawResult::query()->create([
            'draw_id' => $draw->getKey(),
            'first_prize' => '555555',
            'second_prize' => [],
            'third_prize' => [],
            'consolation_prizes' => [],
            'published_at' => now(),
            'metadata' => $this->trustedFixtureMetadata($draw, '555555', 'fixture-ver-import', str_repeat('c', 64)),
        ]);

        $response = $this->get('/results');
        $response->assertOk();
        $content = (string) $response->getContent();
        $this->assertStringContainsString(str_repeat('c', 64), $content);
        $this->assertStringContainsString('555555', $content);
    }

    /**
     * Build a persisted fixture publication stamp for the public projection
     * tests. The fixture is valid only in non-production and remains explicitly
     * labeled FIXTURE_ONLY.
     *
     * @return array<string, mixed>
     */
    private function trustedFixtureMetadata(Draw $draw, string $firstPrize, string $reference, string $fingerprint): array
    {
        $maker = User::factory()->create();
        $checker = User::factory()->create();

        return $this->trustedFixtureResultMetadata(
            $draw,
            $maker,
            $checker,
            $firstPrize,
            '58',
            [],
            [],
            [],
            [],
            $reference,
            $fingerprint,
        );
    }
}
