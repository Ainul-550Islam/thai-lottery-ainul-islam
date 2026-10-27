<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Services\Lottery\BingoLotteryImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Feature tests for Home Data Integrity (Prompt 01).
 * Verifies leading zero preservation, no fake production numbers, no int-casting of digit sequences.
 */
final class HomeDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_leading_zeros_are_strictly_preserved_in_home_digest(): void
    {
        app(BingoLotteryImportService::class)->import([
            'draw_date' => now()->subDays(1)->format('Y-m-d'),
            'first_6_mega' => '004921',
            'three_mega' => '007',
            'two_mega' => '05',
            'source_identifier' => 'TEST-LEADING-ZEROS',
            'retrieved_at' => now()->toIso8601String(),
        ], 'fixture');

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('004921', false);
        $response->assertSee('007', false);
        $response->assertSee('05', false);
    }

    public function test_empty_draw_state_does_not_render_arbitrary_zeros(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        // Zero-fill should never be rendered as a substitute for an empty result
        $this->assertStringNotContainsString('000000000', (string) $response->getContent());
    }
}
