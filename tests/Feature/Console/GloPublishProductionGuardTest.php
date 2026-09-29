<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * GLO PUBLIC RESULT PUBLISH — PRODUCTION FIXTURE GUARD (audit finding S3).
 *
 * The official-source mode defaults to fixture for local and test boots.
 * In production that default must be a refusal, not a silent publication of
 * fixture-sourced results onto the public lane, unless an operator accepts
 * it explicitly with --allow-fixture-in-production.
 */
class GloPublishProductionGuardTest extends TestCase
{
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

    public function test_explicit_flag_acknowledges_the_fixture_mode_and_passes_the_gate(): void
    {
        config([
            'app.env' => 'production',
            'glo.official_source.mode' => 'fixture',
        ]);

        $exit = Artisan::call('glo:publish-public-result', [
            '--draw' => '999999',
            '--allow-fixture-in-production' => true,
        ]);

        // The gate is passed: the failure below it is the honest
        // "draw not found" for a draw that does not exist, NOT the refusal.
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Draw not found', Artisan::output());
        $this->assertStringNotContainsString('refusing to publish', Artisan::output());
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
