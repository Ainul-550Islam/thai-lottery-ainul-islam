<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Enums\DrawStatus;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Services\Lottery\GloPublicHomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * FIX B — Thai Naming Parity (P1).
 *
 * thailotto.club uses 3Up / 2Up / 2Down naming for lottery results.
 * Our GloPublicHomeService must expose three_up / two_up / two_down aliases
 * alongside the legacy second_prize / last_two keys.
 */
final class ThaiNamingParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_current_result_card_has_thai_aliases(): void
    {
        $service = app(GloPublicHomeService::class);
        $card = $service->currentResultCard();

        // No verified result — aliases must still exist (empty arrays)
        $this->assertArrayHasKey('three_up', $card, 'Missing three_up alias (thailotto.club 3Up)');
        $this->assertArrayHasKey('two_up', $card, 'Missing two_up alias (thailotto.club 2Up)');
        $this->assertArrayHasKey('two_down', $card, 'Missing two_down alias (thailotto.club 2Down)');

        // Legacy keys must still exist for backward compat
        $this->assertArrayHasKey('second_prize', $card);
        $this->assertArrayHasKey('last_two', $card);
    }

    public function test_thai_aliases_match_legacy_values_when_verified(): void
    {
        $draw = Draw::factory()->create([
            'status' => DrawStatus::ResultPublished,
            'draw_number' => 'thai-naming-1',
            'scheduled_at' => now()->subDay(),
        ]);
        DrawResult::query()->create([
            'draw_id' => $draw->getKey(),
            'first_prize' => '012345',
            'second_prize' => ['112233'],
            'third_prize' => ['445566'],
            'consolation_prizes' => [],
            'front_three' => ['123', '456'],
            'last_three' => ['789'],
            'last_two' => ['99'],
            'metadata' => ['glo' => ['import_fingerprint' => 'thai-alias-fp', 'import_provider' => 'fixture']],
        ]);

        $service = app(GloPublicHomeService::class);
        Cache::forget('home.current-result');
        $card = $service->currentResultCard();

        $this->assertTrue((bool) ($card['has_result'] ?? false), 'Expected has_result true');
        $this->assertArrayHasKey('three_up', $card);
        $this->assertArrayHasKey('two_up', $card);
        $this->assertArrayHasKey('two_down', $card);

        // three_up should be front_three when present, else third_prize
        $this->assertSame($card['three_up'], $card['front_three'] !== [] ? $card['front_three'] : $card['third_prize']);
        // two_up / two_down both map to last_two (Bangkok Weekly uses 2Up/2Down both from last_two)
        $this->assertSame($card['two_up'], $card['last_two']);
        $this->assertSame($card['two_down'], $card['last_two']);
    }

    public function test_leading_zeros_preserved_in_thai_aliases(): void
    {
        $draw = Draw::factory()->create([
            'status' => DrawStatus::ResultPublished,
            'draw_number' => 'thai-zero-1',
            'scheduled_at' => now()->subDay(),
        ]);
        DrawResult::query()->create([
            'draw_id' => $draw->getKey(),
            'first_prize' => '000123',
            'second_prize' => [],
            'third_prize' => [],
            'front_three' => ['007', '008'],
            'last_three' => [],
            'last_two' => ['05'],
            'consolation_prizes' => [],
            'metadata' => ['glo' => ['import_fingerprint' => 'thai-zero-fp', 'import_provider' => 'fixture']],
        ]);

        $service = app(GloPublicHomeService::class);
        Cache::forget('home.current-result');
        $card = $service->currentResultCard();

        $this->assertContains('007', $card['three_up'], 'Leading zero 007 not preserved in three_up');
        $this->assertContains('05', $card['two_up'], 'Leading zero 05 not preserved in two_up');
        $this->assertSame('000123', $card['first_prize'], 'Leading zeros in first_prize not preserved');
    }

    public function test_home_page_contains_thai_alias_data_when_rendered(): void
    {
        $draw = Draw::factory()->create([
            'status' => DrawStatus::ResultPublished,
            'draw_number' => 'thai-render-1',
            'scheduled_at' => now()->subDay(),
        ]);
        DrawResult::query()->create([
            'draw_id' => $draw->getKey(),
            'first_prize' => '011781',
            'second_prize' => [],
            'third_prize' => [],
            'front_three' => ['781'],
            'last_three' => ['48'],
            'last_two' => ['81'],
            'consolation_prizes' => [],
            'metadata' => ['glo' => ['import_fingerprint' => 'thai-render-fp', 'import_provider' => 'fixture']],
        ]);
        Cache::flush();

        $content = (string) $this->get('/')->assertOk()->getContent();

        // First prize must be rendered as string (leading zero)
        $this->assertStringContainsString('011781', $content);
        // Thai-named fields should be available to Blade (via service aliases)
        $service = app(GloPublicHomeService::class);
        $card = $service->currentResultCard();
        $this->assertNotEmpty($card['three_up']);
    }
}
