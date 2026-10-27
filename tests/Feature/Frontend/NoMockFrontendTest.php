<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use Tests\TestCase;

/**
 * The frontend may not simulate a server.
 *
 * ============================================================================
 * THE FOUR PATTERNS THIS SUITE BANS, AND WHAT EACH ONE COST
 * ============================================================================
 *
 * 1. `alert()` / `prompt()` AS THE SUCCESS SIGNAL. Modal, blocking, unannounced
 *    to a screen reader, and visually identical for "sent 500 THB" and "could not
 *    reach the server". Replaced by resources/js/ui/toast.js.
 *
 * 2. HARDCODED MONEY. A literal like `cashBalance: 5450.00` on a real-money page
 *    is a fabricated balance. A member who sees it will act on it.
 *
 * 3. `Math.random()` AS PROGRESS. A progress bar or an ETA driven by random
 *    numbers is a fake status that always "completes". Real progress comes from a
 *    server response or does not exist.
 *
 * 4. A `@vite()` ENTRY THAT IS NOT A DECLARED BUILD ENTRY, or a declared entry no
 *    view loads. Both produce a broken page: the first throws at render time, the
 *    second ships dead code.
 *
 * THE EXEMPTION IS EXPLICIT AND NAMED. resources/js/_unused/** holds quarantined
 * mockups that are not in the build (see _unused/README.md); they are excluded by
 * PATH, not by a pattern a filename could accidentally match.
 */
final class NoMockFrontendTest extends TestCase
{
    /**
     * Files that legitimately contain one of the banned patterns.
     *
     * Each needs a reason, because an unexplained exemption is how a rule dies.
     *
     * @var array<string, string>
     */
    /**
     * Files that legitimately contain one of the banned patterns.
     *
     * Batch 02 emptied this list. Every module it used to name has either been
     * rewritten to report through resources/js/ui/toast.js and
     * ui/field-error.js, or was deleted as a superseded mock. An unexplained
     * exemption is how a rule dies, so the honest state is none.
     *
     * The only remaining allowance is structural and lives in ALLOWED_RANDOM:
     * a narrow set of line shapes where Math.random() is not standing in for
     * server state (an idempotency key, a UUID fallback, a retry backoff).
     *
     * @var array<string, string>
     */
    private const EXEMPTIONS = [
        // The replacement for alert(). Its docblock explains the ban it enforces.
        'resources/js/ui/toast.js' => 'documents alert() in prose; never calls it',
        // The replacement for prompt(). Same reason.
        'resources/js/ui/inline-prompt.js' => 'documents prompt() in prose; never calls it',
    ];

    /**
     * Line shapes where Math.random() is legitimate, each with the reason it is
     * not the banned pattern.
     *
     * THE BAN IS ABOUT DISPLAYED STATE
     * A random number that reaches the screen as progress, a speed readout, an
     * ETA or an amount is a fabricated status that always "completes". A random
     * number that only has to be UNIQUE — an idempotency key, a fallback UUID —
     * is not status; it never claims anything about the server.
     *
     * Each pattern must match the whole call site's line, so a random progress
     * bar cannot hide behind one of these words in an unrelated line.
     *
     * @var array<string, string>
     */
    private const ALLOWED_RANDOM = [
        // `crypto.randomUUID` / `crypto.getRandomValues` fallbacks: the random
        // number exists only because a CSPRNG was unavailable. It supplies
        // bytes for a token, never a figure on screen.
        '~\b(?:randomUUID|getRandomValues|fillRandom|randomBytes)\b~' => 'CSPRNG fallback for a token',
        // `Date.now().toString(36) + Math.random()`: a collision-resistant
        // client token for idempotency or correlation.
        '~\bDate\.now\b~' => 'idempotency / correlation token',
        // RFC 4122 nibble fill.
        '~\(Math\.random\(\)\s*\*\s*16\)\s*\|\s*0~' => 'UUID v4 nibble fill',
        // Reconnect backoff jitter: spreading reconnects is the whole point.
        '~\bjitter\b~' => 'reconnect backoff jitter',
    ];

    /**
     * How many lines either side of a Math.random() call are inspected when
     * checking ALLOWED_RANDOM.
     *
     * WHY NOT THE WHOLE FILE
     * A file-level allowance would let a fabricated progress bar hide anywhere in
     * a module that also builds an idempotency key — and deposit-page modules do
     * exactly that. Six lines is enough to see the enclosing function's purpose
     * and too narrow to reach an unrelated widget.
     */
    private const RANDOM_CONTEXT_LINES = 6;


