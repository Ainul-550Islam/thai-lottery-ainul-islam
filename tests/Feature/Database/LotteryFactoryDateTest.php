<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\BingoLotteryDraw;
use App\Models\NationalLotteryDraw;
use App\Models\WeeklyLotteryDraw;
use Database\Factories\Support\LotteryFactoryDate;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Regression for a real full-suite random date collision; no assertions/unique constraints are weakened. */
final class LotteryFactoryDateTest extends TestCase
{
    use DatabaseTruncation;

    public static function lanes(): array
    {
        return [[BingoLotteryDraw::class], [WeeklyLotteryDraw::class], [NationalLotteryDraw::class]];
    }

    #[DataProvider('lanes')]
    public function test_identically_seeded_factories_find_distinct_existing_database_dates(string $model): void
    {
        fake()->seed(64119);
        $first = $model::factory()->create();
        fake()->seed(64119);
        $second = $model::factory()->create();
        $this->assertNotSame($first->draw_date->toDateString(), $second->draw_date->toDateString());
        $this->assertTrue($first->exists && $second->exists);
        $this->assertSame($first->draw_date->toDateString(), $model::findOrFail($first->id)->draw_date->toDateString());
    }

    public function test_finite_factory_calendar_refuses_exhaustion_instead_of_looping_or_bypassing_constraint(): void
    {
        $timezone = (string) config('bingo_lottery.timezone', 'Asia/Bangkok');
        $sample = BingoLotteryDraw::factory()->create();
        $row = (array) DB::table('bingo_lottery_draws')->where('id', $sample->id)->sole();
        unset($row['id']);
        DB::table('bingo_lottery_draws')->delete();
        $batch = [];
        for ($day = 1; $day <= 900; $day++) {
            $date = now($timezone)->subDays($day)->toDateString();
            $batch[] = array_replace($row, ['uuid' => (string) Str::uuid(), 'draw_date' => $date,
                'draw_year' => (int) substr($date, 0, 4), 'draw_reference' => BingoLotteryDraw::buildReference($date)]);
            if (count($batch) === 50) {
                DB::table('bingo_lottery_draws')->insert($batch);
                $batch = [];
            }
        }
        try {
            LotteryFactoryDate::previous(BingoLotteryDraw::class, $timezone, 1);
            $this->fail('Exhausted calendar silently granted a duplicate date.');
        } catch (\OverflowException $e) {
            $this->assertStringContainsString('All900', $e->getMessage());
        }
        $this->assertDatabaseCount('bingo_lottery_draws', 900);
    }
}
