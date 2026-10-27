<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Permanent guard against tests that opt themselves out of the suite.
 *
 * A markTestSkipped() or markTestIncomplete() call that is not wrapped in an
 * explicit runtime environment guard (the real-engine pattern used across the
 * codebase: `if (DB::getDriverName() !== 'mysql') { $this->markTestSkipped(...); }`)
 * turns a failing or unwritten assertion into a green run, which is exactly how
 * test debt hides. The same is true of the PHPUnit #[Skip] attribute, which has
 * no lexical guard at all.
 *
 * The check is structural and runs over real code tokens:
 *   - every markTestSkipped/markTestIncomplete call must sit inside an if block
 *     whose condition is not a literal constant (if (true) would be an
 *     unconditional skip in disguise);
 *   - any #[...Skip] attribute fails unconditionally, because an attribute can
 *     never be guarded by an if;
 *   - string literals, comments and docblocks are not code tokens, so a test
 *     that merely mentions the word "markTestSkipped" is not flagged.
 *
 * Existing guarded real-engine skips (driver, DSN, extension, native binary and
 * build-artifact checks) remain legal.
 */
final class NoUnconditionalSkipTest extends TestCase
{
    private const SKIP_METHODS = ['markTestSkipped', 'markTestIncomplete'];

    public function test_no_test_skips_or_incompletes_without_an_environment_guard(): void
    {
        $violations = [];
        $scannedFiles = 0;

        foreach ($this->phpFilesUnder(base_path('tests')) as $path) {
            $scannedFiles++;
            $relative = $this->relative($path);

            foreach ($this->violationsIn($path) as $violation) {
                $violations[] = sprintf('%s:%d  %s', $relative, $violation['line'], $violation['reason']);
            }
        }

        $this->assertGreaterThan(0, $scannedFiles, 'The skip guard found no PHP test files; it would be vacuous.');

        if ($violations !== []) {
            $this->fail("Unconditional test skips are forbidden; wrap the skip in an explicit runtime guard (for example an if on the database driver, an env DSN, an extension or a build artifact):\n  ".implode("\n  ", $violations));
        }

        // Explicit positive assertion for the clean-tree case.
        $this->assertSame([], $violations);
    }

    /**
     * @return list<array{line: int, reason: string}>
     */
    private function violationsIn(string $path): array
    {
        $source = file_get_contents($path);

        if ($source === false) {
            return [];
        }

        if (! str_contains($source, 'markTest') && ! str_contains($source, '#[')) {
            return [];
        }

        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError $e) {
            $this->fail(sprintf('Cannot scan %s for skips: %s', $this->relative($path), $e->getMessage()));
        }

        // Map from the token index of an if-block's opening brace to its
        // normalized condition source.
        $ifBraceConditions = [];

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_IF) {
                $openParen = $this->nextSignificant($tokens, $i);

                if ($openParen !== null && $tokens[$openParen] === '(') {
                    $closeParen = $this->matchingParen($tokens, $openParen);
                    $brace = $this->nextSignificant($tokens, $closeParen);

                    if ($brace !== null && $tokens[$brace] === '{') {
                        $ifBraceConditions[$brace] = $this->conditionSource($tokens, $openParen + 1, $closeParen - 1);
                    }
                }
            }
        }

        // Walk the token stream maintaining a stack of brace blocks; each entry
        // records whether that brace opened an if block and, if so, its condition.
        $blockStack = [];
        $violations = [];

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '{') {
                $condition = $ifBraceConditions[$i] ?? null;
                $blockStack[] = ['is_if' => $condition !== null, 'condition' => $condition];

                continue;
            }

            if ($token === '}') {
                array_pop($blockStack);

                continue;
            }

            if (is_array($token) && $token[0] === T_ATTRIBUTE) {
                $closeBracket = $this->matchingAttributeBracket($tokens, $i);

                if ($this->attributeNamesSkip($tokens, $i, $closeBracket)) {
                    $violations[] = [
                        'line' => $token[2],
                        'reason' => 'a #[Skip] attribute cannot be guarded by a runtime condition; remove it or express the condition as an if around a markTestSkipped() call',
                    ];
                }

                continue;
            }

            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            if (! in_array($token[1], self::SKIP_METHODS, true)) {
                continue;
            }

            if ($this->nextSignificant($tokens, $i) === null || $tokens[$this->nextSignificant($tokens, $i)] !== '(') {
                // Mentioned as an identifier rather than called.
                continue;
            }

            $guard = $this->enclosingGuard($blockStack);

            if ($guard === null) {
                $violations[] = [
                    'line' => $token[2],
                    'reason' => sprintf('%s() is not inside an if guard; an unconditional skip hides the test result', $token[1]),
                ];

                continue;
            }

            if ($this->conditionIsConstantTrue($guard)) {
                $violations[] = [
                    'line' => $token[2],
                    'reason' => sprintf('%s() sits behind an if with a constant-true condition (%s), which is an unconditional skip in disguise', $token[1], $guard),
                ];
            }
        }

        return $violations;
    }

    /**
     * Find the nearest enclosing if block and return its condition, or null when
     * the call is not lexically guarded by any if.
     *
     * @param  list<array{is_if: bool, condition: string|null}>  $blockStack
     */
    private function enclosingGuard(array $blockStack): ?string
    {
        for ($depth = count($blockStack) - 1; $depth >= 0; $depth--) {
            if ($blockStack[$depth]['is_if']) {
                return $blockStack[$depth]['condition'];
            }
        }

        return null;
    }

    /**
     * Reject conditions that always evaluate true, such as `true` or `! false`,
     * which would make the "guard" meaningless. Real environmental conditions
     * (driver names, env values, is_file(), class_exists(), variables populated
     * from the environment) always contain a non-boolean-literal token.
     */
    private function conditionIsConstantTrue(string $condition): bool
    {
        $normalized = preg_replace('/\s+/', '', $condition) ?? '';

        return in_array($normalized, ['true', '!false', '!!true', 'true===true', '1===1'], true);
    }

    private function attributeNamesSkip(array $tokens, int $start, int $end): bool
    {
        for ($i = $start; $i <= $end; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING && $tokens[$i][1] === 'Skip') {
                return true;
            }
        }

        return false;
    }

    private function matchingAttributeBracket(array $tokens, int $attributeIndex): int
    {
        $depth = 0;

        for ($i = $attributeIndex, $count = count($tokens); $i < $count; $i++) {
            if ($tokens[$i] === '[') {
                $depth++;
            } elseif ($tokens[$i] === ']') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return count($tokens) - 1;
    }

    private function matchingParen(array $tokens, int $openParen): int
    {
        $depth = 0;

        for ($i = $openParen, $count = count($tokens); $i < $count; $i++) {
            if ($tokens[$i] === '(') {
                $depth++;
            } elseif ($tokens[$i] === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return count($tokens) - 1;
    }

    private function nextSignificant(array $tokens, int $index): ?int
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                continue;
            }

            if (is_array($tokens[$i]) && ($tokens[$i][0] === T_COMMENT || $tokens[$i][0] === T_DOC_COMMENT)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    private function conditionSource(array $tokens, int $start, int $end): string
    {
        $parts = [];

        for ($i = $start; $i <= $end; $i++) {
            $parts[] = is_array($tokens[$i]) ? $tokens[$i][1] : (string) $tokens[$i];
        }

        return trim(preg_replace('/\s+/', ' ', implode('', $parts)) ?? '');
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
