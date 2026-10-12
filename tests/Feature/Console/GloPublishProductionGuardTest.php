<?php

namespace Tests\Feature\Console;

use App\Enums\DrawStatus;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\User;
use Tests\Feature\Glo\Concerns\CreatesTrustedGloResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * GLO PUBLIC RESULT PUBLISH — PRODUCTION FIXTURE GUARD (audit finding S3).
 *
 * Fixture mode and fixture-provenance result rows are always refused in
 * production. No command-line acknowledgement may override that security rule.
 */
class GloPublishProductionGuardTest extends TestCase
{
    use CreatesTrustedGloResult;
    use RefreshDatabase;

    public function test_production_with_fixture_mode_refuses_to_publish(): void
    {
        config([
            'app.env' => 'production',
            'glo.official_source.mode' => 'fixture',
        ]);

        $exit = Artisan::call('glo:publish-public-result', ['--draw' => '999999']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            'refusing to publish fixture-sourced results in production',
            Artisan::output()
        );
    }

    public function test_fixture_provenance_is_refused_in_production_even_when_source_mode_is_official(): void
    {
        config([
            'app.env' => 'production',
            'glo.official_source.mode' => 'official',
        ]);
        Cache::flush();

        $draw = Draw::factory()->create([
            'status' => DrawStatus::ResultPublished,
            'scheduled_at' => now()->subDay(),
        ]);
        DrawResult::query()->create([
            'draw_id' => $draw->getKey(),
            'first_prize' => '100100',
            'second_prize' => [],
            'third_prize' => [],
            'consolation_prizes' => [],
            'metadata' => [
                'provenance' => 'fixture',
                'provenance_reference' => 'fixture-import-1',
                'glo' => [
                    'import_provider' => 'fixture',
                    'import_fingerprint' => str_repeat('a', 64),
                ],
            ],
        ]);

        $exit = Artisan::call('glo:publish-public-result', ['--draw' => (string) $draw->getKey()]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Fixture provenance is forbidden in production', Artisan::output());
        $this->assertNull(Cache::get('glo:public-result:current:'.$draw->getKey()));
    }

    public function test_unknown_persisted_provenance_is_not_promoted_to_official(): void
    {
        config([
            'app.env' => 'testing',
            'glo.official_source.mode' => 'official',
        ]);
        Cache::flush();

        $draw = Draw::factory()->create([
            'status' => DrawStatus::ResultPublished,
            'scheduled_at' => now()->subDay(),
        ]);
        DrawResult::query()->create([
            'draw_id' => $draw->getKey(),
            'first_prize' => '100100',
            'second_prize' => [],
            'third_prize' => [],
            'consolation_prizes' => [],
            'metadata' => [
                'provenance' => 'unknown',
                'provenance_reference' => 'caller-supplied',
                'glo' => [
                    'import_provider' => 'unrecognized-provider',
                    'import_fingerprint' => str_repeat('b', 64),
                ],
            ],
        ]);

        $exit = Artisan::call('glo:publish-public-result', ['--draw' => (string) $draw->getKey()]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('unknown or unsupported', Artisan::output());
        $this->assertNull(Cache::get('glo:public-result:current:'.$draw->getKey()));
    }

    public function test_verified_official_provenance_is_published_as_official(): void
    {
        config([
            'app.env' => 'testing',
            'glo.official_source.mode' => 'official',
        ]);
        Cache::flush();

        $draw = Draw::factory()->create([
            'status' => DrawStatus::ResultPublished,
            'scheduled_at' => now()->subDay(),
        ]);
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        DrawResult::query()->create([
            'draw_id' => $draw->getKey(),
            'first_prize' => '100100',
            'second_prize' => [],
            'third_prize' => [],
            'consolation_prizes' => [],
            'published_at' => now(),
            'metadata' => $this->trustedFixtureResultMetadata(
                $draw,
                $maker,
                $checker,
                '100100',
                '58',
                [],
                [],
                [],
                [],
                'official-import-1',
                str_repeat('c', 64),
                'official',
                'official',
            ),
        ]);

        $exit = Artisan::call('glo:publish-public-result', ['--draw' => (string) $draw->getKey()]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('OFFICIAL_SOURCE_VERIFIED', Artisan::output());
    }

    public function test_production_with_official_mode_passes_the_gate(): void
    {
        config([
            'app.env' => 'production',
            'glo.official_source.mode' => 'official',
        ]);

        $exit = Artisan::call('glo:publish-public-result', ['--draw' => '999999']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Draw not found', Artisan::output());
    }

    public function test_non_production_environments_are_not_gated(): void
    {
        // Default test environment: fixture mode is fine.
        config(['glo.official_source.mode' => 'fixture']);

        $exit = Artisan::call('glo:publish-public-result', ['--draw' => '999999']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Draw not found', Artisan::output());
    }
}
