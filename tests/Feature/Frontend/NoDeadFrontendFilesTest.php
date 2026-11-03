<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * No dead frontend files.
 *
 * WHY THIS TEST EXISTS
 * resources/js accumulates three kinds of corpse: TypeScript/TSX duplicates
 * that no Vite entry imports, plain .js files that neither an entry nor a view
 * consumes, and build inputs that point at files which no longer exist. Each
 * one makes `npm run build` slower or fail outright and lets a reviewer think
 * a page is wired when it is not. This gate walks the real graph from
 * vite.config.js and proves that every shipped JS file is reachable.
 *
 * The Batch 08 deletions live in DELETE-LIST.txt (a ZIP cannot delete files);
 * run apply-batch-08.sh once after unzipping. Until then this test correctly
 * fails with a message naming the script rather than silently passing.
 */
final class NoDeadFrontendFilesTest extends TestCase
{
    /** @return list<string> repo-relative paths of every JS file under resources/js */
    private function javascriptFiles(): array
    {
        $root = resource_path('js');
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }
            if (! str_ends_with($file->getFilename(), '.js')) {
                continue;
            }
            $files[] = ltrim(str_replace(str_replace(DIRECTORY_SEPARATOR, '/', base_path()), '', str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname())), '/');
        }

        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function registeredInputs(): array
    {
        $config = (string) file_get_contents(base_path('vite.config.js'));
        preg_match_all("/(['\"])(resources\/js\/[^'\"]+\.js)\\1/", $config, $matches);

        return array_values(array_unique($matches[2]));
    }

    /** @return list<string> local module paths one source file imports */
    private function importsOf(string $file): array
    {
        $source = (string) file_get_contents(base_path($file));
        preg_match_all('/(?:from|import)\s*\(?\s*[\'"](\.[^\'"]+)[\'"]/', $source, $matches);

        $imports = [];
        foreach ($matches[1] as $specifier) {
            $candidate = str_replace('\\', '/', dirname($file).'/'.$specifier);
            $parts = [];
            foreach (explode('/', $candidate) as $part) {
                if ($part === '.' || $part === '') {
                    continue;
                }
                if ($part === '..') {
                    array_pop($parts);
                    continue;
                }
                $parts[] = $part;
            }
            $base = implode('/', $parts);
            foreach ([$base, $base.'.js', $base.'/index.js'] as $resolved) {
                if (is_file(base_path($resolved))) {
                    $imports[] = $resolved;
                    break;
                }
            }
        }

        return array_values(array_unique($imports));
    }

    /** @return list<string> */
    private function referencedByViewsOrTests(array $files): array
    {
        $corpus = '';
        foreach (['resources/views', 'tests', 'scripts', 'playwright.config.js', 'vite.config.js'] as $relative) {
            $path = base_path($relative);
            if (is_dir($path)) {
                foreach (File::allFiles($path) as $file) {
                    $corpus .= (string) File::get($file->getPathname());
                }
            } elseif (is_file($path)) {
                $corpus .= (string) file_get_contents($path);
            }
        }

        return array_values(array_filter(
            $files,
            static fn (string $file): bool => str_contains($corpus, $file)
                || str_contains($corpus, basename($file)),
        ));
    }

    public function test_no_typescript_or_react_duplicates_remain(): void
    {
        $root = resource_path('js');
        $leftovers = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && preg_match('/\.(ts|tsx)$/', $file->getFilename()) === 1) {
                $leftovers[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        $this->assertSame(
            [],
            $leftovers,
            "TypeScript/TSX duplicates are still present. Run apply-batch-08.sh (DELETE-LIST.txt) after unzipping the ZIP:\n".implode("\n", $leftovers),
        );
    }

    public function test_every_registered_vite_input_exists_and_no_typescript_input_is_registered(): void
    {
        $config = (string) file_get_contents(base_path('vite.config.js'));

        foreach ($this->registeredInputs() as $input) {
            $this->assertFileExists(base_path($input), $input.' is registered but missing on disk.');
        }

        $this->assertGreaterThan(50, count($this->registeredInputs()), 'The Vite input scan found almost nothing.');
        $this->assertDoesNotMatchRegularExpression(
            '/resources\/[^\'"]+\.(?:ts|tsx)[\'"]/',
            $config,
            'vite.config.js still registers a TypeScript/TSX input.',
        );
    }

    public function test_every_javascript_file_is_an_entry_an_import_or_a_reference(): void
    {
        $files = $this->javascriptFiles();
        $this->assertGreaterThan(40, count($files), 'The resources/js scan found almost nothing.');

        $entries = $this->registeredInputs();
        $reachable = [];
        $queue = $entries;

        while ($queue !== []) {
            $current = (string) array_pop($queue);
            if (isset($reachable[$current]) || ! is_file(base_path($current))) {
                continue;
            }
            $reachable[$current] = true;
            foreach ($this->importsOf($current) as $import) {
                $queue[] = $import;
            }
        }

        $referenced = $this->referencedByViewsOrTests($files);
        $dead = array_values(array_diff($files, array_keys($reachable), $referenced));
        sort($dead);

        $this->assertSame(
            [],
            $dead,
            "These JavaScript files are neither a Vite entry, imported by an entry, nor referenced by a view/test:\n".implode("\n", $dead),
        );
    }

    public function test_the_bundle_has_no_unused_react_dependencies(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('package.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);

        foreach (['react', 'react-dom', '@vitejs/plugin-react'] as $package) {
            $this->assertArrayNotHasKey($package, (array) ($manifest['dependencies'] ?? []), $package.' is unused in dependencies.');
            $this->assertArrayNotHasKey($package, (array) ($manifest['devDependencies'] ?? []), $package.' is unused in devDependencies.');
        }


        $lock = json_decode((string) file_get_contents(base_path('package-lock.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('node_modules/react', (array) ($lock['packages'] ?? []));
        $this->assertArrayNotHasKey('node_modules/react-dom', (array) ($lock['packages'] ?? []));
        $this->assertArrayNotHasKey('node_modules/@vitejs/plugin-react', (array) ($lock['packages'] ?? []));

        // The deposit QR encoder is vendored at resources/js/vendor/qr-encoder.js
        // and is validated against an independent decoder by tests/js/qr-encoder.test.mjs,
        // so no npm QR package is locked — and none may be re-added by accident.
        $this->assertArrayNotHasKey('node_modules/qrcode', (array) ($lock['packages'] ?? []), 'The QR encoder is vendored, not an npm package.');
        $this->assertFileExists(base_path('resources/js/vendor/qr-encoder.js'), 'The vendored QR encoder must ship with the bundle.');

        // The browser suite this batch ships needs @playwright/test; it must
        // stay locked so `npm run e2e:player-limits` cannot drift.
        $this->assertArrayHasKey('node_modules/@playwright/test', (array) ($lock['packages'] ?? []), 'The e2e runner must be locked.');
    }

    /**
     * Every bare import specifier in a shipped module must be a declared
     * package.
     *
     * This is the regression that once broke the whole deposit page: a module
     * imported `qrcode`, which no manifest declared, so Vite could not build
     * the chunk and the page shipped no JavaScript at all. A missing dependency
     * must fail here, in PHP, instead of failing silently in a browser.
     *
     * Commented-out imports are not matched: the pattern anchors on a line that
     * starts with `import`, so the prose in deposit-portal.js that explains the
     * old `qrcode` import is not mistaken for a live one.
     */
    public function test_every_bare_import_resolves_to_a_declared_dependency(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('package.json')), true, 512, JSON_THROW_ON_ERROR);
        $declared = array_values(array_unique(array_merge(
            array_keys((array) ($manifest['dependencies'] ?? [])),
            array_keys((array) ($manifest['devDependencies'] ?? [])),
        )));

        $specifiers = [];

        foreach ($this->javascriptFiles() as $file) {
            $source = (string) file_get_contents(base_path($file));
            preg_match_all('/^\s*import\s+(?:[^;\n]*?\s+from\s+)?[\'"]([^\'"]+)[\'"]/m', $source, $matches);

            foreach ($matches[1] as $specifier) {
                $specifier = trim((string) $specifier);
                if ($specifier === '' || str_starts_with($specifier, '.') || str_starts_with($specifier, '/')) {
                    continue;
                }

                // '@scope/pkg/deep/path' resolves to the package '@scope/pkg'.
                $parts = explode('/', $specifier);
                $package = str_starts_with($specifier, '@') ? implode('/', array_slice($parts, 0, 2)) : $parts[0];
                $specifiers[$package] = $file;
            }
        }

        $this->assertNotSame([], $specifiers, 'The import scan found no bare specifiers at all.');

        foreach ($specifiers as $package => $file) {
            $this->assertContains(
                (string) $package,
                $declared,
                $file.' imports "'.$package.'", which package.json does not declare. A build would fail or silently drop the chunk.',
            );
            $this->assertArrayNotHasKey(
                'node_modules/qrcode',
                (array) ($lock['packages'] ?? []),
                'The QR encoder is vendored; nothing may import the qrcode package again.',
            );
        }
    }

    public function test_every_batch_08_module_is_imported_by_the_app_entry(): void
    {
        $app = (string) file_get_contents(base_path('resources/js/app.js'));

        foreach ([
            './echo',
            './notifications.js',
            './deposit-portal.js',
            './truemoney-redeem.js',
            './slip-upload.js',
            './how-to-play-portal.js',
            './player-settings.js',
            './legal-downloads.js',
        ] as $module) {
            $this->assertStringContainsString($module, $app, 'app.js must load '.$module.' on the pages that need it.');
        }

        $this->assertStringContainsString('document.querySelector(selector)', $app, 'Page modules must be guarded by their root element.');
        $this->assertStringContainsString('e2e:all', (string) file_get_contents(base_path('package.json')));
    }
}
