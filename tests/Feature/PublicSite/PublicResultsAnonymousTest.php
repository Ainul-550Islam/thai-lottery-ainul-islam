<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSite;

use App\Enums\DrawStatus;
use App\Enums\GloSourceState;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\User;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Draw\DrawResultConfirmationService;
use App\Services\Draw\DrawResultIngestionService;
use App\Services\Lottery\GloPublicPrizeSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\PublicSite\Concerns\PublishesProviderResults;
use Tests\TestCase;

/**
 * P1: public / and /results are anonymous; source labels never say "official"
 * for fixtures; metrics is not financial-public; published version immutability
 * is enforced by the publish command fingerprint rule.
 */
final class PublicResultsAnonymousTest extends TestCase
{
    use PublishesProviderResults;
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
        $response->assertSee(__('results.title'), false);
    }

    public function test_results_search_static_interface_renders_in_thai_and_bangla(): void
    {
        $this->get('/results/search?q=missing&lang=th')
            ->assertOk()
            ->assertSee('ค้นหาผลสลาก')
            ->assertSee('ไม่พบผลรางวัลที่เผยแพร่ตรงกับ');

        $this->get('/results/search?q=missing&lang=bn')
            ->assertOk()
            ->assertSee('লটারির ফলাফল খুঁজুন')
            ->assertSee('প্রকাশিত ফল পাওয়া যায়নি');
    }

    public function test_results_card_status_and_table_labels_render_in_thai_and_bangla(): void
    {
        $draw = Draw::factory()->create([
            'scheduled_at' => now()->subDay(),
            'status' => DrawStatus::ResultPublished,
            'draw_number' => 'locale-card-1',
        ]);
        DrawResult::query()->create([
            'draw_id' => $draw->getKey(),
            'first_prize' => '123456',
            'second_prize' => [],
            'third_prize' => [],
            'consolation_prizes' => [],
            'metadata' => ['glo' => ['import_provider' => 'fixture']],
        ]);

        $this->get('/results?lang=th')
            ->assertOk()
            ->assertSee('ผลรางวัลที่เผยแพร่')
            ->assertSee('ข้อมูลตัวอย่าง');

        $this->get('/results?lang=bn')
            ->assertOk()
            ->assertSee('প্রকাশিত ফলাফল')
            ->assertSee('নমুনা তথ্য');
    }

    public function test_results_page_has_canonical_and_open_graph_metadata(): void
    {
        $content = (string) $this->get('/results')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.route('results.index').'"', $content);
        $this->assertStringContainsString('<meta property="og:type" content="website"', $content);
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
        $this->assertStringContainsString('1001, 1002, 2001', $content, 'Other published prize groups must not be omitted from the results table.');
        // Fixture rows must never carry the official badge on the same row block.
        $this->assertStringNotContainsString('OFFICIAL_SOURCE_VERIFIED', str_replace('FIXTURE_ONLY', '', $content));
    }

    public function test_prize_summary_does_not_promote_unknown_result_provider_to_verified(): void
    {
        Cache::forget('home.prize-summary');
        $draw = Draw::factory()->closed()->create([
            'scheduled_at' => now()->subDay(),
            'draw_number' => 'unknown-provider-1',
        ]);
        $draw = app(DrawLifecycleService::class)->markResultPending($draw);
        $card = ['first_prize' => '123456', 'bottom_two' => '45'];
        app(DrawResultIngestionService::class)->ingest((int) $draw->getKey(), $card, 'internal', (int) User::factory()->create()->getKey());
        app(DrawResultConfirmationService::class)->confirm((int) $draw->getKey(), (int) User::factory()->create()->getKey(), $card);

        $summary = app(GloPublicPrizeSummaryService::class)->highlight();

        $this->assertSame('AVAILABLE', $summary['status']);
        $this->assertSame(GloSourceState::InternalReconciled->value, $summary['source_state']);
        $this->assertFalse($summary['fixture_sample']);
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
        $draw = Draw::factory()->closed()->create([
            // A published result belongs to a draw that has been held.
            'scheduled_at' => now()->subDay(),
            'draw_number' => 'fp-100',
        ]);
        $this->publishProviderResult($draw, 'fixture', '100100');

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
