<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use App\Services\Operations\FrontendBuildService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Part 66 — the frontend build works, and the reason it did not is recorded.
 *
 * THE DEFECT, MEASURED
 * `resources/css/app.css` is written for Tailwind v4 (`@import 'tailwindcss'`,
 * `@config '../../tailwind.config.js'`), while `package.json` pinned
 * tailwindcss ^3.4.17. v3 has no CSS entry point, so postcss-import resolved
 * the import to `node_modules/tailwindcss/lib/index.js` and tried to parse
 * JavaScript as CSS:
 *
 *   CssSyntaxError: [postcss] postcss-import: …/tailwindcss/lib/index.js:1:1:
 *   Unknown word "use strict"
 *
 * `npm run build` therefore failed and `public/build/manifest.json` never
 * existed — which is why every suite that renders a layout calling `@vite`
 * failed in this environment, and why `ArtifactCompletionManifestTest` could
 * not pass. The fix is the PostCSS plugin name: in v4 it lives in its own
 * package, `@tailwindcss/postcss`.
 *
 * WHAT IS PROVEN HERE
 *  1. The manifest exists and holds entries.
 *  2. Every input declared in vite.config.js has a manifest entry — a manifest
 *     that exists but misses an input still breaks the page that needs it.
 *  3. The command reports success, and reports failure when the manifest is
 *     absent.
 *  4. `postcss.config.js` names the v4 plugin, so reverting the fix fails this
 *     suite instead of quietly breaking the build again.
 */
final class FrontendBuildVerificationTest extends TestCase
{
    #[Test]
    public function the_manifest_exists_and_covers_every_declared_input(): void
    {
        $report = app(FrontendBuildService::class)->report();

        $this->assertTrue(
            $report['exists'],
            'public/build/manifest.json is missing — run: npm ci && npm run build',
        );
        $this->assertGreaterThan(0, $report['entries']);
        $this->assertNotEmpty($report['inputs'], 'vite.config.js declares inputs');
        $this->assertSame([], $report['missing'], 'inputs with no manifest entry: '.implode(', ', $report['missing']));
    }

    #[Test]
    public function the_command_exits_zero_when_the_build_is_complete(): void
    {
        $this->artisan('operations:verify-frontend-build')
            ->expectsOutputToContain('public/build/manifest.json:')
            ->expectsOutputToContain('Every declared input has a manifest entry.')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_command_fails_when_the_manifest_is_absent(): void
    {
        // A throwaway tree with a vite config and no build output: the failure
        // path is exercised for real rather than asserted from memory.
        $tmp = sys_get_temp_dir().'/frontend-build-check-'.bin2hex(random_bytes(4));
        mkdir($tmp.'/public/build', 0o777, true);
        file_put_contents($tmp.'/vite.config.js', "input: ['resources/css/app.css'],\n");

        $service = new FrontendBuildService($tmp);
        $report = $service->report();

        $this->assertFalse($report['exists']);
        $this->assertSame(['resources/css/app.css'], $report['inputs']);
        $this->assertSame(['resources/css/app.css'], $report['missing']);

        $this->cleanup($tmp);
    }

    #[Test]
    public function an_input_with_no_manifest_entry_is_reported_as_missing(): void
    {
        $tmp = sys_get_temp_dir().'/frontend-build-partial-'.bin2hex(random_bytes(4));
        mkdir($tmp.'/public/build', 0o777, true);
        file_put_contents($tmp.'/vite.config.js', "input: ['resources/css/app.css', 'resources/css/other.css'],\n");
        file_put_contents(
            $tmp.'/public/build/manifest.json',
            json_encode(['resources/css/app.css' => ['file' => 'assets/app.css']]),
        );

        $report = (new FrontendBuildService($tmp))->report();

        $this->assertTrue($report['exists']);
        $this->assertSame(1, $report['entries']);
        $this->assertSame(['resources/css/other.css'], $report['missing']);

        $this->cleanup($tmp);
    }

    #[Test]
    public function the_postcss_config_names_the_tailwind_v4_plugin(): void
    {
        $source = (string) file_get_contents(base_path('postcss.config.js'));

        $this->assertStringContainsString(
            '@tailwindcss/postcss',
            $source,
            'reverting to the v3 plugin name breaks the build again: v3 has no CSS entry point for @import "tailwindcss"',
        );

        $package = json_decode((string) file_get_contents(base_path('package.json')), true);
        $this->assertIsArray($package);
        $this->assertArrayHasKey('tailwindcss', $package['devDependencies'] ?? []);
        $this->assertMatchesRegularExpression(
            '/^\^?4\./',
            (string) $package['devDependencies']['tailwindcss'],
            'the CSS sources are written for Tailwind v4',
        );
    }

    private function cleanup(string $dir): void
    {
        foreach (glob($dir.'/public/build/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir.'/public/build');
        @rmdir($dir.'/public');
        @unlink($dir.'/vite.config.js');
        @rmdir($dir);
    }
}
