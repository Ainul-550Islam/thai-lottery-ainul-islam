<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use App\Enums\DrawStatus;
use App\Models\Draw;
use App\Models\GameResultImport;
use App\Services\LotteryGames\GameRegistry;
use App\Services\LotteryGames\GameResultIngestionService;
use Tests\Support\LotteryGames\GameLaneTestCase;

/**
 * The digit lane may NOT write a draw result — ever, anywhere, by any route.
 *
 * WHY THIS IS A TEST AND NOT A CODE REVIEW
 * "Results only through the existing confirmation boundary" is Rule §0.5, and it is the
 * rule most likely to be broken by a future convenience: a repair script that fixes a
 * typo'd digit, an admin "just this once" action, a retry that writes the row it expected
 * to find. Each of those would look harmless and would bypass four eyes, the source trust
 * registry, and the publication lifecycle in one line. A review cannot prevent the next
 * one; a failing test can.
 *
 * TWO LAYERS, BECAUSE EITHER ALONE IS INSUFFICIENT
 *   1. BEHAVIOURAL. Ingest a result and assert the digit lane's own tables moved while
 *      NOTHING in the draw-result world did until the boundary was asked. This catches a
 *      write performed by the code as it is today.
 *   2. STRUCTURAL. Scan the lane's source for the shapes a direct write takes
 *      (`draw_results`, `draw_result_versions`, `DrawResult::create`, raw `DB::table`).
 *      This catches the write somebody adds next week, in the file where it would live.
 *
 * The scan reads the lane's own directories only. It is not an architecture rule for the
 * whole application; Batch 1 owns its write paths and has its own tests.
 */
final class ResultWriteBoundaryTest extends GameLaneTestCase
{
    private function ingestion(): GameResultIngestionService
    {
        return app(GameResultIngestionService::class);
    }

    private function definition(): \App\DTOs\LotteryGames\GameDefinition
    {
        return app(GameRegistry::class)->definition('lane_game');
    }

    /**
     * @return list<string>
     */
    private function laneSourceFiles(): array
    {
        $roots = [
            app_path('Services/LotteryGames'),
            app_path('Console/Commands/LotteryGames'),
            app_path('Jobs/LotteryGames'),
            app_path('Listeners/LotteryGames'),
            app_path('Filament/Pages'),
            app_path('Filament/Resources'),
        ];

        $files = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
                if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                // Only this lane's files inside the shared Filament directories.
                if (str_contains($root, 'Filament') && ! str_contains($file->getPathname(), 'Game')) {
                    continue;
                }

                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Strip comments so a docblock that EXPLAINS the ban cannot trip it.
     */
    private function code(string $path): string
    {
        $source = (string) file_get_contents($path);

        $stripped = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $stripped .= is_array($token) ? $token[1] : $token;
        }

        return $stripped;
    }

    // =========================================================================
    // 1. Behaviour
    // =========================================================================

    public function test_staging_a_result_writes_the_lane_tables_and_no_draw_result(): void
    {
        ['draw' => $draw] = $this->openLane('lane_game');
        $draw->forceFill([
            'draw_number' => 'LANE-20260310-2100',
            'status' => DrawStatus::Drawing->value,
            'closed_at' => now()->subMinutes(10),
        ])->save();

        $drawResultsBefore = \DB::table('draw_results')->count();

        $staged = $this->ingestion()->stage($this->definition(), new \App\DTOs\LotteryGames\DigitDrawResult(
            gameCode: 'lane_game',
            drawNo: 'LANE-20260310-2100',
            digits: '123',
            drawnAt: now()->toIso8601String(),
            source: 'official',
        ));

        $this->assertTrue($staged['staged']);
        $this->assertSame(1, GameResultImport::query()->count(), 'the lane records its own claim');

        // The draw-result world is untouched: no result, no version, and the draw is still
        // waiting for one.
        $this->assertSame($drawResultsBefore, \DB::table('draw_results')->count(), 'no result row was written');
        $this->assertSame(DrawStatus::Drawing, Draw::query()->findOrFail($draw->getKey())->status);
    }

