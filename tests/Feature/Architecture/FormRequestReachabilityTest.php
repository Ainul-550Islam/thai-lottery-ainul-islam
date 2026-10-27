<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * No FormRequest may sit in this app unreferenced.
 *
 * WHY THIS FILE EXISTS
 * 36 request classes live in app/Http/Requests. Two of them were referenced by nothing -
 * not a controller, not a route, not a test:
 *
 *   App\Http\Requests\Web\ContactRequest   -> class ContactRequest extends ContactMessageRequest {}
 *   App\Http\Requests\Web\WithdrawRequest  -> its own rules() implementation
 *
 * An empty alias is not harmless: it answers "which class validates the web contact form?"
 * with a name that is not the one the route uses, and the day someone adds a rule to it, the
 * rule applies to nobody. The divergent one was worse. WithdrawRequest looked, by name and
 * namespace, like the canonical web withdrawal validator, and its rules were strictly WEAKER
 * than the inline ones in PlayerWebController::storeWithdraw(): one generic account_number
 * pattern for every channel where the controller has a per-method shape, a bank_name with no
 * allow-list, and a currency rule for a field the controller never reads. "Wire it up and
 * stop duplicating validation" - the obvious, well-intentioned refactor - would have replaced
 * four per-method rules with a generic one and made it look like cleanup.
 *
 * Both are deleted. What remains here is the rule that keeps them deleted, plus the salvage
 * the comparison produced: the one thing WithdrawRequest enforced that nothing on the live
 * path did is now a rule in the controller (see WithdrawalDestinationShapeTest), so removing
 * the class lost no policy - it removed a second, worse copy of it.
 *
 * The scan is filesystem-derived on purpose. A list of "requests that must be referenced"
 * written into this file would shrink to whatever its author last remembered, which is the
 * exact failure mode being fixed.
 */
final class FormRequestReachabilityTest extends TestCase
{
    /**
     * Request classes allowed to have no referencer, each with the reason. Adding a name
     * here is a decision about the app's validation surface, not a way to silence a test:
     * the reason must be long enough to be checkable, and the entry must be removed when
     * the class is wired or deleted.
     *
     * @var array<string, string>
     */
    private const UNREFERENCED_BY_DESIGN = [];

    public function test_every_form_request_is_referenced_by_something_that_uses_it(): void
    {
        $classes = $this->formRequestClasses();

        $this->assertNotEmpty($classes, 'The scan found no FormRequest classes, so this test is vacuous.');

        $corpus = $this->repoPhpFiles();

        foreach ($classes as $class => $ownPath) {
            if (array_key_exists($class, self::UNREFERENCED_BY_DESIGN)) {
                $this->assertGreaterThanOrEqual(
                    40,
                    strlen(self::UNREFERENCED_BY_DESIGN[$class]),
                    $class.' is exempted without a reason long enough to be a decision.'
                );

                continue;
            }

            $referencers = [];

            foreach ($corpus as $path => $source) {
                if ($path === $ownPath) {
                    continue;
                }

                if (str_contains($source, '\\'.$class) || preg_match('/\b'.$class.'\b/', $source) === 1) {
                    $referencers[] = $path;
                }
            }

            $this->assertNotSame(
                [],
                $referencers,
                sprintf(
                    '%s is declared in %s and referenced by nothing in app/, routes/, bootstrap/ or tests/. '
                    .'Either wire it where its name says it belongs, or delete it: an unreferenced validator is a '
                    .'second, untested, usually weaker copy of the rules that actually run.',
                    $class,
                    $ownPath
                )
            );
        }
    }

    public function test_no_form_request_is_an_empty_alias_of_another(): void
    {
        foreach ($this->formRequestClasses() as $class => $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('/class\s+'.$class.'\s+extends\s+\w+\s*\{\s*\}/', $source) === 1) {
                $this->fail(sprintf(
                    '%s adds nothing to its parent. A subclass with no body is how two names end up owning one '
                    .'validation policy, with drift possible on exactly one of them.',
                    $class
                ));
            }
        }

        $this->assertTrue(true);
    }

    public function test_the_two_orphans_this_file_was_written_about_stay_gone(): void
    {
        // Not a style preference: these two names are what a future reader of the web
        // withdrawal route would reach for, and one of them enforced weaker rules than the
        // code that actually ran. Their return would go unnoticed by every other test.
        foreach ([
            'App\\Http\\Requests\\Web\\WithdrawRequest' => 'Http/Requests/Web/WithdrawRequest.php',
            'App\\Http\\Requests\\Web\\ContactRequest' => 'Http/Requests/Web/ContactRequest.php',
        ] as $class => $relative) {
            // Deliberately a file check, not class_exists(). Under the test bootstrap a
            // warning becomes an exception, and a stale optimized classmap makes the
            // autoloader include()-then-warn for a deleted class: the failure would be
            // about the build cache, not about the app. The file is the fact.
            $this->assertFalse(
                is_file(app_path($relative)),
                $class.' is back ('.$relative.'). If it is needed, port the rules into the live path and '
                .'delete the duplicate instead of letting two validators disagree about one endpoint.'
            );
        }
    }

    public function test_the_exempt_list_cannot_shelter_a_class_that_no_longer_exists(): void
    {
        foreach (array_keys(self::UNREFERENCED_BY_DESIGN) as $class) {
            $this->assertTrue(
                class_exists($class),
                $class.' is exempted but the class is gone - remove the exemption, it hides nothing.'
            );
        }

        $this->assertTrue(true);
    }

    // ── scanning ────────────────────────────────────────────────────────────

    /**
     * @return array<string, string> class name => absolute path
     */
    private function formRequestClasses(): array
    {
        $found = [];

        foreach ($this->phpFilesUnder(app_path('Http/Requests')) as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('/^\s*class\s+(\w+)/m', $source, $m) === 1) {
                $found[$m[1]] = $path;
            }
        }

        return $found;
    }

    /**
     * Every PHP file that could reasonably name a request class: the app, its bootstrap and
     * the tests. Tests count as referencers on purpose - a class exercised only by a test is
     * still a class whose behaviour is pinned, which is a different problem from the one
     * here, and reporting it as this one would blur the signal.
     *
     * @return array<string, string> path => source
     */
    private function repoPhpFiles(): array
    {
        $corpus = [];

        foreach (['app', 'routes', 'bootstrap', 'tests'] as $dir) {
            foreach ($this->phpFilesUnder(base_path($dir)) as $path) {
                $corpus[$path] = (string) file_get_contents($path);
            }
        }

        return $corpus;
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
                } elseif (str_ends_with($path, '.php')) {
                    $files[] = $path;
                }
            }
        };

        $walk($dir);
        sort($files);

        return $files;
    }
}
