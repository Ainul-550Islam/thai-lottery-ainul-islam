<?php

declare(strict_types=1);

namespace App\Services\Operations;

/**
 * Is the frontend actually built, and does the build cover every declared input?
 *
 * WHY THIS EXISTS
 * `public/build/manifest.json` is what `@vite` reads to turn
 * `resources/css/app.css` into a hashed asset URL. When it is missing, every
 * page whose layout calls `@vite` throws while rendering — which is not a
 * subtle degradation, it is a blank 500. For several parts of this audit that
 * file simply did not exist here, and the reason was never named: the build
 * itself failed.
 *
 * WHAT IT REPORTS
 *  - whether the manifest exists, and how many entries it holds;
 *  - every input declared in `vite.config.js`, and which of them have no
 *    manifest entry — an input with no entry is a page that will fail to render
 *    even though the manifest exists;
 *  - whether the newest source file is newer than the manifest, i.e. whether the
 *    build is stale.
 *
 * WHAT IT DOES NOT DO
 * It never runs the build and never writes anything. Deciding to rebuild is an
 * operator's call, and a checker that silently rebuilds hides the fact that the
 * deployed artifact was out of date.
 */
final class FrontendBuildService
{
    public function __construct(
        private readonly ?string $basePath = null,
    ) {}

    /**
     * @return array{manifest: string, exists: bool, entries: int, inputs: list<string>, missing: list<string>, stale: bool, newest_source: string|null}
     */
    public function report(): array
    {
        $base = $this->basePath ?? base_path();
        $manifestPath = $base.'/public/build/manifest.json';

        $inputs = $this->declaredInputs($base.'/vite.config.js');
        $entries = $this->manifestKeys($manifestPath);

        $missing = array_values(array_diff($inputs, $entries));
        $newest = $this->newestSource($base, $inputs);

        return [
            'manifest' => 'public/build/manifest.json',
            'exists' => $entries !== [] || is_file($manifestPath),
            'entries' => count($entries),
            'inputs' => $inputs,
            'missing' => $missing,
            'stale' => $entries !== [] && $newest !== null && is_file($manifestPath)
                && (int) filemtime($manifestPath) < (int) filemtime($base.'/'.$newest),
            'newest_source' => $newest,
        ];
    }

    /**
     * Every `resources/...` input declared in vite.config.js.
     *
     * @return list<string>
     */
    public function declaredInputs(string $configPath): array
    {
        if (! is_file($configPath)) {
            return [];
        }

        $source = (string) file_get_contents($configPath);

        if (preg_match_all("/['\"](resources\/[^'\"]+)['\"]/", $source, $matches) !== false) {
            return array_values(array_unique($matches[1]));
        }

        return [];
    }

    /**
     * @return list<string>
     */
    public function manifestKeys(string $manifestPath): array
    {
        if (! is_file($manifestPath)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($manifestPath), true);

        return is_array($decoded) ? array_map('strval', array_keys($decoded)) : [];
    }

    /**
     * The most recently modified declared input, or null when none exist.
     *
     * @param  list<string>  $inputs
     */
    private function newestSource(string $base, array $inputs): ?string
    {
        $newest = null;
        $newestTime = 0;

        foreach ($inputs as $input) {
            $path = $base.'/'.$input;

            if (! is_file($path)) {
                continue;
            }

            $time = (int) filemtime($path);

            if ($time > $newestTime) {
                $newestTime = $time;
                $newest = $input;
            }
        }

        return $newest;
    }
}