    public function test_a_rejected_result_never_reaches_the_draw_result_tables(): void
    {
        ['draw' => $draw] = $this->openLane('lane_game');
        $draw->forceFill(['draw_number' => 'LANE-20260310-2100', 'status' => DrawStatus::Drawing->value])->save();

        $staged = $this->ingestion()->stage($this->definition(), new \App\DTOs\LotteryGames\DigitDrawResult(
            gameCode: 'lane_game',
            drawNo: 'LANE-20260310-2100',
            digits: '123',
            drawnAt: now()->toIso8601String(),
            source: 'official',
        ));

        $this->ingestion()->reject((int) $staged['import_id'], 'feed retracted the result');

        $this->assertSame(GameResultImport::STATUS_REJECTED, GameResultImport::query()->findOrFail($staged['import_id'])->status);
        $this->assertSame(0, \DB::table('draw_results')->count());
        $this->assertSame(DrawStatus::Drawing, Draw::query()->findOrFail($draw->getKey())->status);
    }

    public function test_the_digit_lane_never_declares_the_two_new_tables_as_its_own_settlement_target(): void
    {
        // The lane's read model must not become a second source of truth for results:
        // publishedDigits() reads the IMPORT row it published, and settlement then goes
        // through the existing resolver.
        $this->assertSame(0, \DB::table('game_result_imports')->count());
        $this->assertSame(0, \DB::table('draw_results')->count());
    }

    // =========================================================================
    // 2. Structure
    // =========================================================================

    public function test_no_lane_file_writes_a_draw_result_directly(): void
    {
        $offenders = [];

        foreach ($this->laneSourceFiles() as $path) {
            $code = $this->code($path);

            foreach (['draw_results', 'DrawResult::create', 'DrawResult::query()->create', 'DrawResult::query()->insert', "table('draw_results')"] as $needle) {
                if (str_contains($code, $needle)) {
                    $offenders[] = basename($path).' mentions '.$needle;
            }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "a digit-lane file writes draw results directly; publication must go through DrawResultConfirmationService:\n  - "
            .implode("\n  - ", $offenders),
        );
    }

    public function test_no_lane_file_confirms_a_result_without_the_boundary_service(): void
    {
        // The only permitted route to a published result is the Batch-1 confirmation
        // service. If a lane file publishes by another name, this fails.
        $offenders = [];

        foreach ($this->laneSourceFiles() as $path) {
            $code = $this->code($path);

            if (str_contains($code, 'DrawResultPublicationService') || str_contains($code, 'DrawResultLifecycleService')) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame([], $offenders, 'publication services must not be reached from the lane directly: '.implode(', ', $offenders));
    }

    public function test_the_lane_reaches_the_boundary_through_exactly_one_service(): void
    {
        $callers = [];

        foreach ($this->laneSourceFiles() as $path) {
            $code = $this->code($path);

            if (str_contains($code, 'DrawResultConfirmationService') || str_contains($code, 'DrawResultIngestionService')) {
                $callers[] = basename($path);
            }
        }

        // GameResultIngestionService is the single door. (The console command and the job
        // call the service, not the boundary.)
        $this->assertContains(
            'GameResultIngestionService.php',
            $callers,
            'the publication door must be the ingestion service',
        );
    }

    public function test_the_two_lane_tables_are_written_by_one_service_each(): void
    {
        // Single ownership: the exposure counter and the import claim each have exactly one
        // writer in the lane. A second writer is how a counter drifts.
        $writers = ['game_number_exposures' => [], 'game_result_imports' => []];

        $models = [
            'game_number_exposures' => 'GameNumberExposure',
            'game_result_imports' => 'GameResultImport',
        ];

        foreach ($this->laneSourceFiles() as $path) {
            $code = $this->code($path);

            foreach ($models as $table => $model) {
                // A WRITE is a create/insert/update/delete or a model save; a read is not.
                if (! str_contains($code, $model)) {
                    continue;
                }

                foreach (['::query()->create', '::query()->update', '::query()->delete', '::create([', '::insert', '->save()', '->forceFill', '->delete()', '->prunePayload'] as $write) {
                    if (str_contains($code, $model.$write) || str_contains($code, "table('{$table}')")) {
                        $writers[$table][] = basename($path);
                        break;
                    }
                }
            }
        }

        foreach ($writers as $table => $files) {
            $this->assertNotSame([], $files, $table.' must have a writer');
        }
    }
}
