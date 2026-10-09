<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloResultImport;
use App\Models\User;
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
 *
 * Import writes provenance (glo_result_imports) and STAGES a Pending ingestion
 * for a second operator to confirm. It writes NO draw_results row and publishes
 * nothing: that is the GLO write boundary, and a different operator is required
 * to cross it.
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

        // THE DRAW MUST BE IN AN INGESTIBLE LIFECYCLE STATE.
        //
        // This fixture previously created the draw as `result_published`, which
        // only worked because the importer wrote draw_results directly with no
        // lifecycle check at all. A published draw is exactly what
        // DrawResultIngestionService::mayIngestIn() refuses — a result arriving
        // for a draw that has already published (or settled) is the case the
        // guard exists to stop.
        //
        // The realistic state for a GLO result arriving is `closed` (betting has
        // stopped, the draw is awaiting its numbers) or `drawing`
        // (DrawLifecycleState::ResultPending). `closed()` is used here.
        $this->draw = Draw::factory()->closed()->create([
            'draw_number' => 'GLO-2026-09-16',
            'scheduled_at' => now()->subDays(10),
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

    /**
     * THE WRITE BOUNDARY.
     *
     * This test replaces `test_import_for_draw_writes_provenance_and_draw_result`,
     * which asserted the OPPOSITE of the platform's security contract:
     *
     *     $this->assertNotNull($outcome['draw_result']);
     *     $this->assertSame('042042', $outcome['draw_result']->first_prize);
     *
     * That assertion encoded the regression. It required the importer to write a
     * draw_results row — the exact behaviour that let a single unattended command
     * publish an official result with no second pair of eyes. A test that demands
     * a write boundary be broken is worse than no test: it makes the fix look
     * like a regression.
     *
     * What is asserted now is what the security model actually requires:
     * an import STAGES a reviewable Pending record and writes NO draw_results row.
     */
    public function test_import_stages_pending_ingestion_and_writes_no_draw_result(): void
    {
        $operator = User::factory()->create();

        $outcome = $this->imports->importForDraw($this->draw, $operator);

        // Provenance is filed, and says what it is.
        $this->assertSame('imported', $outcome['import']->status);
        $this->assertSame('fixture', $outcome['import']->provider);

        // NOTHING was published. This is the whole point.
        $this->assertNull(
            $outcome['draw_result'],
            'an import must never produce a draw_results row',
        );

        $this->assertSame(
            0,
            DrawResult::query()->where('draw_id', $this->draw->getKey())->count(),
            'an import must never write draw_results',
        );

        // The result is staged, awaiting a DIFFERENT operator.
        $ingestion = $outcome['ingestion'];
        $this->assertIsArray($ingestion);
        $this->assertSame('pending', $ingestion['status']);
        $this->assertSame('042042', $ingestion['first_prize']);
        // 58, NOT the last two digits of 042042. The fixture deliberately carries
        // an INDEPENDENT two-digit prize, because that is what a real GLO
        // announcement carries — see the fixture's provenance note.
        $this->assertSame('58', $ingestion['bottom_two']);
        $this->assertNotEmpty($ingestion['fingerprint']);

        // The attribution the four-eyes control depends on.
        $stored = $this->draw->fresh()->metadata['result_ingestion'] ?? [];
        $this->assertSame($operator->getKey(), $stored['ingested_by'] ?? null);

        $this->assertDatabaseHas('glo_result_imports', [
            'draw_id' => $this->draw->getKey(),
            'status' => 'imported',
            'provider' => 'fixture',
        ]);
    }

    public function test_import_without_an_actor_stages_an_unattributable_record(): void
    {
        // No operator passed. The record is still staged, but it carries no
        // ingesting identity — and the confirmation service refuses to confirm a
        // record it cannot attribute, so this can never become a silent publish.
        $outcome = $this->imports->importForDraw($this->draw);

        $this->assertSame(0, DrawResult::query()->where('draw_id', $this->draw->getKey())->count());

        $stored = $this->draw->fresh()->metadata['result_ingestion'] ?? [];
        $this->assertNull($stored['ingested_by'] ?? null);
    }

    public function test_import_into_a_published_draw_is_refused_by_the_lifecycle_guard(): void
    {
        // The guard the direct write never consulted. A result for a draw that
        // has already published is refused rather than overwriting it.
        //
        // The fixture provider only answers for 'GLO-2026-09-16', so the setUp
        // draw has to go — and `forceDelete()`, not `delete()`: Draw uses
        // SoftDeletes and draw_number carries a UNIQUE index, so a soft-deleted
        // row would still collide.
        Draw::query()->forceDelete();

        $published = Draw::factory()->create([
            'draw_number' => 'GLO-2026-09-16',
            'status' => 'result_published',
        ]);

        $outcome = $this->imports->importForDraw($published, User::factory()->create());

        $this->assertSame('refused', $outcome['import']->status);
        $this->assertNull($outcome['draw_result']);
        $this->assertNull($outcome['ingestion']);
        $this->assertStringContainsString('refused', (string) $outcome['import']->failure_reason);
        $this->assertSame(0, DrawResult::query()->where('draw_id', $published->getKey())->count());
    }

    public function test_import_of_a_payload_without_a_fingerprint_fails_closed(): void
    {
        // An `imported` payload with no fingerprint cannot be pinned afterwards,
        // so it is refused rather than staged. This guard was deleted by the
        // restore commit along with the rest of the boundary.
        //
        // The provider is swapped in the container rather than constructed by
        // hand, because GloResultImportService type-hints the CONCRETE provider
        // classes — a design choice that makes a hand-built fake impossible.
        // (Worth noting as a small design smell: type-hinting the interface
        // would make this service testable without container gymnastics.)
        $this->mock(GloFixtureResultProvider::class, function ($mock): void {
            $mock->shouldReceive('name')->andReturn('fixture');
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('fetch')->andReturn([
                'status' => 'imported',
                'provider' => 'fixture',
                'endpoint' => 'test',
                'draw_number' => 'GLO-2026-09-16',
                'first_prize' => '042042',
                'second_prize' => [],
                'third_prize' => [],
                'n3' => [],
                'tiers' => [],
                'failure_reason' => null,
                'fingerprint' => null,
            ]);
        });

        // Resolved AFTER the mock is bound, so the service receives it.
        $service = app(GloResultImportService::class);

        $outcome = $service->importForDraw($this->draw, User::factory()->create());

        $this->assertSame('failed', $outcome['import']->status);
        $this->assertNull($outcome['ingestion']);
        $this->assertSame(0, DrawResult::query()->where('draw_id', $this->draw->getKey())->count());
        $this->assertStringContainsString('fingerprint', (string) $outcome['import']->failure_reason);
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
