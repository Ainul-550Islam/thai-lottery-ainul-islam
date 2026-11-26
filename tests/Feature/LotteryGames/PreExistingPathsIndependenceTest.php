<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use App\Services\Draw\DrawResultIngestionService;
use Tests\TestCase;

/**
 * GLO L6, PCSO 6D and the result boundary must not be reachable from Batch 2.
 *
 * WHY THIS IS A STATIC PROOF AND NOT ANOTHER PURCHASE TEST
 * ExistingGamesRegressionTest already proves that a Batch-1 purchase and a cancellation
 * behave as before. What it cannot say is anything about the GLO L6 and PCSO 6D code
 * paths, because those have their own services and their own fixtures. The strongest
 * claim available without re-implementing their fixtures is the structural one: nothing
 * those games run references anything Batch 2 added, so no Batch-2 change can alter what
 * they do. If a future change makes a GLO or PCSO file depend on the digit lane, this test
 * fails and names the file - at which point a behavioural regression test for that game is
 * owed, not optional.
 *
 * The files are DISCOVERED (every app/ file whose name carries Glo or Pcso), so a new GLO
 * or PCSO class is covered the day it is added.
 *
 * THE RESULT BOUNDARY'S TRUST LIST
 * Batch 2 added one entry to DrawResultIngestionService::INTERNAL_SOURCES. The list is a
 * trust registry, so the test pins the EXACT original entries, in order, and allows
 * exactly one addition: widening trust further would need a decision, not a drive-by.
 */
final class PreExistingPathsIndependenceTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function gameSources(): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'))) as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match('/(Glo|Pcso)/', $file->getFilename()) === 1) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function test_the_glo_and_pcso_sources_are_found(): void
    {
        $this->assertNotEmpty($this->gameSources(), 'no GLO/PCSO source was discovered, so the check below proves nothing');
    }

    public function test_no_glo_or_pcso_file_references_the_digit_lane(): void
    {
        $needles = [
            'App\\Services\\LotteryGames',
            'App\\Models\\GameBetAttribution',
            'App\\Models\\GameNumberExposure',
            'App\\Models\\GameResultImport',
            'App\\Models\\GameAuditEvent',
            'App\\Models\\LotteryGame',
            'lottery_games',
            'BetFundingSource',
        ];

        $violations = [];

        foreach ($this->gameSources() as $path) {
            $source = (string) file_get_contents($path);

            foreach ($needles as $needle) {
                if (str_contains($source, $needle)) {
                    $violations[] = str_replace(base_path().'/', '', $path).' → '.$needle;
                }
            }
        }

        $this->assertSame([], $violations, 'GLO/PCSO code must not depend on anything Batch 2 added');
    }

    public function test_the_result_boundary_trust_list_gained_exactly_one_entry(): void
    {
        $original = [
            'internal',
            'manual',
            'operator',
            'operator-cli',
            'api-operator',
            'admin',
            'admin-panel',
            'admin_entry',
        ];

        $this->assertSame(
            [...$original, 'lottery_games'],
            DrawResultIngestionService::INTERNAL_SOURCES,
            'every pre-existing trusted source is still there, unchanged and in order; Batch 2 added only its own lane',
        );
    }
}
