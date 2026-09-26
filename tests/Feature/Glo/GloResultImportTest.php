<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloResultImport;
use App\Services\Lottery\GloFixtureResultProvider;
use App\Services\Lottery\GloOfficialResultProvider;
use App\Services\Lottery\GloResultImportService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GLO-8 result providers: fixture replay + official documented endpoints only.
 *
 * Official provider returns honest NOT_CONFIGURED when mode≠official or the
 * endpoint is unreachable — never a fabricated result.
 * Import writes provenance (glo_result_imports) and draw_results metadata.
 */
class GloResultImportTest extends TestCase
{
    use DatabaseTruncation;

    private Draw $draw;

    private GloFixtureResultProvider $fixture;

    private GloOfficialResultProvider $official;

    private GloResultImportService $imports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->draw = Draw::factory()->create([
            'draw_number' => 'GLO-2026-09-16',
            'status' => 'result_published',
            'scheduled_at' => now()->subDays(10),
            'result_published_at' => now()->subDays(9),
        ]);

        $this->fixture = app(GloFixtureResultProvider::class);
        $this->official = app(GloOfficialResultProvider::class);
        $this->imports = app(GloResultImportService::class);

        config(['glo.official_source.mode' => 'fixture']);
    }

    public function test_fixture_provider_is_configured_when_directory_exists(): void
    {
        $this->assertTrue($this->fixture->isConfigured());
        $this->assertSame('fixture', $this->fixture->name());
    }

    public function test_fixture_fetch_returns_imported_with_six_digit_first(): void
    {
        $payload = $this->fixture->fetch('GLO-2026-09-16');

        $this->assertSame('imported', $payload['status']);
        $this->assertSame('fixture', $payload['provider']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $payload['first_prize']);
        $this->assertSame('042042', $payload['first_prize']);
        $this->assertNotEmpty($payload['second_prize']);
        $this->assertNotEmpty($payload['n3']['special'] ?? []);
        $this->assertMatchesRegularExpression('/^\d{3}$/', $payload['n3']['special'][0]);
        $this->assertNotNull($payload['fingerprint']);
    }

    public function test_fixture_unknown_draw_number_fails_honestly(): void
    {
        $payload = $this->fixture->fetch('NO-SUCH-DRAW');

        $this->assertSame('failed', $payload['status']);
        $this->assertNotNull($payload['failure_reason']);
        $this->assertNull($payload['first_prize']);
    }

    public function test_fixture_missing_directory_reports_not_configured(): void
    {
        config(['glo.official_source.fixture_path' => '/nonexistent/glo/fixtures']);
        $provider = app(GloFixtureResultProvider::class);

        $this->assertFalse($provider->isConfigured());
        $payload = $provider->fetch(null);
        $this->assertSame('not_configured', $payload['status']);
    }

    public function test_official_provider_not_configured_in_fixture_mode(): void
    {
        config(['glo.official_source.mode' => 'fixture']);
        $provider = app(GloOfficialResultProvider::class);

        $this->assertFalse($provider->isConfigured());

        $payload = $provider->fetch(null);
        $this->assertSame('not_configured', $payload['status']);
    }

    public function test_official_provider_not_configured_when_mode_official_but_unreachable(): void
    {
        config(['glo.official_source.mode' => 'official']);

        // Simulate network failure — never invent a result.
        Http::fake([
            'www.glo.or.th/*' => Http::response('', 500),
        ]);

        $payload = app(GloOfficialResultProvider::class)->fetch(null);

        $this->assertContains($payload['status'], ['not_configured', 'failed']);
        $this->assertNotNull($payload['failure_reason']);
        $this->assertStringContainsString('www.glo.or.th/api', (string) $payload['endpoint']);
    }

    public function test_official_provider_unreachable_is_not_configured(): void
    {
        config(['glo.official_source.mode' => 'official']);
        Http::fake(function () {
            throw new \RuntimeException('network unreachable');
        });

        $payload = app(GloOfficialResultProvider::class)->fetch('12345');

        $this->assertSame('not_configured', $payload['status']);
        $this->assertStringContainsString('unreachable', (string) $payload['failure_reason']);
    }

    public function test_official_provider_only_uses_documented_endpoints(): void
    {
        config(['glo.official_source.mode' => 'official']);
        Http::fake([
            'www.glo.or.th/api/lottery/getLatestLottery' => Http::response([
                'draw' => 'GLO-2026-09-16',
                'first' => '042042',
                'second' => ['112233'],
                'third' => ['600001'],
            ], 200),
            'www.glo.or.th/*' => Http::response('', 404),
        ]);

        $payload = app(GloOfficialResultProvider::class)->fetch(null);

        $this->assertSame('imported', $payload['status']);
        $this->assertStringEndsWith('/api/lottery/getLatestLottery', (string) $payload['endpoint']);
        $this->assertSame('042042', $payload['first_prize']);
        Http::assertSentCount(1);
    }

    public function test_import_for_draw_writes_provenance_and_draw_result(): void
    {
        $outcome = $this->imports->importForDraw($this->draw);

        $this->assertSame('imported', $outcome['import']->status);
        $this->assertSame('fixture', $outcome['import']->provider);
        $this->assertNotNull($outcome['draw_result']);
        $this->assertSame('042042', $outcome['draw_result']->first_prize);

        $metadata = $outcome['draw_result']->metadata['glo'] ?? [];
        $this->assertNotEmpty($metadata['import_fingerprint'] ?? '');
        $this->assertSame('fixture', $metadata['import_provider'] ?? null);

        $this->assertDatabaseHas('glo_result_imports', [
            'draw_id' => $this->draw->getKey(),
            'status' => 'imported',
            'provider' => 'fixture',
        ]);
    }

    public function test_import_is_idempotent_on_same_fingerprint(): void
    {
        $first = $this->imports->importForDraw($this->draw);
        $second = $this->imports->importForDraw($this->draw);

        $this->assertSame($first['import']->getKey(), $second['import']->getKey());
        $this->assertSame(1, GloResultImport::query()->where('draw_id', $this->draw->getKey())->count());
    }

    public function test_import_unknown_draw_number_records_failure_without_result(): void
    {
        $outcome = $this->imports->importForDraw($this->draw, null, 'MISSING-DRAW');

        $this->assertSame('failed', $outcome['import']->status);
        $this->assertNull($outcome['draw_result']);
        $this->assertSame(0, DrawResult::query()->where('draw_id', $this->draw->getKey())->count());
    }

    public function test_import_latest_without_matching_draw_records_provenance_only(): void
    {
        // Wipe local draw so latest fixture has no match.
        Draw::query()->delete();

        $outcome = $this->imports->importLatest();

        $this->assertNotNull($outcome['import']);
        $this->assertNull($outcome['draw_result']);
        $this->assertSame('fixture', $outcome['import']->provider);
    }

    public function test_import_official_mode_not_configured_writes_provenance_not_results(): void
    {
        config(['glo.official_source.mode' => 'official']);
        Http::fake(function () {
            throw new \RuntimeException('down');
        });

        $outcome = $this->imports->importForDraw($this->draw);

        $this->assertSame('not_configured', $outcome['import']->status);
        $this->assertNull($outcome['draw_result']);
        $this->assertSame(0, DrawResult::query()->where('draw_id', $this->draw->getKey())->count());
        $this->assertSame(0, GloResultImport::query()->where('status', 'imported')->count());
    }

    public function test_fixture_payload_second_and_third_are_six_digit_strings(): void
    {
        $payload = $this->fixture->fetch('GLO-2026-09-16');

        foreach (array_merge($payload['second_prize'], $payload['third_prize']) as $number) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $number);
        }
    }

    public function test_fixture_n3_numbers_are_three_digit_strings(): void
    {
        $payload = $this->fixture->fetch('GLO-2026-09-16');

        foreach ($payload['n3'] as $group => $list) {
            foreach ($list as $number) {
                $this->assertMatchesRegularExpression('/^\d{3}$/', (string) $number, 'n3.'.$group);
            }
        }
    }
}
