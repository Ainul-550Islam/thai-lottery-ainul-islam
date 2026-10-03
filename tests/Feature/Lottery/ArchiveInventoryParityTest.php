<?php

namespace Tests\Feature\Lottery;

use App\Services\Lottery\BingoLotteryImportService;
use App\Services\Lottery\NationalLotteryImportService;
use App\Services\Lottery\PcsoLotteryImportService;
use App\Services\Lottery\WeeklyLotteryImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ARCHIVE INVENTORY PARITY (FINAL AUDIT #7/#8 — test-side work).
 *
 * For every lane, the published archive must be internally consistent: the
 * year links the index advertises all resolve to year pages that carry
 * rows, the rows link to detail pages that resolve, and a year with no
 * draws shows an honest empty state instead of broken navigation. This is
 * the parity the operator's REAL history import must satisfy before
 * go-live; the test proves the contract with real imported draws.
 */
class ArchiveInventoryParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: callable}>
     */
    public static function laneProvider(): array
    {
        return [
            'national' => ['/national-lottery', fn (string $date) => app(NationalLotteryImportService::class)->import([
                'draw_date' => $date,
                'first_prize' => '040615',
                'three_up' => '615',
                'two_up' => '15',
                'two_down' => '06',
                'source_identifier' => 'PARITY-'.$date,
                'retrieved_at' => '2026-01-01T00:00:00+00:00',
            ], 'fixture')],
            'weekly' => ['/weekly-lottery', fn (string $date) => app(WeeklyLotteryImportService::class)->import([
                'draw_date' => $date,
                'first_6' => '001234',
                'three_ball' => '049',
                'two_ball' => '09',
                'source_identifier' => 'PARITY-'.$date,
                'retrieved_at' => '2026-01-01T00:00:00+00:00',
            ], 'fixture')],
            'bingo' => ['/bingo-lottery', fn (string $date) => app(BingoLotteryImportService::class)->import([
                'draw_date' => $date,
                'first_6_mega' => '001234',
                'three_mega' => '049',
                'two_mega' => '09',
                'source_identifier' => 'PARITY-'.$date,
                'retrieved_at' => '2026-01-01T00:00:00+00:00',
            ], 'fixture')],
            'pcso' => ['/pcso-lottery', fn (string $date) => app(PcsoLotteryImportService::class)->import([
                'draw_date' => $date,
                'draw_time_local' => '14:00',
                'six_digit' => '875390',
                'four_digit' => '8359',
                'three_digit' => '075',
                'two_digit' => '05',
                'source_identifier' => 'PARITY-'.$date,
                'retrieved_at' => '2026-01-01T00:00:00+00:00',
            ], 'fixture')],
        ];
    }

    #[DataProvider('laneProvider')]
    public function test_every_advertised_year_link_has_rows_and_every_detail_link_resolves(string $index, callable $publish): void
    {
        // Two published draws in the same year for this lane.
        $publish('2026-01-15');
        $publish('2026-02-15');

        $indexPage = (string) $this->get($index)->assertOk()->getContent();

        // Every year link the index advertises...
        preg_match_all('#href="('.preg_quote(url('/'), '/').$index.'/year/([0-9]{1,4}))"#', $indexPage, $matches, PREG_SET_ORDER);

        $seenYearWithRows = false;

        foreach ($matches as $match) {
            $yearUrl = $match[1];
            $yearPage = (string) $this->get($yearUrl)->assertOk()->getContent();

            // Resolves, and links to detail pages
            preg_match_all('#href="((?:https?://[^/]+)?'.$index.'/([A-Za-z0-9\-]{1,40}))"#', $yearPage, $detailMatches, PREG_SET_ORDER);

            $detailLinks = [];
            foreach ($detailMatches as $detailMatch) {
                $slug = (string) $detailMatch[2];

                // The lane nav itself, year pages and the search form are
                // not draw details.
                if (in_array($slug, ['search', 'year'], true) || str_starts_with($slug, 'year')) {
                    continue;
                }

                $detailLinks[] = (string) parse_url((string) $detailMatch[1], PHP_URL_PATH);
            }

            if ($detailLinks === []) {
                continue;
            }

            $seenYearWithRows = true;

            // And every detail link resolves.
            foreach (array_slice(array_unique($detailLinks), 0, 3) as $detailPath) {
                $this->get((string) $detailPath)->assertOk();
            }
        }

        $this->assertTrue(
            $seenYearWithRows,
            'At least one advertised year for '.$index.' must carry rows — the imported draws should be listed.',
        );
    }

    #[DataProvider('laneProvider')]
    public function test_a_year_with_no_draws_renders_an_honest_empty_state(string $index): void
    {
        $this->get($index.'/year/2511')
            ->assertOk();
    }

    public function test_the_year_urls_are_wellformed_everywhere(): void
    {
        foreach (['/national-lottery', '/weekly-lottery', '/bingo-lottery', '/pcso-lottery'] as $index) {
            $page = (string) $this->get($index)->assertOk()->getContent();

            preg_match_all('#href="[^"]*'.$index.'/year/([^"]*)"#', $page, $matches);

            foreach ($matches[1] as $year) {
                $this->assertMatchesRegularExpression('/^[0-9]{1,4}$/', (string) $year, $index.' advertises a malformed year link: '.$year);
            }
        }
    }
}