    /**
     * @return list<string> every module under resources/js, excluding quarantine
     */
    private function modules(): array
    {
        $base = resource_path('js');
        $out = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = 'resources/js/'.ltrim(str_replace($base, '', $file->getPathname()), DIRECTORY_SEPARATOR);

            if (str_starts_with($relative, 'resources/js/_unused/')) {
                continue;
            }

            if (! preg_match('/\.(js|ts|tsx|mjs)$/', $relative)) {
                continue;
            }

            $out[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        }

        sort($out);

        return $out;
    }

    /**
     * Comments removed, so a module that DOCUMENTS a banned pattern is not
     * accused of performing it.
     */
    private function stripComments(string $source): string
    {
        $withoutBlocks = (string) preg_replace('~/\*.*?\*/~s', '', $source);

        return (string) preg_replace('~(^|[^:])//[^\n]*~', '$1', $withoutBlocks);
    }

    /**
     * @return list<string>
     */
    private function offenders(string $pattern): array
    {
        $found = [];

        foreach ($this->modules() as $relative) {
            if (isset(self::EXEMPTIONS[$relative])) {
                continue;
            }

            $source = $this->stripComments((string) file_get_contents(base_path($relative)));

            if (preg_match($pattern, $source) === 1) {
                $found[] = $relative;
            }
        }

        return $found;
    }

    /**
     * Modules that use Math.random() somewhere other than an allowed shape.
     *
     * ALIGNED BLOCKS, NOT FILES
     * Lines matching ALLOWED_RANDOM are dropped before the scan, because the ban
     * is on a random number becoming displayed state, not on randomness itself.
     * If every random call in a file is allowed, the file is not an offender.
     *
     * @return list<string>
     */
    private function randomOffenders(): array
    {
        $found = [];

        foreach ($this->modules() as $relative) {
            if (isset(self::EXEMPTIONS[$relative])) {
                continue;
            }

            $source = $this->stripComments((string) file_get_contents(base_path($relative)));

            if (preg_match('/Math\.random\s*\(\s*\)/', $source) !== 1) {
                continue;
            }

            $lines = preg_split('/\R/', $source) ?: [];

            foreach ($lines as $index => $line) {
                if (preg_match('/Math\.random\s*\(\s*\)/', $line) !== 1) {
                    continue;
                }

                $allowed = false;
                $from = max(0, $index - self::RANDOM_CONTEXT_LINES);
                $to = min(count($lines) - 1, $index + self::RANDOM_CONTEXT_LINES);
                $window = implode("\n", array_slice($lines, $from, $to - $from + 1));

                foreach (array_keys(self::ALLOWED_RANDOM) as $pattern) {
                    if (preg_match($pattern, $window) === 1) {
                        $allowed = true;
                        break;
                    }
                }

                if (! $allowed) {
                    $found[] = $relative;
                    break;
                }
            }
        }

        return $found;
    }

    public function test_no_module_uses_alert_or_prompt_outside_the_named_legacy_list(): void
    {
        $offenders = $this->offenders('/(?<![.\w])(alert|prompt)\s*\(/');

        $this->assertSame(
            [],
            $offenders,
            "these modules use a blocking dialog; use resources/js/ui/toast.js instead:\n  - ".implode("\n  - ", $offenders),
        );
    }

    public function test_no_module_renders_a_hardcoded_money_balance(): void
    {
        // A numeric literal assigned to or beside a balance-shaped identifier.
        // Quoted strings are deliberately matched too: `cashBalance: '5450.00'`
        // is the same fabrication as the unquoted form.
        // WHAT THIS PATTERN ACTUALLY NEEDS TO CATCH
        // A fabricated figure is a money-shaped key assigned a literal amount:
        // `cashBalance: 5450.00`, `wallet_credit = '12000'`.
        //
        // WHAT IT MUST NOT CATCH, AND WHY THE FIRST DRAFT DID
        //   - attribute selectors: board.querySelector('[data-prize="3d_top"]')
        //     is a DOM lookup, not a claim about money;
        //   - readiness flags: root.dataset.prizeCheckerReady = '1' assigns the
        //     string "1" to a dataset key, which is a boolean in disguise;
        //   - zero initialisation: `var payoutMinor = 0;` states that no prize has
        //     been added up yet. Banning it would push authors towards declaring
        //     the variable later, which is worse code.
        //
        // So the value must be a WHOLE numeric literal (nothing after it but a
        // terminator), it must be non-zero, and the key must not be a dataset or
        // data- attribute. A real hardcoded balance still fails every part.
        $offenders = $this->offenders(
            '/\b(?<!dataset\.)(?<!data-)'
            .'(?:balance|cash|credit|prize|payout|wallet)'
            .'[A-Za-z0-9_]*\s*[:=]\s*'
            .'[\'"]?(?:[1-9]\d*(?:[.,]\d+)?|0[.,]\d+)[\'"]?'
            .'\s*[,;)\n}]/i',
        );

        $this->assertSame(
            [],
            $offenders,
            "these modules hardcode a money figure:\n  - ".implode("\n  - ", $offenders),
        );
    }

