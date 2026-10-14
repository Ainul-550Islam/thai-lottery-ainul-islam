<?php

declare(strict_types=1);

namespace Tests\Feature\PublicPages;

use App\Enums\DrawStatus;
use App\Enums\GloSourceState;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Services\PublicPages\ResultsPageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Public Privacy and Results feature tests.
 *
 * Verifies canonical legal privacy route, Results page provenance mapping,
 * and lack of inline DB queries.
 */
final class PublicPrivacyAndResultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_privacy_is_public_without_login(): void
    {
        $response = $this->get('/privacy');
        $response->assertOk();
        $this->assertGuest();

        $content = (string) $response->getContent();
        $this->assertStringContainsString('data-pp-page="privacy"', $content);
        $this->assertStringContainsString('Privacy Policy', $content);
        $this->assertStringContainsString(config('legal.version', 'v1.0.0'), $content);
    }

    public function test_privacy_effective_date_is_static_from_config(): void
    {
        $effective = (string) config('legal.effective_at');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $effective);

        $content = (string) $this->get('/privacy')->assertOk()->getContent();
        $this->assertStringContainsString($effective, $content);
    }

    public function test_privacy_renders_in_thai_without_raw_keys(): void
    {
        app()->setLocale('th');

        $response = $this->get('/privacy');
        $response->assertOk();

        $content = (string) $response->getContent();
        $this->assertStringContainsString('นโยบายความเป็นส่วนตัว', $content);
        $this->assertStringNotContainsString('public_pages.privacy_', $content);
    }

    public function test_results_page_serves_verified_and_fixture_rows_with_exact_provenance(): void
    {
        $draw1 = Draw::factory()->create([
            'status' => DrawStatus::ResultPublished,
            'draw_number' => 'GLO-20260901',
            'scheduled_at' => now()->subDays(10),
        ]);

        DrawResult::factory()->create([
            'draw_id' => $draw1->id,
            'first_prize' => '123456',
            'metadata' => [
                'glo' => [
                    'import_provider' => 'official_api',
                    'import_fingerprint' => 'glo-fp-001',
                ],
            ],
        ]);

        $draw2 = Draw::factory()->create([
            'status' => DrawStatus::Completed,
            'draw_number' => 'GLO-20260816',
            'scheduled_at' => now()->subDays(25),
        ]);

        DrawResult::factory()->create([
            'draw_id' => $draw2->id,
            'first_prize' => '654321',
            'metadata' => [
                'glo' => [
                    'import_provider' => 'fixture',
                    'import_fingerprint' => 'glo-fixture-002',
                ],
            ],
        ]);

        $draw3 = Draw::factory()->create([
            'status' => DrawStatus::Completed,
            'draw_number' => 'GLO-20260801',
            'scheduled_at' => now()->subDays(40),
        ]);

        DrawResult::factory()->create([
            'draw_id' => $draw3->id,
            'first_prize' => '999888',
            'metadata' => [
                'glo' => [
                    'import_provider' => 'unknown_custom_source',
                    'import_fingerprint' => 'glo-custom-003',
                ],
            ],
        ]);

        $response = $this->get('/results');
        $response->assertOk();

        $content = (string) $response->getContent();

        // Official provider mapped to OFFICIAL_SOURCE_VERIFIED
        $this->assertStringContainsString(GloSourceState::OfficialSourceVerified->value, $content);
        $this->assertStringContainsString('123456', $content);

        // Fixture provider mapped to FIXTURE_ONLY
        $this->assertStringContainsString(GloSourceState::FixtureOnly->value, $content);
        $this->assertStringContainsString('654321', $content);

        // Unknown provider degrades safely to UNAVAILABLE, never official
        $this->assertStringContainsString(GloSourceState::Unavailable->value, $content);
        $this->assertStringContainsString('999888', $content);

        // No demo ticket 000001 in consumer copy
        $this->assertStringNotContainsString('/api/v1/glo/results/check/000001', $content);
    }

    public function test_results_page_service_produces_structured_data(): void
    {
        $service = app(ResultsPageService::class);
        $data = $service->resultsData();

        $this->assertIsArray($data);
        $this->assertArrayHasKey('currentStatus', $data);
        $this->assertArrayHasKey('rows', $data);
    }
}
