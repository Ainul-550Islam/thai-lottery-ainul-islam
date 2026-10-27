<?php

declare(strict_types=1);

/**
 * Environment template key-parity & credential hygiene verifier (Audit §F11).
 *
 * Verifies that `.env.example`, `.env.ci`, and `.env.production.example`:
 *   1. All exist and parse cleanly.
 *   2. Declare the exact same set of `KEY=` entries (including documented `# KEY=` lines)
 *      with zero undocumented config drift across environments.
 *   3. Never ship live credential values for any `*(TOKEN|SECRET|KEY|PASSWORD|SALT)` key
 *      in `.env.example` or `.env.production.example`.
 */
$root = dirname(__DIR__);

$templates = [
    '.env.example',
    '.env.ci',
    '.env.production.example',
];

/**
 * @return list<string>
 */
function parseTemplateKeys(string $filePath): array
{
    $lines = file($filePath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException("Unable to read {$filePath}");
    }

    $keys = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*(?:#\s*)?([A-Z][A-Z0-9_]*)=/', $line, $m) === 1) {
            $keys[$m[1]] = true;
        }
    }

    $result = array_keys($keys);
    sort($result);

    return $result;
}

$keySets = [];
$errors = [];

foreach ($templates as $relPath) {
    $fullPath = $root.DIRECTORY_SEPARATOR.$relPath;
    if (! is_file($fullPath)) {
        $errors[] = "Missing required environment template: {$relPath}";

        continue;
    }

    $keySets[$relPath] = parseTemplateKeys($fullPath);

    if (in_array($relPath, ['.env.example', '.env.production.example'], true)) {
        $raw = (string) file_get_contents($fullPath);
        if (preg_match_all('/^([A-Z0-9_]*(?:TOKEN|SECRET|KEY|PASSWORD|SALT))=(.+)$/m', $raw, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $errors[] = "{$relPath} ships a non-empty credential value for {$match[1]}";
            }
        }
    }
}

if (count($keySets) === count($templates)) {
    $canonical = $keySets['.env.example'];

    foreach (['.env.ci', '.env.production.example'] as $peer) {
        $missingInPeer = array_values(array_diff($canonical, $keySets[$peer]));
        $extraInPeer = array_values(array_diff($keySets[$peer], $canonical));

        if ($missingInPeer !== []) {
            $errors[] = "Keys in .env.example missing from {$peer}: ".implode(', ', $missingInPeer);
        }
        if ($extraInPeer !== []) {
            $errors[] = "Keys in {$peer} missing from .env.example: ".implode(', ', $extraInPeer);
        }
    }
}

$report = [
    'ok' => $errors === [],
    'templates' => array_map('count', $keySets),
    'errors' => $errors,
];

fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

exit($errors === [] ? 0 : 1);
