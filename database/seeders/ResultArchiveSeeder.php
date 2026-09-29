<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * OPTIONAL historical result archive seeding (audit finding 7).
 *
 * `php artisan migrate --seed` on a fresh deployment produces a working
 * system with an EMPTY result history. That is deliberate: the four import
 * commands are the supported way to load real draws, and this project does
 * not invent lottery results — a fabricated draw history would be worse
 * than an empty one.
 *
 * This seeder is the wiring between the two. Drop per-draw JSON payload
 * files (the exact payload shape each import command validates) into:
 *
 *     database/seeders/data/results/national/*.json
 *     database/seeders/data/results/weekly/*.json
 *     database/seeders/data/results/bingo/*.json
 *     database/seeders/data/results/pcso/*.json
 *
 * and `migrate --seed` will replay every one of them through the SAME
 * import command an operator would run by hand — same validation, same
 * conflict detection, same audit trail, no parallel code path. The README
 * in the data directory documents the payload shape per lane and where to
 * export a history from.
 *
 * With no files present the seeder is an explicit no-op: an empty archive
 * is the honest state of a fresh install, not a seeding failure.
 */
class ResultArchiveSeeder extends Seeder
{
    /**
     * Lane directory => import command. One payload file = one draw.
     *
     * Files are replayed in filename order so a directory sorted by draw
     * date replays the archive chronologically.
     */
    private const LANES = [
        'national' => 'national-lottery:import',
        'weekly' => 'weekly-lottery:import',
        'bingo' => 'bingo-lottery:import',
        'pcso' => 'pcso-lottery:import',
    ];

    public function run(): void
    {
        $base = database_path('seeders/data/results');
        $imported = 0;
        $rejected = 0;

        foreach (self::LANES as $lane => $command) {
            $directory = $base.'/'.$lane;

            if (! is_dir($directory)) {
                continue;
            }

            $files = glob($directory.'/*.json') ?: [];
            sort($files);

            foreach ($files as $file) {
                $exit = Artisan::call($command, ['--file' => $file]);

                if ($exit !== 0) {
                    // A payload the importer refuses is a data problem the
                    // operator must see in the seeding output, not something
                    // to swallow or skip silently.
                    $this->command?->warn(sprintf(
                        '[%s] %s rejected (exit %d): %s',
                        $lane,
                        basename($file),
                        $exit,
                        trim((string) Artisan::output())
                    ));
                    $rejected++;

                    continue;
                }

                $imported++;
            }
        }

        if ($imported === 0 && $rejected === 0) {
            $this->command?->info(
                'ResultArchiveSeeder: no payload files under database/seeders/data/results — '
                .'result history stays empty until the import commands are run.'
            );

            return;
        }

        $this->command?->info(sprintf(
            'ResultArchiveSeeder: replayed %d draw payload(s) through the import commands (%d rejected).',
            $imported,
            $rejected
        ));
    }
}
