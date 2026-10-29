<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\DrawStatus;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloResultImport;
use App\Models\User;
use App\Services\Draw\DrawResultIngestionService;
use App\Services\Draw\DrawResultPublicationService;
use App\Services\Lottery\GloOfficialResultProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class FetchGloCanonicalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'glo.official_source.mode' => 'official',
            'glo.official_source.base_url' => 'https://results.example.test',
            'glo.official_source.lottery_result_path' => '/result',
            'glo.official_source.latest_lottery_path' => '/latest',
            'glo.official_source.request_retries' => 0,
        ]);
        Http::preventStrayRequests();
    }

    private function operator(): User
    {
        Role::findOrCreate('super-admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        return $user;
    }

    private function draw(string $number = 'GLO-TEST-001'): Draw
    {
        return Draw::factory()->closed()->create([
            'draw_number' => $number,
            'draw_code' => $number,
            'scheduled_at' => now()->subDay(),
        ]);
    }

    private function response(array $overrides = []): array
    {
        return array_merge([
            'draw' => 'GLO-TEST-001',
            'first' => '001234',
            // This is an independent prize, not the tail of the first prize.
            'last_two' => ['78'],
            'second' => ['222222'],
            'third' => ['333333'],
        ], $overrides);
    }

    public function test_fetch_stages_canonical_provenance_and_only_a_distinct_operator_can_publish(): void
    {
        $maker = $this->operator();
        $checker = $this->operator();
        $draw = $this->draw();
        config(['services.system.automated_operator_id' => $maker->id]);
        Http::fake(['results.example.test/*' => Http::response($this->response(), 200)]);
        $this->app['env'] = 'production';

        $this->assertTrue(app(GloOfficialResultProvider::class)->isConfigured(), 'Production must allow a real official provider, not fixtures.');
        $this->artisan('glo:fetch-result', ['draw_code' => $draw->draw_code])->assertSuccessful();
        $this->assertSame(DrawStatus::Drawing, $draw->fresh()->status);
        $this->assertSame(0, DrawResult::query()->count());
        $import = GloResultImport::query()->sole();
        $this->assertSame('official', $import->provider);
        $this->assertSame('imported', $import->status);
        $this->assertSame($maker->id, $import->imported_by);
        $pending = app(DrawResultIngestionService::class)->pendingIngestion($draw->id);
        $this->assertSame($maker->id, $pending['ingested_by']);
        $this->assertSame('001234', $pending['payload']['first_prize']);
        $this->assertSame('78', $pending['payload']['bottom_two']);
        $this->assertSame('glo_import:official', $pending['source']);

        $arguments = [
            'draw' => $draw->draw_number,
            '--first-prize' => '001234', '--bottom-two' => '78',
            '--operator-id' => (string) $maker->id, '--confirm' => true, '--yes' => true,
        ];
        $this->artisan('lottery:publish-result', $arguments)->assertFailed();
        $this->assertSame(0, DrawResult::query()->count());
        $arguments['--operator-id'] = (string) User::factory()->create()->id;
        $this->artisan('lottery:publish-result', $arguments)->assertFailed();
        $this->assertSame(0, DrawResult::query()->count(), 'A distinct but unauthorized user is not a checker.');
        $arguments['--operator-id'] = (string) $checker->id;
        $this->artisan('lottery:publish-result', $arguments)->assertSuccessful();
        $result = DrawResult::query()->sole();
        $this->assertSame('001234', $result->first_prize);
        $this->assertSame('78', $result->metadata['bottom_two']);
        $verified = app(DrawResultPublicationService::class)->verifiedProvenanceFor($draw->fresh(), $result);
        $this->assertSame('source-verified', $verified['trust']);
        $this->assertSame('official', $verified['provider']);
        $this->assertSame($import->import_reference, $verified['import_reference']);
        Http::assertSentCount(1);
    }

    public function test_dry_run_writes_no_import_draw_result_or_draw_state(): void
    {
        $draw = $this->draw();
        config(['services.system.automated_operator_id' => null]);
        Http::fake(['results.example.test/*' => Http::response($this->response(), 200)]);
        $this->artisan('glo:fetch-result', ['draw_code' => $draw->draw_code, '--dry-run' => true])
            ->expectsOutputToContain('Nothing was written')
            ->assertSuccessful();
        $this->assertSame(DrawStatus::Closed, $draw->fresh()->status);
        $this->assertNull(app(DrawResultIngestionService::class)->pendingIngestion($draw->id));
        $this->assertSame(0, GloResultImport::query()->count());
        $this->assertSame(0, DrawResult::query()->count());
    }

    public function test_missing_or_unauthorized_maker_refuses_before_network_or_writes(): void
    {
        $draw = $this->draw();
        Http::fake();
        foreach ([null, User::factory()->create()->id] as $id) {
            config(['services.system.automated_operator_id' => $id]);
            $this->artisan('glo:fetch-result', ['draw_code' => $draw->draw_code])->assertFailed();
        }
        Http::assertNothingSent();
        $this->assertSame(0, GloResultImport::query()->count());
        $this->assertSame(0, DrawResult::query()->count());
    }

    #[DataProvider('badResponses')]
    public function test_ambiguous_or_incomplete_source_results_never_stage_or_publish(array $override): void
    {
        $maker = $this->operator();
        $draw = $this->draw();
        config(['services.system.automated_operator_id' => $maker->id]);
        Http::fake(['results.example.test/*' => Http::response($this->response($override), 200)]);
        $this->artisan('glo:fetch-result', ['draw_code' => $draw->draw_code])->assertFailed();
        $this->assertSame(DrawStatus::Closed, $draw->fresh()->status);
        $this->assertNull(app(DrawResultIngestionService::class)->pendingIngestion($draw->id));
        $this->assertSame(0, DrawResult::query()->count());
    }

    public static function badResponses(): array
    {
        return [
            'wrong draw' => [['draw' => 'ANOTHER-DRAW']],
            'missing draw' => [['draw' => null]],
            'short integer first prize' => [['first' => 1234]],
            'missing independently drawn bottom prize' => [['last_two' => []]],
        ];
    }

    public function test_automated_fetch_never_replaces_an_existing_pending_result(): void
    {
        $maker = $this->operator();
        $draw = $this->draw();
        app(DrawResultIngestionService::class)->ingest($draw->id, ['first_prize' => '777777', 'bottom_two' => '88'], 'internal', $maker->id);
        config(['services.system.automated_operator_id' => $maker->id]);
        Http::fake();
        $this->artisan('glo:fetch-result', ['draw_code' => $draw->draw_code])->assertSuccessful();
        Http::assertNothingSent();
        $pending = app(DrawResultIngestionService::class)->pendingIngestion($draw->id);
        $this->assertSame('777777', $pending['payload']['first_prize']);
        $this->assertSame(0, GloResultImport::query()->count());
    }
}
