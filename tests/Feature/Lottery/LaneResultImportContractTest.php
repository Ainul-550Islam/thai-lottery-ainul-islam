<?php

namespace Tests\Feature\Lottery;

use App\Models\NationalLotteryDraw;
use App\Models\NationalLotteryResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * LANE IMPORT CONTRACT (FINAL AUDIT #7/#8 — test-side work).
 *
 * The result-history data itself is an OPERATIONAL deliverable (approved
 * payloads, never fabricated here). What the repository pins in code is the
 * import CONTRACT: values arrive with canonical widths (leading zeros
 * intact), malformed numbers and dates are REFUSED rather than repaired,
 * duplicates are refused as conflicts, and a rejected row persists nothing.
 */
class LaneResultImportContractTest extends TestCase
{
    use RefreshDatabase;

    private function payloadFile(array $payload): string
    {
        $path = storage_path('app/test-import-'.bin2hex(random_bytes(4)).'.json');
        File::put($path, json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function validNationalPayload(): array
    {
        return [
            'draw_date' => '2025-09-16',
            'first_prize' => '040615',
            'three_up' => '615',
            'two_up' => '15',
            'two_down' => '06',
            'three_front' => ['060', '521'],
            'three_after' => ['041'],
            'source_identifier' => 'glo-2025-09-16',
            'retrieved_at' => '2025-09-16T15:30:00+00:00',
        ];
    }

    public function test_a_valid_import_preserves_canonical_widths_including_leading_zeros(): void
    {
        $this->artisan('national-lottery:import', [
            '--file' => $this->payloadFile($this->validNationalPayload()),
            '--provider' => 'internal',
            '--json' => true,
        ])->assertExitCode(0);

        $result = NationalLotteryResult::query()->first();
        $this->assertNotNull($result, 'The import must persist the result.');

        // Dedicated columns, canonical widths, leading zeros intact:
        // '040615' — not 40615, not '40615'.
        $this->assertSame('040615', (string) $result->first_prize);
        $this->assertSame('06', (string) $result->two_down);
        $this->assertSame('15', (string) $result->two_up);
        $this->assertSame(['060', '521'], array_map(strval(...), (array) $result->three_front));
    }

    public function test_a_malformed_first_prize_width_is_refused_and_persists_nothing(): void
    {
        $payload = $this->validNationalPayload();
        $payload['first_prize'] = '40615'; // five digits — malformed

        $this->artisan('national-lottery:import', [
            '--file' => $this->payloadFile($payload),
            '--provider' => 'internal',
            '--json' => true,
        ])->assertNotExitCode(0);

        $this->assertDatabaseCount('national_lottery_results', 0);
        $this->assertDatabaseCount('national_lottery_draws', 0);
    }

    public function test_a_malformed_two_digit_value_is_refused_rather_than_padded(): void
    {
        $payload = $this->validNationalPayload();
        $payload['two_down'] = '6'; // one digit — must NOT become '06'

        $this->artisan('national-lottery:import', [
            '--file' => $this->payloadFile($payload),
            '--provider' => 'internal',
            '--json' => true,
        ])->assertNotExitCode(0);

        $this->assertDatabaseCount('national_lottery_results', 0);
    }

    public function test_a_malformed_draw_date_is_refused_and_persists_nothing(): void
    {
        $payload = $this->validNationalPayload();
        $payload['draw_date'] = '16/09/2025 5pm'; // not an accepted format

        $this->artisan('national-lottery:import', [
            '--file' => $this->payloadFile($payload),
            '--provider' => 'internal',
            '--json' => true,
        ])->assertNotExitCode(0);

        $this->assertDatabaseCount('national_lottery_draws', 0);
    }

    public function test_importing_the_same_draw_twice_is_a_conflict_not_a_second_result(): void
    {
        $file = $this->payloadFile($this->validNationalPayload());

        $this->artisan('national-lottery:import', [
            '--file' => $file,
            '--provider' => 'internal',
            '--json' => true,
        ])->assertExitCode(0);

        // Same draw, DIFFERENT numbers: a conflict, refused loudly. The
        // conflicting delivery may leave an evidence row (versioned, not
        // current) but it can never replace the published result.
        $payload = $this->validNationalPayload();
        $payload['first_prize'] = '999999';

        $this->artisan('national-lottery:import', [
            '--file' => $this->payloadFile($payload),
            '--provider' => 'internal',
            '--json' => true,
        ])->assertExitCode(2);

        $current = NationalLotteryResult::query()->where('is_current', true)->get();
        $this->assertCount(1, $current);
        $this->assertSame('040615', (string) $current->first()->first_prize);
    }

    public function test_an_import_with_a_publishing_step_marks_the_draw_published(): void
    {
        $this->artisan('national-lottery:import', [
            '--file' => $this->payloadFile($this->validNationalPayload()),
            '--provider' => 'internal',
            '--json' => true,
        ])->assertExitCode(0);

        $draw = NationalLotteryDraw::query()->first();

        $this->assertNotNull($draw);
        $this->assertTrue($draw->publication_status === \App\Enums\DrawPublicationStatus::Published);
    }
}
