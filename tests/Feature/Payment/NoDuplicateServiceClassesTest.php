<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Tests\TestCase;

/**
 * One class, one name, one place.
 *
 * WHY THIS SUITE EXISTS
 * `app/Services/Payment/WithdrawalCompletionService.php` is a byte-identical
 * copy of `app/Services/Finance/WithdrawalCompletionService.php` that still
 * declares `namespace App\Services\Finance;` from a Payment path. Two different
 * files declaring the SAME fully-qualified class name is the worst kind of
 * defect: the class map resolves to whichever file Composer happens to index
 * first, so editing one file and running the other is a live possibility, and
 * nothing about either file looks wrong in isolation.
 *
 * apply-batch-05.sh deletes the Payment-path copy (see DELETE-LIST.txt). These
 * assertions are the tripwire: they fail while the duplicate exists and pass
 * once it is gone, which is what makes the deletion a verified closure rather
 * than a hopeful cleanup.
 *
 * The second test is the general form of the same rule — every first-party
 * file must declare the namespace its path implies — because a PSR-4 mismatch
 * is how a file becomes invisible to the autoloader without anybody noticing.
 */
final class NoDuplicateServiceClassesTest extends TestCase
{
    public function test_no_two_first_party_files_declare_the_same_class(): void
    {
        $declarations = $this->declarationsFor('app', 'App');

        $duplicates = [];

        foreach ($declarations as $class => $paths) {
            if (count($paths) > 1) {
                $duplicates[] = sprintf('%s is declared by %s', $class, implode(' and ', $paths));
            }
        }

        $this->assertSame(
            [],
            $duplicates,
            "Two files declaring one class name means Composer picks one and the other is dead code that still looks authoritative.\n"
            .implode("\n", $duplicates),
        );
    }

    public function test_no_two_test_files_declare_the_same_class(): void
    {
        $declarations = $this->declarationsFor('tests', 'Tests');

        $duplicates = [];

        foreach ($declarations as $class => $paths) {
            if (count($paths) > 1) {
                $duplicates[] = sprintf('%s is declared by %s', $class, implode(' and ', $paths));
            }
        }

        $this->assertSame(
            [],
            $duplicates,
            "Two test classes with one name means one of them never runs.\n".implode("\n", $duplicates),
        );
    }

    public function test_every_application_file_declares_the_namespace_its_path_implies(): void
    {
        $drift = [];

        foreach ($this->phpFiles(base_path('app')) as $path => $relative) {
            $declared = $this->declaredNamespace($path);

            if ($declared === null) {
                continue;
            }

            // $relative is repository-relative ("app/Providers/X.php"); drop the
            // leading "app/" so what is left is the PSR-4 path under App\.
            $psr4 = substr($relative, strlen('app/'));

            $expected = 'App'.(dirname($psr4) !== '.' ? '\\'.str_replace('/', '\\', dirname($psr4)) : '');

            if ($declared !== $expected) {
                $drift[] = sprintf('%s declares %s but its path implies %s', $relative, $declared, $expected);
            }
        }

        $this->assertSame(
            [],
            $drift,
            "A file whose namespace does not match its path is unreachable by PSR-4 autoloading.\n".implode("\n", $drift),
        );
    }

    /**
     * @return array<string, list<string>> fully-qualified class name => declaring files
     */
    private function declarationsFor(string $directory, string $rootNamespace): array
    {
        $declarations = [];

        foreach ($this->phpFiles(base_path($directory)) as $path => $relative) {
            $declared = $this->declaredNamespace($path);
            $name = $this->declaredTypeName($path);

            if ($declared === null || $name === null) {
                continue;
            }

            if ($declared !== $rootNamespace && ! str_starts_with($declared, $rootNamespace.'\\')) {
                continue;
            }

            $declarations[$declared.'\\'.$name][] = $relative;
        }

        return $declarations;
    }

    /**
     * @return array<string, string> absolute path => repository-relative path
     */
    private function phpFiles(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        $prefix = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $absolute = str_replace('\\', '/', $file->getPathname());
                $files[$absolute] = str_starts_with($absolute, $prefix)
                    ? substr($absolute, strlen($prefix))
                    : $absolute;
            }
        }

        return $files;
    }

    private function declaredNamespace(string $path): ?string
    {
        $contents = (string) file_get_contents($path);

        return preg_match('/^namespace\s+([^;{]+)[;{]/m', $contents, $matches) === 1
            ? trim($matches[1])
            : null;
    }

    private function declaredTypeName(string $path): ?string
    {
        $contents = (string) file_get_contents($path);

        return preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $contents, $matches) === 1
            ? $matches[1]
            : null;
    }
}
