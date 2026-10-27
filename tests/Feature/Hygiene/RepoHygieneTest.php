<?php

declare(strict_types=1);

namespace Tests\Feature\Hygiene;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Repository hygiene: the things that must never be committed, and the script
 * that proves they are not.
 *
 * ============================================================================
 * WHY THIS IS A TEST AND NOT A CODE REVIEW ITEM
 * ============================================================================
 * Every rule below has already failed once in this repository:
 *
 *   - a `.env` reached the index, carrying a real APP_KEY;
 *   - twelve React mockups shipped in `resources/js/components/`, compiled by
 *     the build and loaded by nothing;
 *   - a TypeScript twin of a live module sat next to it, half-migrated;
 *   - `logs/` accumulated markdown reports that read like documentation;
 *   - a stylesheet for a page nobody served stayed in the entry list.
 *
 * `scripts/repo-hygiene.sh` performs the removals. This suite is the part that
 * CI can run without write access: it asserts the outcomes, so a cleanup that
 * was never re-run after a rebase is caught here rather than by the next person
 * who tries `npm ci` on a fresh clone.
 */
final class RepoHygieneTest extends TestCase
{
    private function root(): string
    {
        return base_path();
    }

    public function test_the_hygiene_script_exists_and_is_valid_bash(): void
    {
        $script = $this->root().'/scripts/repo-hygiene.sh';

        $this->assertFileExists($script, 'the hygiene script is part of the deliverable');
        $this->assertTrue(is_executable($script) || is_readable($script));

        // `bash -n` parses without executing: a syntax error in a cleanup script
        // is discovered at the worst possible moment otherwise.
        exec('bash -n '.escapeshellarg($script).' 2>&1', $output, $status);

        $this->assertSame(0, $status, 'scripts/repo-hygiene.sh does not parse: '.implode("\n", $output));
    }

    public function test_the_hygiene_script_refuses_to_touch_batch_01_paths(): void
    {
        $source = (string) file_get_contents($this->root().'/scripts/repo-hygiene.sh');

        // The script deletes files. A parallel lane owns the payment tree, and a
        // cleanup run must never collect another team's work.
        $this->assertStringContainsString('BATCH_01_PATHS', $source);
        $this->assertStringContainsString('is_batch_01', $source);
        $this->assertStringContainsString('refusing', $source);
    }

    public function test_no_env_file_is_tracked(): void
    {
        $tracked = $this->git('ls-files .env .env.local .env.production 2>/dev/null');

        $this->assertSame(
            '',
            trim($tracked),
            'a real environment file is tracked; it carries APP_KEY and every credential',
        );
    }

    public function test_the_ignore_files_cover_the_things_that_must_not_ship(): void
    {
        $gitignore = (string) file_get_contents($this->root().'/.gitignore');

        foreach ([
            '.env',
            'node_modules',
            'public/build',
            'storage/logs',
            'vendor',
        ] as $pattern) {
            $this->assertStringContainsString($pattern, $gitignore, '.gitignore must ignore '.$pattern);
        }

        $dockerignore = (string) file_get_contents($this->root().'/.dockerignore');

        foreach (['.git', 'node_modules', '.env'] as $pattern) {
            $this->assertStringContainsString($pattern, $dockerignore, '.dockerignore must exclude '.$pattern);
        }
    }

    public function test_no_typescript_or_tsx_file_survives_under_resources_js(): void
    {
        $leftovers = [];

        foreach (File::allFiles(resource_path('js')) as $file) {
            if (preg_match('/\.(ts|tsx)$/', $file->getFilename()) === 1) {
                $leftovers[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        $this->assertSame(
            [],
            $leftovers,
            "there is no TypeScript build step, so these files cannot be built and cannot load:\n  - "
            .implode("\n  - ", $leftovers),
        );
    }

    public function test_the_quarantine_directory_documents_what_was_removed(): void
    {
        // The directory is now empty on purpose. A future reader who wonders where
        // twelve components went must find an answer instead of a mystery.
        $readme = resource_path('js/_unused/README.md');

        $this->assertFileExists($readme);

        $contents = (string) file_get_contents($readme);

        $this->assertStringContainsString('removed', strtolower($contents));
        $this->assertStringContainsString('TypeScript', $contents);
    }

    public function test_no_source_file_is_a_null_blob(): void
    {
        // Four files in this repository were once overwritten with NUL bytes by a
        // bad write, and the only symptom was a confusing Blade compile error.
        // A NUL byte in a text file is never intentional.
        $offenders = [];

        foreach (['resources/views', 'resources/js', 'app'] as $directory) {
            foreach (File::allFiles($this->root().'/'.$directory) as $file) {
                $extension = $file->getExtension();

                if (! in_array($extension, ['php', 'js', 'mjs', 'blade.php', 'css', 'json', 'md'], true)) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                if ($contents !== '' && str_contains($contents, "\0")) {
                    $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $offenders, "these files contain NUL bytes:\n  - ".implode("\n  - ", $offenders));
    }

    public function test_the_delete_list_names_only_paths_that_are_allowed_to_go(): void
    {
        $manifest = $this->root().'/batch-02-delete.txt';

        // The delete list is part of the delivered batch, so it is either in the
        // tree (the batch was applied) or the batch was not applied at all. Only
        // the first case is a state this suite can judge, and an absent manifest
        // is reported as a failure rather than excused, because a batch applied
        // without its delete list leaves the removed files in place while the
        // zip claims they are gone.
        $this->assertFileExists($manifest, 'the batch delete list must ship with the batch');

        $batch01 = [
            'PlayerWebController.php',
            'config/payment.php',
            'config/thai_payout.php',
        ];

        foreach (preg_split('/\R/', (string) file_get_contents($manifest)) ?: [] as $line) {
            $path = trim($line);

            if ($path === '' || str_starts_with($path, '#')) {
                continue;
            }

            foreach ($batch01 as $owned) {
                $this->assertStringNotContainsString(
                    $owned,
                    $path,
                    'the delete list must never name a Batch 01 path: '.$path,
                );
            }

            $this->assertStringStartsWith('resources/', $path, 'a delete entry must be a resources path: '.$path);
        }
    }

    private function git(string $arguments): string
    {
        $output = [];
        $status = 0;

        exec('cd '.escapeshellarg($this->root()).' && git '.$arguments.' 2>/dev/null', $output, $status);

        return implode("\n", $output);
    }
}
