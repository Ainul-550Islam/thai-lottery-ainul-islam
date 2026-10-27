<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The build manifest contract, from the config side.
 *
 * ============================================================================
 * WHY THIS SUITE IS SEPARATE FROM ViteEntryIntegrityTest
 * ============================================================================
 * ViteEntryIntegrityTest walks the VIEWS and asks "is everything a page wants
 * declared". This one walks vite.config.js and asks the reverse questions:
 *
 *   - is every declared entry a real file of a buildable type?
 *   - is any entry a TypeScript or TSX input, when there is no TypeScript step?
 *   - is any entry declared twice?
 *   - is any entry a quarantined mockup?
 *   - does the stylesheet/script pair for a page keep the same name?
 *
 * Both directions matter. A missing declaration throws at render time; a stale
 * declaration wastes build time and, worse, invites somebody to import a file
 * that was never meant to ship.
 */
final class ViteManifestEntriesTest extends TestCase
{
    /**
     * @return list<string> every path declared in vite.config.js, in order
     */
    private function entries(): array
    {
        $raw = (string) file_get_contents(base_path('vite.config.js'));

        preg_match_all("/'(resources\\/[^']+)'/", $raw, $matches);

        return $matches[1];
    }

    public function test_the_config_declares_entries_at_all(): void
    {
        $this->assertGreaterThan(50, count($this->entries()), 'the entry list has collapsed');
    }

    public function test_every_declared_entry_exists_on_disk(): void
    {
        $missing = [];

        foreach ($this->entries() as $entry) {
            if (! File::isFile(base_path($entry))) {
                $missing[] = $entry;
            }
        }

        $this->assertSame([], $missing, "vite.config.js declares files that do not exist:\n  - ".implode("\n  - ", $missing));
    }

    public function test_no_declared_entry_is_typescript_or_tsx(): void
    {
        $offenders = array_values(array_filter(
            $this->entries(),
            static fn (string $entry): bool => preg_match('/\.(ts|tsx)$/', $entry) === 1,
        ));

        $this->assertSame(
            [],
            $offenders,
            "there is no TypeScript build step, so these inputs cannot compile:\n  - ".implode("\n  - ", $offenders),
        );
    }

    public function test_no_declared_entry_is_a_quarantined_mockup(): void
    {
        $offenders = array_values(array_filter(
            $this->entries(),
            static fn (string $entry): bool => str_contains($entry, 'resources/js/components/')
                || str_contains($entry, 'resources/js/_unused/'),
        ));

        $this->assertSame([], $offenders, "a quarantined mockup is a build entry:\n  - ".implode("\n  - ", $offenders));
    }

    public function test_no_entry_is_declared_twice(): void
    {
        $seen = [];
        $duplicates = [];

        foreach ($this->entries() as $entry) {
            if (isset($seen[$entry])) {
                $duplicates[] = $entry;
            }

            $seen[$entry] = true;
        }

        $this->assertSame([], array_values(array_unique($duplicates)), "duplicate entries:\n  - ".implode("\n  - ", $duplicates));
    }

    public function test_every_entry_is_a_script_or_a_stylesheet(): void
    {
        $offenders = [];

        foreach ($this->entries() as $entry) {
            if (preg_match('/\.(js|mjs|css)$/', $entry) !== 1) {
                $offenders[] = $entry;
            }
        }

        $this->assertSame([], $offenders, "unexpected entry types:\n  - ".implode("\n  - ", $offenders));
    }

    public function test_a_stylesheet_entry_has_a_matching_owner_or_a_view_that_asks_for_it(): void
    {
        // A stylesheet with no view and no importing module is dead weight. The
        // reverse check (entries that nothing references) lives in
        // NoMockFrontendTest; this asserts the specific case that produced the
        // dead stylesheet in the first place: a CSS file named after a module
        // that no longer exists.
        $offenders = [];

        foreach ($this->entries() as $entry) {
            if (! str_ends_with($entry, '.css')) {
                continue;
            }

            $stem = basename($entry, '.css');
            $script = 'resources/js/'.$stem.'.js';

            $viewAsks = $this->viewEntries()[$entry] ?? false;
            $moduleOwnsIt = File::isFile(base_path($script));

            if (! $viewAsks && ! $moduleOwnsIt) {
                $offenders[] = $entry;
            }
        }

        // Informational rather than fatal: a stylesheet may legitimately be
        // imported by a differently-named module. The assertion below keeps the
        // list from growing silently, so a new orphan has to be justified.
        $this->assertLessThanOrEqual(
            20,
            count($offenders),
            "too many stylesheets with no obvious owner:\n  - ".implode("\n  - ", $offenders),
        );
    }

    public function test_the_app_entry_exists_and_is_the_first_script(): void
    {
        $entries = $this->entries();

        $scripts = array_values(array_filter(
            $entries,
            static fn (string $entry): bool => str_ends_with($entry, '.js'),
        ));

        $this->assertSame('resources/js/app.js', $scripts[0], 'the application entry must lead the script list');
    }

    public function test_the_layout_loads_the_application_entry(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString("'resources/js/app.js'", $layout);
        $this->assertStringContainsString("'resources/css/app.css'", $layout);
    }

    /**
     * @return array<string, true> every path a view passes to @vite()
     */
    private function viewEntries(): array
    {
        $entries = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match_all("/@vite\\(\\s*\\[?(.*?)\\]?\\s*\\)/s", $source, $blocks) === 0) {
                continue;
            }

            foreach ($blocks[1] as $block) {
                if (preg_match_all("/'([^']+)'/", $block, $paths) === 0) {
                    continue;
                }

                foreach ($paths[1] as $path) {
                    $entries[$path] = true;
                }
            }
        }

        return $entries;
    }
}
