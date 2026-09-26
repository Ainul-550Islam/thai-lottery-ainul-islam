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
use Tests\TestCase;

/**
 * P1: public / and /results are anonymous; source labels never say "official"
 * for fixtures; metrics is not financial-public; published version immutability
 * is enforced by the publish command fingerprint rule.
 */
final class PublicResultsAnonymousTest extends TestCase
{
    use RefreshDatabase;

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
        $response->assertSee('Government Lottery results', false);
    }

    public function test_results_page_never_labels_fixture_as_official(): void
    {
        $draw = Draw::factory()->create([
            'status' => DrawStatus::ResultPublished,
            'draw_number' => 'fixture-pub-1',
        ]);
        DrawResult::query()->create([
            'draw_id' => $draw->getKey(),
            'first_prize' => '123456',
            'second_prize' => ['654321'],
            'third_prize' => ['111111', '222222', '333333'],
            'consolation_prizes' => ['1001', '1002', '2001'],
            'metadata' => ['glo' => ['import_provider' => 'fixture']],
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
        $login = $this->post('/login', [
            'login' => $player->email,
            'password' => 'password',
        ]);
        $login->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($player);

        $asPlayer = $this->get('/metrics');
        $this->assertTrue(in_array($asPlayer->status(), [403, 404], true), 'non-staff metrics must not be 200, got '.$asPlayer->status());
    }

    public function test_publish_command_refuses_missing_fingerprint(): void
    {
        $draw = Draw::factory()->create([
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
            'status' => DrawStatus::ResultPublished,
            'draw_number' => 'fp-100',
        ]);
        DrawResult::query()->create([
            'draw_id' => $draw->getKey(),
            'first_prize' => '100100',
            'second_prize' => [],
            'third_prize' => [],
            'consolation_prizes' => [],
            'metadata' => ['glo' => [
                'import_fingerprint' => 'fp-v1-abc',
                'import_provider' => 'fixture',
            ]],
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
            'metadata' => ['glo' => ['import_fingerprint' => 'ver-fp-1']],
        ]);

        $response = $this->get('/results');
        $response->assertOk();
        $content = (string) $response->getContent();
        $this->assertStringContainsString('ver-fp-1', $content);
        $this->assertStringContainsString('555555', $content);
    }
}
