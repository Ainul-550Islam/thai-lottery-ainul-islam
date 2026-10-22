<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Nine controllers shipped once without their class closing brace: my locale de-duplication pass deleted a
 * trailing method and took the brace with it, so those files could not be parsed at all. Nothing in the suite
 * noticed, because a controller is only compiled when a route reaches it - and by then the symptom is a 500
 * on a public page, which surfaced as 13 red names across five unrelated directories while the directory that
 * broke them stayed green.
 *
 * An earlier revision of this gate counted braces/brackets/parens over token_get_all() output and was dropped
 * rather than tuned: PHP emits the literal '[' of "[{$key}]" (app/Services/Operations/
 * AdminOperationService.php:333) as its own character token while the matching ']' is absorbed into the
 * surrounding string tokens, so hand-rolled balance arithmetic reports false positives on valid code. The
 * parser is the only authority on whether a file parses, so this asks the parser - one `php -l` per file,
 * ~25ms each, and no shell-template quoting to get wrong.
 */
final class PhpFileStructureTest extends TestCase
{
    private const ROOTS = ['app', 'routes', 'bootstrap', 'config', 'database'];

    /**
     * Guards against passing vacuously: if the tree is ever restructured so the scan matches almost nothing,
     * an empty offender list must not read as "clean".
     */
    private const MINIMUM_FILES_SCANNED = 300;

    #[Test]
    public function every_php_file_in_the_repository_compiles(): void
    {
        $root = dirname(__DIR__, 3);
        $php = escapeshellarg(\PHP_BINARY);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        $scanned = 0;
        $offenders = [];

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getRealPath(), strlen($root) + 1);

            if (! \in_array(explode('/', $relative)[0], self::ROOTS, true)) {
                continue;
            }

            $scanned++;

            $output = (string) shell_exec($php.' -l '.escapeshellarg($file->getRealPath()).' 2>&1');

            if (! str_contains($output, 'No syntax errors detected')) {
                $offenders[] = $relative.': '.trim($output);
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_FILES_SCANNED,
            $scanned,
            'this gate only means something if it actually looked at the tree; scanned '.$scanned.' file(s)',
        );

        $this->assertSame(
            [],
            $offenders,
            sprintf(
                "%d PHP file(s) do not compile - a truncated edit usually eats the class brace:\n%s",
                count($offenders),
                implode("\n", $offenders),
            ),
        );
    }
}
