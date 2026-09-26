<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Enums\UserStatus;
use App\Enums\DrawStatus;
use App\Models\Draw;
use App\Models\DrawResult;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * GLO-18 public result experience: 6-digit, history window, sheet,
 * Data Matrix adapter states, live provider NOT_CONFIGURED, rate limiting shape.
 */
class GloPublicResultHistoryTest extends TestCase
{
    use DatabaseTruncation;

    private Draw $published;

    private Draw $oldDraw;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Cache::flush();

        $this->published = Draw::factory()->create([
            'status' => DrawStatus::ResultPublished,
            'scheduled_at' => now()->subDays(10),
            'result_published_at' => now()->subDays(9),
        ]);

        DrawResult::create([
            'draw_id' => $this->published->getKey(),
            'first_prize' => '000001',
            'second_prize' => ['112233'],
            'third_prize' => ['600001'],
            'published_at' => now()->subDays(9),
            'metadata' => [
                'glo' => [
                    'import_fingerprint' => 'fp-public-v1',
                    'import_provider' => 'fixture',
                ],
                'fourth' => ['700001'],
                'fifth' => ['800001'],
                'front_three' => ['123'],
                'last_three' => ['321'],
                'last_two' => ['01'],
            ],
        ]);

        $this->oldDraw = Draw::factory()->create([
            'status' => DrawStatus::Completed,
            'scheduled_at' => now()->subYears(3)->subDay(),
        ]);

