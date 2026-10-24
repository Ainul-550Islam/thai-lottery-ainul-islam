<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use Tests\TestCase;

/**
 * Vite asset wiring contract.
 *
 * WHY THIS TEST EXISTS
 * Ten stylesheets and scripts were referenced from Blade with `@vite(...)` but
 * were never declared in vite.config.js: tailwind-utility.css, wallet-conversion.css,
 * pages/blog.css, pages/rewards.css, contact-portal.js, lotto-discount-portal.js,
 * wallet-conversion.js, pages/blog.js, pages/rewards.js and chat/operator.js.
 *
 * The failure mode is quiet in development and loud in production. In dev,
 * @vite() asks the Vite dev server for a file it was never told to serve. After
 * `npm run build`, the manifest has no record for the path, so Laravel throws
 * "Unable to locate file in Vite manifest" and the whole page is a 500 - the
 * contact page, the blog, the rewards page, the wallet conversion screens and
 * the chat operator console all rendered nothing.
 *
 * A registered entry that no longer exists is the mirror-image defect: the build
 * fails outright. Both directions are asserted here, plus the manifest, so a
 * typo in either file can never ship silently again.
 */
final class ViteAssetContractTest extends TestCase
{
    /**
     * Every `resources/...` literal handed to @vite() anywhere under
     * resources/views, deduplicated, keyed by the asset path.
     *
     * @return array<string, list<string>> asset => files that reference it
     */
    private function bladeAssetReferences(): array
    {
        $references = [];

        /** @var array<int, string> $files */
        $files = array_merge(
            glob(resource_path('views/*.blade.php')) ?: [],
            glob(resource_path('views/*/*.blade.php')) ?: [],
            glob(resource_path('views/*/*/*.blade.php')) ?: [],
            glob(resource_path('views/*/*/*/*.blade.php')) ?: [],
        );

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            // @vite([...]) or @vite('...') - the argument always ends at the
            // matching close of the call, so scanning to the last quote on the
            // directive's line pair is not needed: quoted literals are enough.
            if (preg_match_all('/@vite\s*\((.*?)\)/s', $source, $calls) === false) {
                continue;
            }

            foreach ($calls[1] as $call) {
                if (preg_match_all("/(['\"])(resources\/[^'\"]+)\\1/", $call, $matches) === false) {
                    continue;
                }

                foreach ($matches[2] as $asset) {
                    $references[$asset][] = str_replace(base_path().'/', '', $file);
                }
            }
        }

        return $references;
    }

    /**
     * The inputs declared in vite.config.js.
     *
     * @return list<string>
     */
    private function registeredInputs(): array
    {
        $config = (string) file_get_contents(base_path('vite.config.js'));

        preg_match_all(
            "/(['\"])(resources\/[^'\"]+\\.(?:js|ts|tsx|css))\\1/",
            $config,
            $matches,
        );

        return array_values(array_unique($matches[2]));
    }

    public function test_every_asset_referenced_by_a_view_exists_on_disk(): void
    {
        $references = $this->bladeAssetReferences();

        $this->assertGreaterThan(40, count($references), 'The scan found almost no @vite references; it is not looking at the views.');

        $missing = [];
        foreach ($references as $asset => $referencingFiles) {
            if (! is_file(base_path($asset))) {
                $missing[] = $asset.' (referenced by '.implode(', ', array_unique($referencingFiles)).')';
            }
        }

        $this->assertSame([], $missing, "Blade references assets that do not exist:\n".implode("\n", $missing));
    }

    public function test_every_asset_referenced_by_a_view_is_registered_in_vite_config(): void
    {
        $references = $this->bladeAssetReferences();
        $registered = $this->registeredInputs();

        $unregistered = [];
        foreach (array_keys($references) as $asset) {
            if (! in_array($asset, $registered, true)) {
                $unregistered[] = $asset.' (referenced by '.implode(', ', array_unique($references[$asset])).')';
            }
        }

        $this->assertSame(
            [],
            $unregistered,
            "Blade references assets that vite.config.js never declares; @vite() cannot resolve them:\n"
            .implode("\n", $unregistered),
        );
    }

    public function test_every_registered_input_exists_on_disk(): void
    {
        $registered = $this->registeredInputs();

        $this->assertGreaterThan(50, count($registered), 'vite.config.js declares almost nothing; the scan is wrong.');

        $missing = array_values(array_filter(
            $registered,
            static fn (string $asset): bool => ! is_file(base_path($asset)),
        ));

        $this->assertSame([], $missing, "vite.config.js declares entries that do not exist:\n".implode("\n", $missing));
    }

    public function test_every_asset_referenced_by_a_view_is_present_in_the_built_manifest(): void
    {
        $manifestPath = public_path('build/manifest.json');

        $this->assertFileExists($manifestPath, 'The built Vite manifest is missing; run "npm run build".');

        /** @var array<string, array{file?: string}> $manifest */
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        $missing = [];
        foreach (array_keys($this->bladeAssetReferences()) as $asset) {
            if (! array_key_exists($asset, $manifest)) {
                $missing[] = $asset;
                continue;
            }

            $built = (string) ($manifest[$asset]['file'] ?? '');
            if ($built === '' || ! is_file(public_path('build/'.$built))) {
                $missing[] = $asset.' (manifest entry has no built file)';
            }
        }

        $this->assertSame(
            [],
            $missing,
            "These assets are referenced by Blade but absent from the built manifest:\n".implode("\n", $missing),
        );
    }
}
