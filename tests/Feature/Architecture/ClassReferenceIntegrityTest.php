<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Permanent guard against the "class does not exist" class of defect.
 *
 * A renamed, deleted or never-created class that is still imported or passed
 * to ::class is invisible to a grep and only fails at runtime on the unlucky
 * path that touches it. The canonical case was FinancialComplianceGateService,
 * which shipped referenced by payout code long after the class itself was
 * gone. This guard walks every PHP file the application ships and resolves
 * every App\\ reference found in real code tokens (use statements, inline
 * qualified names, ::class and new) to its PSR-4 file, so a dangling
 * reference fails the suite instead of a production request.
 *
 * The check is filesystem based rather than autoload based: class_exists()
 * would execute the referenced file, turning a static integrity scan into an
 * arbitrary code loader and producing warnings for classes whose dependencies
 * are not bootstrapped in the test process.
 *
 * Namespace-prefix imports may resolve to a real directory. A deliberately
 * registered compatibility alias may also resolve, but only when it is already
 * loaded without autoloading and reflection points to the canonical App class's
 * actual PSR-4 file. Any other reference backed by neither a file nor a
 * directory fails.
 */
final class ClassReferenceIntegrityTest extends TestCase
{
    /**
     * The roots whose PHP tokens are scanned. Each is read from the repository
     * root the same way the application ships it.
     *
     * @var list<string>
     */
    private const SCAN_ROOTS = ['app', 'routes', 'config', 'database', 'tests'];

    public function test_every_app_class_reference_resolves_to_a_real_file(): void
    {
        $offenders = [];
        $scannedFiles = 0;

        foreach (self::SCAN_ROOTS as $root) {
            foreach ($this->phpFilesUnder(base_path($root)) as $path) {
                $scannedFiles++;
                $relative = $this->relative($path);

                foreach (array_unique($this->appReferencesIn($path)) as $reference) {
                    if (! $this->referenceResolves($reference)) {
                        $offenders[$relative][] = $reference;
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $scannedFiles, 'The integrity scan found no PHP files; the guard would be vacuous.');

        if ($offenders !== []) {
            $lines = ['Dangling App\\ class references (no PSR-4 class file or namespace directory exists):'];

            foreach ($offenders as $file => $references) {
                foreach ($references as $reference) {
                    $lines[] = sprintf('  %s  ->  %s  (looked for %s and %s/)', $reference, $file, $this->expectedPath($reference), $this->expectedDirectory($reference));
                }
            }

            $this->fail(implode("\n", $lines));
        }

        // Reaching here means every reference resolved; assert explicitly so the
        // test still has a positive assertion when the tree is clean.
        $this->assertSame([], $offenders);
    }

    /**
     * Pull every App\\ namespace reference out of a file's real code tokens.
     * Comments, docblocks and string literals are not name tokens, so stale
     * mentions inside prose or error messages can never raise a false failure.
     *
     * @return list<string> normalized FQCNs without a leading backslash
     */
    private function appReferencesIn(string $path): array
    {
        $source = file_get_contents($path);

        if ($source === false || ! str_contains($source, 'App')) {
            return [];
        }

        $references = [];

        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError $e) {
            $this->fail(sprintf('Cannot scan %s for class references: %s', $this->relative($path), $e->getMessage()));
        }

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] !== T_NAME_QUALIFIED && $token[0] !== T_NAME_FULLY_QUALIFIED) {
                continue;
            }

            $name = ltrim($token[1], '\\');

            if (str_starts_with($name, 'App\\')) {
                $references[] = $name;
            }
        }

        return $references;
    }

    /** A real file/directory or an already-loaded alias to its canonical file. */
    private function referenceResolves(string $reference): bool
    {
        if (is_file($this->expectedPath($reference)) || is_dir($this->expectedDirectory($reference))) {
            return true;
        }

        // The false flag is important: do not autoload arbitrary references
        // while scanning source. Only a registered alias can resolve this way.
        if (! class_exists($reference, false)) {
            return false;
        }
        $reflection = new \ReflectionClass($reference);
        $canonical = $reflection->getName();
        $file = $reflection->getFileName();

        return strcasecmp($canonical, $reference) !== 0
            && str_starts_with($canonical, 'App\\')
            && is_string($file)
            && is_file($this->expectedPath($canonical))
            && realpath($file) === realpath($this->expectedPath($canonical));
    }

    public function test_a_loaded_compatibility_alias_resolves_to_its_real_canonical_file(): void
    {
        $legacy = 'App\\Services\\Withdrawal\\ManualDisbursementQueueService';
        self::assertTrue(class_exists($legacy, false));
        self::assertTrue($this->referenceResolves($legacy));
        self::assertSame(
            'App\\Services\\Payment\\ManualDisbursementQueueService',
            (new \ReflectionClass($legacy))->getName(),
        );
        self::assertFalse($this->referenceResolves('App\\Services\\Withdrawal\\DefinitelyMissingService'));
    }

    private function expectedPath(string $reference): string
    {
        // PSR-4: the App\\ namespace prefix maps to the lowercase app/ directory.
        return $this->expectedDirectory($reference).'.php';
    }

    private function expectedDirectory(string $reference): string
    {
        return base_path('app/'.substr($this->toRelativePath($reference), 4));
    }

    private function toRelativePath(string $reference): string
    {
        return str_replace('\\', '/', $reference);
    }

    private function relative(string $absolute): string
    {
        return ltrim(str_replace(base_path(), '', $absolute), '/');
    }

    /**
     * @return list<string>
     */
    private function phpFilesUnder(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];

        $walk = static function (string $current) use (&$walk, &$files): void {
            foreach (scandir($current) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $current.'/'.$entry;

                if (is_dir($path)) {
                    $walk($path);
                } elseif (is_file($path) && str_ends_with($path, '.php')) {
                    $files[] = $path;
                }
            }
        };

        $walk($dir);
        sort($files);

        return $files;
    }
}
