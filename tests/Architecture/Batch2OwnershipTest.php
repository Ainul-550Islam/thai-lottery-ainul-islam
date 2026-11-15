<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Tests\TestCase;

/**
 * Structural guarantees of the batch-2 lane, asserted by reading its source.
 *
 * WHY SOURCE-LEVEL TESTS AND NOT ONLY BEHAVIOURAL ONES
 * Several of batch 2's rules are about what code must NOT contain: no second
 * writer of an exposure row, no second writer of an import row, no balance
 * arithmetic, no hardcoded odds or draw times. A behavioural test can pass while
 * a forbidden second writer exists and simply was not exercised. These
 * assertions fail at the moment such a line is added.
 */
final class Batch2OwnershipTest extends TestCase
{
    /**
     * @return array<string, string> path => source
     */
    private function laneSources(): array
    {
        $roots = [
            base_path('app/Services/LotteryGames'),
            base_path('app/Jobs/LotteryGames'),
            base_path('app/Console/Commands/LotteryGames'),
            base_path('app/Listeners/LotteryGames'),
            base_path('app/Listeners/Cashback'),
            base_path('app/Http/Controllers/Api/V1/LotteryGameController.php'),
            base_path('app/Http/Controllers/Api/V1/GameBetController.php'),
            base_path('app/Http/Controllers/Api/V1/GameResultController.php'),
            base_path('app/Providers/LotteryGamesServiceProvider.php'),
        ];

        $sources = [];

        foreach ($roots as $root) {
            if (is_file($root)) {
                $sources[$root] = (string) file_get_contents($root);

                continue;
            }

            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        return $sources;
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }

    /**
     * The same source with every comment removed.
     *
     * WHY: this lane explains its rules in long docblocks that quote the very
     * values it is forbidden to hardcode ("never a hardcoded 21:15"). A naive
     * substring scan would fail on the explanation, and the fix must not be to
     * delete the explanation.
     */
    private function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    public function test_the_lane_has_sources_to_check(): void
    {
        $this->assertGreaterThan(15, count($this->laneSources()));
    }

    public function test_only_the_exposure_service_writes_exposure_rows(): void
    {
        $writers = [];

        foreach ($this->laneSources() as $path => $source) {
            $relative = $this->relative($path);

            if (str_ends_with($relative, 'LiabilityExposureService.php')) {
                continue;
            }

            if (preg_match('/GameNumberExposure::query\(\)\s*->\s*(create|update|insert|delete|updateOrCreate|firstOrCreate)/', $source) === 1) {
                $writers[] = $relative;
            }

            if (str_contains($source, "DB::table('game_number_exposures')")) {
                $writers[] = $relative;
            }
        }

        $this->assertSame([], $writers, 'liability exposure has exactly one writer');
    }

    public function test_only_the_ingestion_service_stages_import_rows(): void
    {
        $writers = [];

        foreach ($this->laneSources() as $path => $source) {
            $relative = $this->relative($path);

            if (str_ends_with($relative, 'GameResultIngestionService.php')) {
                continue;
            }

            if (str_contains($source, 'GameResultImport::query()->create') || str_contains($source, "DB::table('game_result_imports')")) {
                $writers[] = $relative;
            }
        }

        $this->assertSame([], $writers, 'result imports have exactly one writer');
    }

    public function test_the_lane_never_writes_a_balance_or_a_ledger_entry(): void
    {
        $forbidden = [
            "DB::table('wallets')",
            "DB::table('ledger_entries')",
            "DB::table('financial_transactions')",
            "DB::table('wallet_transactions')",
            'Wallet::query()->update',
            'LedgerEntry::query()->create',
            'LedgerEntry::create',
        ];

        $violations = [];

        foreach ($this->laneSources() as $path => $source) {
            foreach ($forbidden as $needle) {
                if (str_contains($source, $needle)) {
                    $violations[] = $this->relative($path).' → '.$needle;
                }
            }
        }

        $this->assertSame([], $violations, 'money moves only through the existing wallet/ledger services');
    }

    public function test_the_lane_does_no_float_money_arithmetic(): void
    {
        $violations = [];

        foreach ($this->laneSources() as $path => $source) {
            // A float cast on anything named like money is the exact bug bcmath
            // exists to prevent.
            if (preg_match('/\(float\)\s*\$[a-z_]*(amount|stake|payout|balance|liability|total)/i', $source) === 1) {
                $violations[] = $this->relative($path);
            }

            if (preg_match('/(floatval|round|intval)\(\s*\$[a-z_]*(amount|stake|payout|balance)/i', $source) === 1) {
                $violations[] = $this->relative($path);
            }
        }

        $this->assertSame([], $violations, 'money is exact decimal strings, never floats');
    }

    public function test_digits_are_never_cast_to_int(): void
    {
        $violations = [];

        foreach ($this->laneSources() as $path => $source) {
            if (preg_match('/\(int\)\s*\$[a-z_]*(digit|number|drawNumber|draw_no)/i', $source) === 1) {
                $violations[] = $this->relative($path);
            }

            if (preg_match('/intval\(\s*\$[a-z_]*(digit|number)/i', $source) === 1) {
                $violations[] = $this->relative($path);
            }
        }

        $this->assertSame([], $violations, 'a selection like "0042" must never become 42');
    }

    public function test_no_official_value_is_hardcoded_in_the_lane(): void
    {
        $violations = [];

        foreach ($this->laneSources() as $path => $source) {
            $source = $this->withoutComments($source);

            // Draw times, multipliers and cutoffs belong to configuration. The
            // mockup times are the specific trap this guards against.
            foreach (['21:15', '21:30', '21:45', "'90'", "'900'", 'cutoff_seconds\' => 300'] as $needle) {
                if (str_contains($source, $needle)) {
                    $violations[] = $this->relative($path).' → '.$needle;
                }
            }
        }

        $this->assertSame([], $violations, 'an official rule value may only come from config/lottery_games.php');
    }

    public function test_the_games_read_their_rules_from_the_registry_only(): void
    {
        foreach (['Speed88Game.php', 'LaoHanoi4DGame.php'] as $file) {
            $source = (string) file_get_contents(base_path('app/Services/LotteryGames/Games/'.$file));

            // A game class must not declare its own digit count, multiplier or
            // market list: it resolves the definition from GameRegistry, which is
            // the single read point for config('lottery_games').
            $this->assertStringContainsString('GameRegistry', $source, $file.' must read rules through the registry');
            $this->assertDoesNotMatchRegularExpression('/payout_multiplier\'\s*=>\s*\'?\d/', $source);
        }
    }

    public function test_the_registry_is_the_only_reader_of_the_game_config_block(): void
    {
        $readers = [];

        foreach ($this->laneSources() as $path => $source) {
            $relative = $this->relative($path);

            if (str_ends_with($relative, 'GameRegistry.php')) {
                continue;
            }

            // COMMENTS ARE NOT CODE.
            // This rule is about who READS the rule block at runtime. A docblock that
            // mentions config('lottery_games.games.*') while explaining why it does NOT read
            // it is not a reader - and treating it as one would push a future author to
            // delete an accurate comment to make a test pass, which is the worst possible
            // outcome of an architecture check. Comments and strings-as-prose are stripped
            // before the match.
            $code = $this->stripComments($source);

            if (str_contains($code, "config('lottery_games.games") || str_contains($code, 'config->get(\'lottery_games.games')) {
                $readers[] = $relative;
            }
        }

        // THE ONE PERMITTED READER ABOVE THE REGISTRY, NAMED RATHER THAN TOLERATED.
        // RulesProfileResolver decides WHICH block is in force (declared config, a
        // non-production profile, an operator override) and writes the answer back into the
        // config repository, which the registry then reads. It is therefore upstream of the
        // registry rather than a second opinion beside it: it never prices a bet, never
        // settles one, and never answers a governance question. Keeping it out of this list
        // would mean either lying about the rule or hiding the read behind an indirection,
        // and both are worse than one named exception.
        //
        // The list is asserted EXACTLY, so a third reader cannot join silently: an import
        // command that reached past the registry (it used to, and was fixed) fails here.
        //
        // The Filament verification action is not in the list because it hashes the block
        // through the registry's definition, not through config().
        $this->assertSame(
            ['app/Services/LotteryGames/RulesProfileResolver.php'],
            $readers,
            'game rules are read through GameRegistry, and RulesProfileResolver is the only layer writer above it',
        );
    }

    public function test_new_composer_dependencies_were_not_added(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

        $this->assertIsArray($composer);

        foreach (['cron-expression', 'dragonmantank'] as $package) {
            $inRequire = isset($composer['require'][$package]) || isset($composer['require-dev'][$package]);
            $this->assertFalse($inRequire, $package.' must not be added as a direct dependency');
        }
    }

    public function test_only_the_audit_recorder_writes_audit_rows(): void
    {
        $sources = $this->laneSources();

        // The operator console and the cancellation listeners live outside the lane roots
        // above but call the recorder, so they are scanned for a direct write as well.
        foreach ([base_path('app/Filament'), base_path('app/Policies'), base_path('app/Models')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        $writers = [];

        foreach ($sources as $path => $source) {
            $relative = $this->relative($path);

            if (str_ends_with($relative, 'GameAuditRecorder.php') || str_ends_with($relative, 'GameAuditEvent.php')) {
                continue;
            }

            $code = $this->stripComments($source);

            if (preg_match('/GameAuditEvent::(query\(\)\s*->\s*)?(create|insert|update|delete|updateOrCreate|firstOrCreate|truncate)/', $code) === 1
                || str_contains($code, "DB::table('game_audit_events')")
                || str_contains($code, 'new GameAuditEvent')) {
                $writers[] = $relative;
            }
        }

        $this->assertSame([], $writers, 'the audit trail has exactly one writer');
    }

    public function test_every_audit_event_constant_is_in_the_published_vocabulary(): void
    {
        $constants = array_values((new \ReflectionClass(\App\Services\LotteryGames\GameAuditRecorder::class))->getConstants());

        $this->assertNotEmpty($constants);

        $missing = array_values(array_diff($constants, \App\Services\LotteryGames\GameAuditRecorder::vocabulary()));
        $extra = array_values(array_diff(\App\Services\LotteryGames\GameAuditRecorder::vocabulary(), $constants));

        $this->assertSame([], $missing, 'an event constant that vocabulary() does not list is invisible to an auditor');
        $this->assertSame([], $extra, 'vocabulary() lists a name no constant defines');
    }

    public function test_the_money_and_decision_paths_reach_the_audit_recorder(): void
    {
        $expected = [
            'app/Services/LotteryGames/GameBetPlacementService.php' => ['BET_PLACED', 'BET_ATTRIBUTION_FAILED', 'BET_PLACEMENT_FAILED'],
            'app/Services/LotteryGames/GameResultIngestionService.php' => ['RESULT_STAGED', 'RESULT_PUBLISHED'],
            'app/Listeners/LotteryGames/ReleaseExposureOnBetCancelled.php' => ['BET_EXPOSURE_RELEASED'],
            'app/Filament/Resources/LotteryGameResource.php' => ['RULES_VERIFIED', 'GAME_SUSPENDED', 'GAME_RESUMED', 'GAME_ENABLED', 'GAME_DISABLED'],
            'app/Console/Commands/LotteryGames/RulesImportCommand.php' => ['RULES_OVERRIDE_INSTALLED'],
        ];

        $silent = [];

        foreach ($expected as $path => $events) {
            $code = $this->stripComments((string) file_get_contents(base_path($path)));

            foreach ($events as $event) {
                if (! str_contains($code, 'GameAuditRecorder::'.$event)) {
                    $silent[] = $path.' → '.$event;
                }
            }
        }

        $this->assertSame([], $silent, 'a decision path stopped writing its audit event');
    }

    /**
     * Strip PHP comments so an architecture rule matches CODE rather than prose.
     *
     * Tokens are used rather than a regex: a regex over raw source turns a quoted '//' in a
     * string into a truncated line, and would then both miss real readers and invent fake
     * ones.
     */
    private function stripComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }
}