        DrawResult::create([
            'draw_id' => $this->oldDraw->getKey(),
            'first_prize' => '999999',
            'second_prize' => [],
            'third_prize' => [],
            'published_at' => now()->subYears(3),
        ]);
    }

    public function test_valid_six_digit_with_leading_zeros(): void
    {
        $this->getJson('/api/v1/glo/results/check/000001')
            ->assertOk()
            ->assertJsonPath('data.ticket_number', '000001')
            ->assertJsonPath('data.won', true)
            ->assertJsonPath('data.source_state', 'FIXTURE_ONLY');
    }

    public function test_six_digit_check_post_body(): void
    {
        $this->postJson('/api/v1/glo/results/check', ['number' => '000001'])
            ->assertOk()
            ->assertJsonPath('data.ticket_number', '000001');
    }

    public function test_invalid_six_digit_rejected(): void
    {
        $this->postJson('/api/v1/glo/results/check', ['number' => '12345'])
            ->assertStatus(422);

        $this->postJson('/api/v1/glo/results/check', ['number' => '1234567'])
            ->assertStatus(422);

        // Route constraint on GET path rejects non-6-digit.
        $this->getJson('/api/v1/glo/results/check/12345')
            ->assertStatus(404);
    }

    public function test_number_is_string_not_int_in_response(): void
    {
        $response = $this->getJson('/api/v1/glo/results/check/000001');
        $response->assertOk();
        $this->assertSame('000001', $response->json('data.ticket_number'));
        $this->assertIsString($response->json('data.ticket_number'));
    }

    public function test_current_result_endpoint(): void
    {
        $this->getJson('/api/v1/glo/results')
            ->assertOk()
            ->assertJsonPath('data.has_result', true)
            ->assertJsonPath('data.first_prize', '000001');
    }

    public function test_history_excludes_older_than_two_years(): void
    {
        $response = $this->getJson('/api/v1/glo/results/history');
        $response->assertOk();

        $ids = array_column($response->json('data'), 'draw_id');
        $this->assertContains($this->published->getKey(), $ids);
        $this->assertNotContains($this->oldDraw->getKey(), $ids);
        $this->assertSame(2, $response->json('meta.history_years'));
    }

    public function test_history_explicit_old_from_date_rejected(): void
    {
        $this->getJson('/api/v1/glo/results/history?from='.now()->subYears(5)->toDateString())
            ->assertStatus(422);
    }

    public function test_history_pagination(): void
    {
        $this->getJson('/api/v1/glo/results/history?page=1')
            ->assertOk()
            ->assertJsonStructure(['meta' => ['page', 'per_page', 'total', 'last_page']]);
    }

    public function test_result_sheet_labels_fixture_not_official(): void
    {
        $response = $this->getJson('/api/v1/glo/results/sheet');
        $response->assertOk();

        $sheet = $response->json('data');
        $this->assertSame('glo_result_check_sheet', $sheet['sheet_type']);
        $this->assertSame('FIXTURE_ONLY', $sheet['source_state']);
        $this->assertSame('INTERNAL DEMO / FIXTURE', $sheet['source_badge']);
        $this->assertStringContainsString('Not an officially issued', $sheet['disclaimer']);
        $this->assertArrayHasKey('categories', $sheet);
    }

    public function test_result_sheet_can_label_official_when_import_provider_official(): void
    {
        DrawResult::query()->where('draw_id', $this->published->getKey())->update([
            'metadata' => json_encode([
                'glo' => [
                    'import_fingerprint' => 'fp-official-1',
                    'import_provider' => 'official',
                ],
            ]),
        ]);

        $sheet = $this->getJson('/api/v1/glo/results/sheet')->json('data');
        $this->assertSame('OFFICIAL_SOURCE_VERIFIED', $sheet['source_state']);
        $this->assertSame('GLO VERIFIED SOURCE', $sheet['source_badge']);
    }

    public function test_datamatrix_fixture_envelope_parses(): void
    {
        $payload = json_encode([
            'schema' => 'SYNTHETIC_FIXTURE_V1',
            'ticket_number' => '000001',
            'draw_number' => $this->published->draw_number,
        ], JSON_THROW_ON_ERROR);

        $this->postJson('/api/v1/glo/results/datamatrix', ['payload' => $payload])
            ->assertOk()
            ->assertJsonPath('data.parse_status', 'FIXTURE_PARSED')
            ->assertJsonPath('data.source_state', 'FIXTURE_ONLY')
            ->assertJsonPath('data.ticket_number', '000001');
    }

    public function test_datamatrix_opaque_payload_unsupported(): void
    {
        $this->postJson('/api/v1/glo/results/datamatrix', ['payload' => base64_encode(random_bytes(64))])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'UNSUPPORTED_FORMAT')
            ->assertJsonPath('error.details.source_state', 'NOT_CONFIGURED');
    }

    public function test_datamatrix_empty_rejected(): void
    {
        $this->postJson('/api/v1/glo/results/datamatrix', ['payload' => ''])
            ->assertStatus(422);
    }

    public function test_datamatrix_unknown_json_schema_not_configured(): void
    {
        $this->postJson('/api/v1/glo/results/datamatrix', [
            'payload' => '{"schema":"TOTALLY_UNKNOWN","ticket_number":"000001"}',
        ])->assertStatus(503);
    }

    public function test_live_draw_not_configured(): void
    {
        $this->getJson('/api/v1/glo/results/live')
            ->assertOk()
            ->assertJsonPath('data.live_status', 'not_configured')
            ->assertJsonPath('data.source_state', 'NOT_CONFIGURED');
    }

    public function test_result_cache_key_includes_version(): void
    {
        $a = $this->getJson('/api/v1/glo/results')->json('data');
        $b = $this->getJson('/api/v1/glo/results')->json('data');
        $this->assertSame($a['result_version'], $b['result_version']);
        $this->assertSame('fp-public-v1', $a['result_version']);
    }

    public function test_public_routes_throttled_by_glo_public(): void
    {
        // Smoke: many sequential hits still succeed (limiter allows 30/min);
        // failure would be 429 beyond limit — we only assert first N OK.
        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/v1/glo/results/check/000001')->assertOk();
        }
    }

    public function test_no_pii_in_result_payloads(): void
    {
        $sheet = $this->getJson('/api/v1/glo/results/sheet')->json();
        $check = $this->getJson('/api/v1/glo/results/check/000001')->json();
        $encoded = json_encode([$sheet, $check]);

        $this->assertStringNotContainsString('password', (string) $encoded);
        $this->assertStringNotContainsString('email', strtolower((string) $encoded));
        $this->assertStringNotContainsString('id_card', strtolower((string) $encoded));
        $this->assertStringNotContainsString('national_id', strtolower((string) $encoded));
    }

    private function freshUser(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return User::factory()->state(fn (): array => [
            'email' => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(12)).'.'.bin2hex(random_bytes(4)).'@glofreeze.local',
            'username' => 'gz'.\Illuminate\Support\Str::random(10),
            'phone' => '+8801'.random_int(100_000_000, 999_999_999),
            'status' => UserStatus::Active,
        ]);
    }
}
