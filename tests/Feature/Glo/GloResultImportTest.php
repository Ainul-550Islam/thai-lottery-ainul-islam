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
 * Official provider returns NOT_CONFIGURED when no official source is
 * configured and FAILED for attempted HTTP/transport errors — never a fabricated
 * result.
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

    public function test_official_provider_http_error_is_a_countable_failure(): void
    {
        config(['glo.official_source.mode' => 'official']);

        // Simulate network failure — never invent a result.
        Http::fake([
            'www.glo.or.th/*' => Http::response('', 500),
        ]);

        $payload = app(GloOfficialResultProvider::class)->fetch(null);

        $this->assertSame('failed', $payload['status']);
        $this->assertNotNull($payload['failure_reason']);
        $this->assertStringContainsString('www.glo.or.th/api', (string) $payload['endpoint']);
    }

    public function test_official_provider_network_failure_is_failed_and_breakable(): void
    {
        config(['glo.official_source.mode' => 'official']);
        Http::fake(function () {
            throw new \RuntimeException('network unreachable');
        });

        $payload = app(GloOfficialResultProvider::class)->fetch('12345');

        $this->assertSame('failed', $payload['status']);
        $this->assertStringContainsString('unreachable', (string) $payload['failure_reason']);
        $this->assertStringNotContainsString('network unreachable', (string) $payload['failure_reason']);
    }

    public function test_provider_fingerprint_changes_when_separately_drawn_bottom_two_changes(): void
    {
        config(['glo.official_source.mode' => 'official']);
        Http::fake([
            'www.glo.or.th/*' => Http::sequence()
                ->push([
                    'draw' => 'GLO-2026-09-16',
                    'first' => '042042',
                    'last_two' => ['58'],
                ], 200)
                ->push([
                    'draw' => 'GLO-2026-09-16',
                    'first' => '042042',
                    'last_two' => ['59'],
                ], 200),
        ]);

        $provider = app(GloOfficialResultProvider::class);
        $first = $provider->fetch('GLO-2026-09-16');
        $second = $provider->fetch('GLO-2026-09-16');

        $this->assertSame('58', $first['tiers']['last_two'][0]);
        $this->assertSame('59', $second['tiers']['last_two'][0]);
        $this->assertNotSame($first['fingerprint'], $second['fingerprint']);
    }

    public function test_changed_provider_prize_list_replaces_the_pending_payload_fingerprint(): void
    {
        config(['glo.official_source.mode' => 'official']);
        Http::fake([
            'www.glo.or.th/*' => Http::sequence()
                ->push([
                    'draw' => (string) $this->draw->draw_number,
                    'first' => '042042',
                    'second' => ['112233'],
                    'last_two' => ['58'],
                ], 200)
                ->push([
                    'draw' => (string) $this->draw->draw_number,
                    'first' => '042042',
                    'second' => ['223344'],
                    'last_two' => ['58'],
                ], 200),
        ]);

        $maker = User::factory()->create();
        $first = $this->imports->importForDraw($this->draw, $maker);
        $second = $this->imports->importForDraw($this->draw, $maker);

        $this->assertSame('imported', $first['import']->status);
        $this->assertSame('imported', $second['import']->status);
        $this->assertNotSame($first['import']->result_fingerprint, $second['import']->result_fingerprint);
        $this->assertNotSame($first['ingestion']['fingerprint'], $second['ingestion']['fingerprint']);
        $this->assertSame(['223344'], $this->draw->fresh()->metadata['result_ingestion']['payload']['second_prize']);
        $this->assertSame('superseded', $this->draw->fresh()->metadata['result_ingestion_history'][0]['status']);
        $this->assertSame(0, DrawResult::query()->where('draw_id', $this->draw->getKey())->count());
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
        $this->assertSame((string) $this->draw->draw_number, $outcome['import']->upstream_draw_id);
        $this->assertSame('042042', $outcome['import']->payload_summary['first_prize']);
        $this->assertSame('58', $outcome['import']->payload_summary['bottom_two']);
        $this->assertSame(1, $outcome['import']->payload_summary['second_prize_count']);
        $this->assertSame('fixture', $outcome['import']->payload_summary['source_attempts'][0]['source']);
        $this->assertNotNull($outcome['import']->result_fingerprint);
        $this->assertArrayNotHasKey('payload', $outcome['import']->getAttributes());

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

    public function test_import_without_a_persisted_maker_is_refused_before_staging(): void
    {
        // A provider may return valid numbers, but without a persisted active
        // operator there is no maker for the four-eyes record. Preserve the
        // source response as a refused import and do not create an orphan Pending
        // record that can never be confirmed or safely repaired by an exact replay.
        $outcome = $this->imports->importForDraw($this->draw);

        $this->assertSame('refused', $outcome['import']->status);
        $this->assertStringContainsString('active operator', (string) $outcome['import']->failure_reason);
        $this->assertNull($outcome['ingestion']);
        $this->assertSame(0, DrawResult::query()->where('draw_id', $this->draw->getKey())->count());
        $this->assertArrayNotHasKey('result_ingestion', $this->draw->fresh()->metadata ?? []);
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

    public function test_import_with_missing_independent_bottom_two_is_recorded_as_refused(): void
    {
        $fingerprint = hash('sha256', 'provider-result-without-bottom-two');
        $this->mock(GloFixtureResultProvider::class, function ($mock) use ($fingerprint): void {
            $mock->shouldReceive('name')->andReturn('fixture');
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('fetch')->once()->andReturn([
                'status' => 'imported',
                'provider' => 'fixture',
                'endpoint' => 'fixture',
                'draw_number' => (string) $this->draw->draw_number,
                'first_prize' => '042042',
                'second_prize' => [],
                'third_prize' => [],
                'n3' => [],
                'tiers' => [],
                'failure_reason' => null,
                'fingerprint' => $fingerprint,
            ]);
        });

        $outcome = app(GloResultImportService::class)->importForDraw(
            $this->draw,
            User::factory()->create(),
        );

        $this->assertSame('refused', $outcome['import']->status);
        $this->assertNull($outcome['ingestion']);
        $this->assertNull($outcome['draw_result']);
        $this->assertStringContainsString('bottom_two', (string) $outcome['import']->failure_reason);
        $this->assertSame(0, DrawResult::query()->where('draw_id', $this->draw->getKey())->count());
    }

    public function test_import_of_a_payload_without_a_fingerprint_fails_closed(): void
    {
        // An `imported` payload with no fingerprint cannot be pinned afterwards,
        // so it is refused rather than staged. This guard was deleted by the
        // restore commit along with the rest of the boundary.
        //
        // The fixture source is swapped in the container so the autowired
        // GloResultProviderChain receives this strict test double. The importer
        // consumes the chain contract; it does not instantiate a provider.
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

    public function test_import_persists_only_bounded_redacted_provenance(): void
    {
        $fingerprint = hash('sha256', 'secret-safe-provider-payload');

        $this->mock(GloFixtureResultProvider::class, function ($mock) use ($fingerprint): void {
            $mock->shouldReceive('name')->andReturn('fixture');
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('fetch')->once()->andReturn([
                'status' => 'imported',
                'provider' => 'fixture',
                'endpoint' => 'https://fixtures.example/result?api_key=do-not-persist&signature=also-secret',
                'draw_number' => (string) $this->draw->draw_number,
                'first_prize' => '042042',
                'second_prize' => ['112233'],
                'third_prize' => ['600001'],
                'n3' => ['special' => ['007']],
                'tiers' => ['last_two' => ['58']],
                'failure_reason' => null,
                'fingerprint' => $fingerprint,
            ]);
        });

        $service = app(GloResultImportService::class);
        $outcome = $service->importForDraw($this->draw);
        $import = $outcome['import'];

        $this->assertStringContainsString('[redacted]', (string) $import->endpoint);
        $this->assertStringNotContainsString('do-not-persist', (string) $import->endpoint);
        $this->assertStringNotContainsString('also-secret', (string) $import->endpoint);
        $this->assertSame($fingerprint, $import->result_fingerprint);
        $this->assertSame('042042', $import->payload_summary['first_prize']);
        $this->assertSame(['special' => 1], $import->payload_summary['n3_counts']);
        $this->assertArrayNotHasKey('endpoint', $import->payload_summary);
        $this->assertArrayNotHasKey('payload', $import->getAttributes());
    }

    public function test_import_is_idempotent_on_same_fingerprint(): void
    {
        $maker = User::factory()->create();
        $first = $this->imports->importForDraw($this->draw, $maker);
        $second = $this->imports->importForDraw($this->draw, $maker);

        $this->assertSame($first['import']->getKey(), $second['import']->getKey());
        $this->assertSame(1, GloResultImport::query()->where('draw_id', $this->draw->getKey())->count());
        $this->assertSame(0, DrawResult::query()->where('draw_id', $this->draw->getKey())->count());
        $this->assertSame(
            $first['ingestion']['fingerprint'],
            $second['ingestion']['fingerprint'],
            'an identical provider fingerprint must retain one pending ingestion fingerprint',
        );
        $this->assertSame('pending', $second['ingestion']['status']);
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
        $replay = $this->imports->importLatest();

        $this->assertNotNull($outcome['import']);
        $this->assertNull($outcome['draw_result']);
        $this->assertSame('fixture', $outcome['import']->provider);
        $this->assertSame($outcome['import']->getKey(), $replay['import']->getKey());
        $this->assertSame(1, GloResultImport::query()->whereNull('draw_id')->count());
    }

    public function test_import_official_network_failure_writes_failed_provenance_not_results(): void
    {
        config(['glo.official_source.mode' => 'official']);
        Http::fake(function () {
            throw new \RuntimeException('down');
        });

        $outcome = $this->imports->importForDraw($this->draw);

        $this->assertSame('failed', $outcome['import']->status);
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
