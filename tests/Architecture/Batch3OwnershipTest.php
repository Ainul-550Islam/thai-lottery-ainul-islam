<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Tests\TestCase;

/**
 * The ownership map, enforced by reflection instead of by convention.
 *
 * THE RULE BEING ENFORCED
 * Every money-moving write already belongs to a service that predates this batch. The
 * new Batch 3 surface is a READ MODEL plus a draft basket; the only thing in it
 * allowed to reach the wallet tables is BetSlipConfirmationService, and even that one
 * does so by calling the existing purchase pipeline rather than by writing a balance.
 *
 * WHY A SOURCE SCAN RATHER THAN A REVIEW CHECKLIST
 * A checklist is followed on the day it is written. This test fails the build the
 * first time somebody adds `$wallet->balance = ...` to a read model, which is the
 * moment the mistake is cheap to undo.
 */
final class Batch3OwnershipTest extends TestCase
{
    /**
     * Directories that must never write to the money tables.
     *
     * @return array<string, array{0:string}>
     */
    public static function readOnlyRoots(): array
    {
        return [
            'wallet read models' => ['app/Services/Wallet'],
            'profile read model' => ['app/Services/Account'],
            'security read models' => ['app/Services/Security'],
            'my bets read model' => ['app/Services/MyBets'],
            'promotions read model' => ['app/Services/Promotions'],
            'FX display' => ['app/Services/Fx'],
            'compliance' => ['app/Services/Compliance'],
            'ops probes' => ['app/Services/Ops'],
        ];
    }

    /**
     * @dataProvider readOnlyRoots
     */
    public function test_a_read_model_never_writes_a_wallet_or_ledger_row(string $directory): void
    {
        $root = base_path($directory);

        if (! is_dir($root)) {
            $this->markTestSkipped($directory.' is not present in this checkout.');
        }

        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $number => $line) {
                $trimmed = trim($line);

                // Comments are explanations, not writes.
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                    continue;
                }

                if (! preg_match('/\b(Wallet|LedgerEntry|FinancialTransaction|WalletBalance)\b/', $line)) {
                    continue;
                }

                if (preg_match('/::\s*(create|insert|updateOrCreate|firstOrCreate|forceCreate)\s*\(/', $line) === 1) {
                    $offenders[] = str_replace(base_path().'/', '', $path).':'.($number + 1).' '.$trimmed;
                }

                if (preg_match('/->\s*(save|update|delete|increment|decrement|forceDelete)\s*\(/', $line) === 1) {
                    $offenders[] = str_replace(base_path().'/', '', $path).':'.($number + 1).' '.$trimmed;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A read model writes to a money table:\n  ".implode("\n  ", $offenders).
            "\nMoney moves through the ledger services only.",
        );
    }

    public function test_the_wallet_balance_can_only_be_changed_through_the_ledger_services(): void
    {
        /*
         * SCOPE: THE BATCH 3 SURFACE ONLY.
         *
         * Pre-existing writers are not in question. App\Services\Finance\WalletService
         * IS the ledger service - assigning a balance is its job - and
         * App\Services\Agent\AgentOnboardingService creates a wallet at zero when it
         * provisions an agent, which is provisioning rather than movement. Neither is
         * this batch's code and neither is changed by it.
         *
         * What this asserts is narrower, and is the thing that can actually regress:
         * nothing NEW in Batch 3 assigns a balance directly. A read model that did so
         * would bypass the ledger and produce money with no provenance.
         */
        $newRoots = [
            'app/Services/Wallet',
            'app/Services/BetSlip',
            'app/Services/MyBets',
            'app/Services/Compliance',
            'app/Services/Ops',
            'app/Services/Account',
            'app/Services/Promotions',
        ];

        $writers = [];

        foreach ($newRoots as $relativeRoot) {
            $root = base_path($relativeRoot);

            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                $relative = str_replace(base_path().'/', '', $file->getPathname());
                $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];

                foreach ($lines as $number => $line) {
                    $trimmed = trim($line);

                    if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                        continue;
                    }

                    // An assignment to a wallet's balance column, in the new code, is
                    // the exact shape of the bug this guards.
                    if (preg_match('/(wallet|\$w)\w*->\s*(balance|locked_balance)\s*=/i', $line) === 1) {
                        $writers[] = $relative.':'.($number + 1).' '.$trimmed;
                    }
                }
            }
        }


        $this->assertSame(
            [],
            $writers,
            "A wallet balance is assigned outside the ledger services:\n  ".implode("\n  ", $writers),
        );
    }

    public function test_a_member_facing_resource_never_exposes_a_member_id_or_a_secret(): void
    {
        $root = base_path('app/Http/Resources');

        $forbiddenKeys = ['password', 'remember_token', 'api_token', 'two_factor_secret', 'secret'];

        $offenders = [];

        foreach (glob($root.'/*.php') ?: [] as $file) {
            $contents = (string) file_get_contents($file);
            $name = basename($file);

            foreach ($forbiddenKeys as $key) {
                if (str_contains($contents, "'".$key."'")) {
                    $offenders[] = $name.': '.$key;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A resource serialises a credential field:\n  ".implode("\n  ", $offenders),
        );
    }

    public function test_the_batch_three_service_provider_binds_only_its_own_namespace(): void
    {
        $contents = (string) file_get_contents(base_path('app/Providers/PlayerExperienceServiceProvider.php'));

        // A provider that rebinds an existing service is a provider that changes
        // behaviour elsewhere in the application, which this batch must not do.
        $this->assertStringNotContainsString('LedgerService::class =>', $contents);
        $this->assertStringNotContainsString('BetPurchaseService::class =>', $contents);
        $this->assertStringNotContainsString('WalletService::class =>', $contents);
    }

    public function test_every_new_controller_lives_in_the_api_namespace(): void
    {
        $root = base_path('app/Http/Controllers/Api/V1');

        $this->assertDirectoryExists($root);

        foreach (glob($root.'/*.php') ?: [] as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertStringContainsString(
                'namespace App\\Http\\Controllers\\Api\\V1;',
                $contents,
                basename($file).' must sit in the V1 API namespace.',
            );
        }
    }

    public function test_no_new_file_contains_a_placeholder_ellipsis_body(): void
    {
        // "No shortening" is a delivery rule, not a style preference: a file that was
        // abbreviated is a file that was never compiled.
        $roots = [
            'app/Services/BetSlip', 'app/Services/MyBets', 'app/Services/Wallet',
            'app/Services/Fx', 'app/Services/Security', 'app/Services/Account',
            'app/Services/Notifications', 'app/Services/Loyalty', 'app/Services/Promotions',
            'app/Services/Support', 'app/Services/Compliance', 'app/Services/Ops',
            'app/Http/Controllers/Api/V1', 'app/Http/Requests/Api/V1',
            'app/Http/Resources', 'app/Filament/Resources', 'app/Models',
        ];

        $offenders = [];

        foreach ($roots as $relative) {
            $root = base_path($relative);

            if (! is_dir($root)) {
                continue;
            }

            foreach (glob($root.'/*.php') ?: [] as $file) {
                $contents = (string) file_get_contents($file);

                // A bare ellipsis on its own line inside a code block is the marker
                // of a truncated file. Docblock prose is fine.
                if (preg_match('/^\s*\.\.\.\s*$/m', $contents) === 1) {
                    $offenders[] = str_replace(base_path().'/', '', $file);
                }
            }
        }

        $this->assertSame([], $offenders, "A file was delivered truncated:\n  ".implode("\n  ", $offenders));
    }
}