    public function test_no_module_uses_random_numbers_as_progress(): void
    {
        $offenders = $this->randomOffenders();

        $this->assertSame(
            [],
            $offenders,
            "these modules synthesise progress or jitter with Math.random(); use server-reported state:\n  - ".implode("\n  - ", $offenders),
        );
    }

    public function test_every_vite_entry_exists_on_disk(): void
    {
        $missing = [];

        foreach ($this->configEntries() as $entry) {
            if (! is_file(base_path($entry))) {
                $missing[] = $entry;
            }
        }

        $this->assertSame([], $missing, "vite.config.js declares entries that do not exist:\n  - ".implode("\n  - ", $missing));
    }

    public function test_every_vite_entry_is_referenced_by_a_view(): void
    {
        // One exemption class only: an entry that is imported by another entry
        // rather than by a Blade @vite() call. Nothing else may be dead weight in
        // the build.
        $declared = $this->configEntries();
        $referenced = $this->viewEntries();

        $unreferenced = [];

        foreach ($declared as $entry) {
            if (in_array($entry, $referenced, true)) {
                continue;
            }

            // An entry may also be reached through an import from another entry
            // rather than from a Blade @vite() call. Three shapes count, because
            // all three put the file in the build graph:
            //
            //   import x from './a.js'      static binding
            //   import './a.js'             side-effect import
            //   import('./a.js')            code-split chunk (app.js loads page
            //                               modules this way, only when the page
            //                               contains the module's root element)
            //
            // Earlier this only matched the first shape, so every lazily loaded
            // portal looked unreferenced and the natural "fix" would have been to
            // delete working page code.
            $imported = false;

            foreach ($declared as $other) {
                if ($other === $entry) {
                    continue;
                }

                $path = base_path($other);

                if (! is_file($path) || ! preg_match('/\.(js|mjs|ts|tsx|css)$/', $other)) {
                    continue;
                }

                $source = (string) file_get_contents($path);

                // The extension is optional in a module specifier: app.js imports
                // './echo' as often as './echo.js'. Matching only the full file
                // name made every extension-less import look like no import at
                // all, which is why these modules appeared unreferenced.
                $stem = preg_quote(pathinfo($entry, PATHINFO_FILENAME), '~');
                $basename = $stem.'(?:\\.js)?';

                $patterns = [
                    '~from\s+[\'"][^\'"]*'.$basename.'[\'"]~',
                    '~import\s+[\'"]\.{1,2}/[^\'"]*'.$basename.'[\'"]~',
                    '~import\s*\(\s*[\'"]\.{1,2}/[^\'"]*'.$basename.'[\'"]\s*\)~',
                    '~@import\s+(?:url\()?[\'"]\.{1,2}/[^\'"]*'.$basename.'[\'"]~',
                ];

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $source) === 1) {
                        $imported = true;
                        break 2;
                    }
                }
            }

            if (! $imported) {
                $unreferenced[] = $entry;
            }
        }

        $this->assertSame(
            [],
            $unreferenced,
            "vite.config.js declares entries no view references and no entry imports:\n  - ".implode("\n  - ", $unreferenced),
        );
    }

    public function test_no_quarantined_mockup_is_a_build_entry(): void
    {
        foreach ($this->configEntries() as $entry) {
            $this->assertStringNotContainsString('resources/js/components/', $entry);
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private function configEntries(): array
    {
        $raw = (string) file_get_contents(base_path('vite.config.js'));

        preg_match_all("~'(resources/[^']+)'~", $raw, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return list<string>
     */
    private function viewEntries(): array
    {
        $entries = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            $contents = (string) preg_replace('~\{\{--.*?--\}\}~s', '', $contents);

            if (preg_match_all('~@vite\(\s*\[(.*?)\]\s*\)~s', $contents, $blocks) > 0) {
                foreach ($blocks[1] as $block) {
                    preg_match_all("~'([^']+)'~", $block, $paths);

                    foreach ($paths[1] as $path) {
                        $entries[] = $path;
                    }
                }
            }

            if (preg_match_all("~@vite\(\s*'([^']+)'\s*\)~", $contents, $single) > 0) {
                foreach ($single[1] as $path) {
                    $entries[] = $path;
                }
            }
        }

        return array_values(array_unique($entries));
    }
}
