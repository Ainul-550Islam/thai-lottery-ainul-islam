<?php

declare(strict_types=1);

namespace Tests\Feature\Hygiene;

use Tests\TestCase;

/**
 * The build manifest and the views must agree.
 *
 * WHY THIS IS A TEST AND NOT A REVIEW CHECKLIST
 * ---------------------------------------------------------------------------
 * `@vite('resources/js/whatever.js')` in a Blade view throws a build exception
 * at RENDER time if the entry is missing from vite.config.js, and a page that
 * throws is discovered by whoever visits it first. This test discovers it in CI
 * instead, for every view at once, and it also enforces the reverse rule that
 * keeps the quarantined mockups out of the build.
 */
final class ViteEntryIntegrityTest extends TestCase
{
    /**
     * @return list<string> every path passed to @vite() anywhere in resources/views
     */
    private function viewEntries(): array
    {
        $entries = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views')),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match_all('/@vite\(\s*\[(.*?)\]\s*\)/s', $contents, $blocks) === 0) {
                continue;
            }

            foreach ($blocks[1] as $block) {
                if (preg_match_all("/'([^']+)'/", $block, $paths) === 0) {
                    continue;
                }

                foreach ($paths[1] as $path) {
                    $entries[] = $path;
                }
            }

            if (preg_match_all('/@vite\(\s*\'([^\']+)\'\s*\)/', $contents, $single) > 0) {
                foreach ($single[1] as $path) {
                    $entries[] = $path;
                }
            }
        }

        return array_values(array_unique($entries));
    }

    /**
     * @return list<string> every entry declared in vite.config.js
     */
    private function configEntries(): array
    {
        $raw = (string) file_get_contents(base_path('vite.config.js'));

        preg_match_all("/'(resources\/[^']+)'/", $raw, $matches);

        return array_values(array_unique($matches[1]));
    }

    public function test_every_entry_a_view_asks_for_is_declared_in_the_build(): void
    {
        $declared = $this->configEntries();
        $missing = [];

        foreach ($this->viewEntries() as $entry) {
            if (! in_array($entry, $declared, true)) {
                $missing[] = $entry;
            }

            $this->assertFileExists(base_path($entry), "view references a file that does not exist: {$entry}");
        }

        $this->assertSame([], $missing, "views reference entries missing from vite.config.js:\n  - ".implode("\n  - ", $missing));
    }

    public function test_no_quarantined_react_mockup_is_a_build_entry(): void
    {
        foreach ($this->configEntries() as $entry) {
            $this->assertStringNotContainsString(
                'resources/js/components/',
                $entry,
                'the quarantined .tsx mockups must not be build entries (see resources/js/_unused/README.md)',
            );
        }
    }

    public function test_the_quarantined_mockups_are_documented(): void
    {
        $this->assertFileExists(resource_path('js/_unused/README.md'));
    }

    /**
     * Modules that still contain an `alert()`/`prompt()` call.
     *
     * THIS LIST IS NOW EMPTY, AND EMPTY IS THE POINT
     * -----------------------------------------------------------------------
     * It used to name six modules whose only feedback for a real-money action
     * was a blocking dialog. Batch 02 rewrote or removed every one of them:
     *
     *   - player-settings.js, contact-portal.js and
     *     account-verification-portal.js now report through
     *     resources/js/ui/toast.js, ui/inline-prompt.js and inline field errors;
     *   - glo-results-checker.js was a superseded duplicate of prize-checker.js;
     *   - player-security-settings.js was a mock of the responsible-gaming
     *     controls and was deleted (see batch-02-delete.txt).
     *
     * The two assertions below still run: a module that is NOT listed and calls
     * alert() fails, and a listed module that no longer calls it fails too. With
     * the list empty the first rule applies to every build entry, which is the
     * strongest form of the check.
     *
     * @var list<string>
     */
    private const LEGACY_BLOCKING_DIALOGS = [];

    /**
     * Strip comments so a module that merely *documents* alert() is not accused
     * of calling it. Both comment forms are removed; string literals that
     * contain the word are left alone, which is why the pattern below requires a
     * call parenthesis.
     */
    private function stripComments(string $source): string
    {
        $withoutBlocks = (string) preg_replace('~/\*.*?\*/~s', '', $source);

        return (string) preg_replace('~(^|[^:])//[^\n]*~', '$1', $withoutBlocks);
    }

    public function test_no_new_build_entry_uses_a_blocking_alert_as_a_success_signal(): void
    {
        $offenders = [];
        $cleaned = [];

        foreach ($this->configEntries() as $entry) {
            if (! str_ends_with($entry, '.js')) {
                continue;
            }

            $path = base_path($entry);

            if (! is_file($path)) {
                continue;
            }

            $source = $this->stripComments((string) file_get_contents($path));
            $calls = preg_match('/(?<![.\w])(alert|prompt)\s*\(/', $source) === 1;
            $listed = in_array($entry, self::LEGACY_BLOCKING_DIALOGS, true);

            if ($calls && ! $listed) {
                $offenders[] = $entry;
            }

            if (! $calls && $listed) {
                $cleaned[] = $entry;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "these build entries call alert()/prompt() and are not on the legacy list; use the shared toast instead:\n  - "
            .implode("\n  - ", $offenders),
        );

        $this->assertSame(
            [],
            $cleaned,
            "these entries no longer call alert()/prompt() and should be removed from LEGACY_BLOCKING_DIALOGS:\n  - "
            .implode("\n  - ", $cleaned),
        );
    }
}
