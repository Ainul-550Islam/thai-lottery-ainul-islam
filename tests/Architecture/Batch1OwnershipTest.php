<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Filament\Resources\CashbackPolicyResource;
use App\Filament\Resources\MemberBankAccountResource;
use App\Filament\Resources\RewardCatalogItemResource;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

final class Batch1OwnershipTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function batch1PhpFiles(): array
    {
        $paths = [
            base_path('config/rewards.php'),
            base_path('app/Enums/CashbackAccrualStatus.php'),
            base_path('app/Enums/RewardType.php'),
            base_path('app/Enums/RewardClaimStatus.php'),
            base_path('app/Enums/FreeBetVoucherStatus.php'),
            base_path('app/Enums/BankAccountStatus.php'),
            base_path('app/Enums/LoginEventOutcome.php'),
            base_path('app/Models/CashbackPolicy.php'),
            base_path('app/Models/CashbackAccrual.php'),
            base_path('app/Models/RewardCatalogItem.php'),
            base_path('app/Models/RewardClaim.php'),
            base_path('app/Models/FreeBetVoucher.php'),
            base_path('app/Models/MemberBankAccount.php'),
            base_path('app/Models/LoginEvent.php'),
            base_path('app/Policies/MemberBankAccountPolicy.php'),
            base_path('app/Policies/RewardClaimPolicy.php'),
            base_path('app/Providers/RewardsServiceProvider.php'),
            base_path('app/Services/Account/MemberBankAccountService.php'),
            base_path('app/Services/Affiliate/ReferralCenterService.php'),
            base_path('app/Services/Affiliate/ReferralPayoutRequestService.php'),
            base_path('app/Services/Home/PlayerDashboardSummaryService.php'),
            base_path('app/Http/Controllers/Api/V1/CashbackController.php'),
            base_path('app/Http/Controllers/Api/V1/RewardController.php'),
            base_path('app/Http/Controllers/Api/V1/LoginHistoryController.php'),
            base_path('app/Http/Controllers/Api/V1/BankAccountController.php'),
            base_path('app/Http/Controllers/Api/V1/ReferralCenterController.php'),
            base_path('app/Http/Controllers/Api/V1/DashboardSummaryController.php'),
            base_path('app/Http/Resources/CashbackSummaryResource.php'),
            base_path('app/Http/Resources/RewardCatalogItemResource.php'),
            base_path('app/Http/Resources/RewardClaimResource.php'),
            base_path('app/Http/Resources/LoginEventResource.php'),
            base_path('app/Http/Resources/MemberBankAccountResource.php'),
            base_path('app/Http/Resources/ReferralCenterResource.php'),
            base_path('app/Http/Resources/DashboardSummaryResource.php'),
            base_path('app/Filament/Resources/CashbackPolicyResource.php'),
            base_path('app/Filament/Resources/RewardCatalogItemResource.php'),
            base_path('app/Filament/Resources/MemberBankAccountResource.php'),
            base_path('app/Filament/Resources/RewardClaimResource.php'),
            base_path('app/Services/Home/DashboardCacheInvalidator.php'),
            base_path('tests/Unit/Cashback/CashbackCalculatorTest.php'),
            base_path('tests/Feature/Cashback/CashbackAccrualIdempotencyTest.php'),
            base_path('tests/Feature/Cashback/CashbackClaimTest.php'),
            base_path('tests/Feature/Cashback/CashbackClaimConcurrencyTest.php'),
            base_path('tests/Feature/Rewards/RewardRedemptionTest.php'),
            base_path('tests/Feature/Rewards/RewardRedemptionAtomicityTest.php'),
            base_path('tests/Feature/Rewards/RewardIdempotencyTest.php'),
            base_path('tests/Feature/Rewards/FreeBetVoucherServiceTest.php'),
            base_path('tests/Feature/Rewards/VipProgressTest.php'),
            base_path('tests/Feature/Security/LoginHistoryTest.php'),
            base_path('tests/Feature/Security/NewDeviceDetectionTest.php'),
            base_path('tests/Feature/Security/LoginEventDedupeTest.php'),
            base_path('tests/Feature/Account/BankAccountTest.php'),
            base_path('tests/Feature/Account/WithdrawalBankAccountLinkTest.php'),
            base_path('tests/Feature/Affiliate/ReferralCenterTest.php'),
            base_path('tests/Feature/Affiliate/ReferralPayoutRequestTest.php'),
            base_path('tests/Feature/Home/DashboardSummaryTest.php'),
        ];

        $directories = [
            base_path('app/Services/Cashback'),
            base_path('app/Services/Rewards'),
            base_path('app/Services/Security/LoginHistory'),
            base_path('app/Events/Rewards'),
            base_path('app/Events/Security'),
            base_path('app/Listeners/Security'),
            base_path('app/Jobs/Cashback'),
            base_path('app/Jobs/Rewards'),
            base_path('app/Console/Commands/Cashback'),
            base_path('app/Console/Commands/Security'),
            base_path('app/Http/Requests/Api/V1'),
            base_path('app/Filament/Resources/CashbackPolicyResource'),
            base_path('app/Filament/Resources/RewardCatalogItemResource'),
            base_path('app/Filament/Resources/MemberBankAccountResource'),
            base_path('app/Filament/Resources/RewardClaimResource'),
        ];

        foreach ($directories as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $paths[] = $file->getRealPath() ?: $file->getPathname();
                }
            }
        }

        return array_values(array_unique(array_filter($paths, 'is_file')));
    }

    /**
     * @return list<PhpToken>
     */
    private function significantTokens(string $source): array
    {
        $significant = [];
        foreach (PhpToken::tokenize($source) as $token) {
            if (! $token->isIgnorable()) {
                $significant[] = $token;
            }
        }

        return $significant;
    }

    public function test_no_direct_wallet_balance_mutation_or_floats_in_batch1_code(): void
    {
        $files = $this->batch1PhpFiles();
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $tokens = $this->significantTokens($source);
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                $tok = $tokens[$i];

                // Token scan: forbid (float)/(double)/(real) casts and floatval() calls
                $this->assertNotSame(
                    T_DOUBLE_CAST,
                    $tok->id,
                    "Floating-point cast token forbidden in {$file}:{$tok->line}",
                );
                if ($tok->id === T_STRING && strtolower($tok->text) === 'floatval') {
                    $this->fail("floatval() call forbidden in {$file}:{$tok->line}");
                }

                // Token scan: forbid direct balance / locked_balance increment or decrement calls
                if (
                    $tok->id === T_OBJECT_OPERATOR
                    && isset($tokens[$i + 1], $tokens[$i + 2], $tokens[$i + 3])
                    && in_array(strtolower($tokens[$i + 1]->text), ['increment', 'decrement'], true)
                    && $tokens[$i + 2]->text === '('
                    && in_array(trim($tokens[$i + 3]->text, "'\""), ['balance', 'locked_balance'], true)
                ) {
                    $this->fail("Direct balance {$tokens[$i + 1]->text}() forbidden in {$file}:{$tok->line}");
                }

                // Token scan: forbid DB::table('wallets')->update|increment|decrement|insert
                if (
                    $tok->text === 'DB'
                    && isset($tokens[$i + 1], $tokens[$i + 2], $tokens[$i + 3], $tokens[$i + 4], $tokens[$i + 6], $tokens[$i + 7])
                    && $tokens[$i + 1]->id === T_DOUBLE_COLON
                    && strtolower($tokens[$i + 2]->text) === 'table'
                    && $tokens[$i + 3]->text === '('
                    && trim($tokens[$i + 4]->text, "'\"") === 'wallets'
                    && $tokens[$i + 6]->id === T_OBJECT_OPERATOR
                    && in_array(strtolower($tokens[$i + 7]->text), ['update', 'increment', 'decrement', 'insert', 'upsert'], true)
                ) {
                    $this->fail("Direct DB::table('wallets') write forbidden in {$file}:{$tok->line}");
                }
            }
        }

        $calculatorSource = (string) file_get_contents(base_path('app/Services/Cashback/CashbackCalculator.php'));
        $this->assertDoesNotMatchRegularExpression(
            '/Illuminate\\\\Database|App\\\\Models|WalletType|DB::/',
            $calculatorSource,
            'CashbackCalculator must be a pure function with zero DB or wallet dependencies.',
        );
    }

    public function test_single_writer_ownership_for_accruals_claims_and_login_events(): void
    {
        $ownedModels = [
            'CashbackAccrual' => 'app/Services/Cashback/CashbackAccrualService.php',
            'RewardClaim' => 'app/Services/Rewards/RewardRedemptionService.php',
            'LoginEvent' => 'app/Services/Security/LoginHistory/LoginEventRecorder.php',
        ];
        $ownedTables = [
            'cashback_accruals' => 'app/Services/Cashback/CashbackAccrualService.php',
            'reward_claims' => 'app/Services/Rewards/RewardRedemptionService.php',
            'login_events' => 'app/Services/Security/LoginHistory/LoginEventRecorder.php',
        ];
        $writeMethods = ['create', 'insert', 'upsert', 'firstorcreate', 'updateorcreate', 'forcecreate'];

        foreach ($this->batch1PhpFiles() as $file) {
            $normalized = str_replace('\\', '/', $file);
            if (str_contains($normalized, '/tests/')) {
                continue;
            }

            $tokens = $this->significantTokens((string) file_get_contents($file));
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                $tok = $tokens[$i];

                // Token scan: Model::create(...) or Model::query()->create(...)
                if (isset($ownedModels[$tok->text]) && ! str_ends_with($normalized, $ownedModels[$tok->text])) {
                    if (
                        isset($tokens[$i + 1], $tokens[$i + 2])
                        && $tokens[$i + 1]->id === T_DOUBLE_COLON
                        && in_array(strtolower($tokens[$i + 2]->text), $writeMethods, true)
                    ) {
                        $this->fail("Only {$ownedModels[$tok->text]} may call {$tok->text}::{$tokens[$i + 2]->text}() ({$file}:{$tok->line})");
                    }

                    if (
                        isset($tokens[$i + 1], $tokens[$i + 2], $tokens[$i + 3], $tokens[$i + 4], $tokens[$i + 5], $tokens[$i + 6])
                        && $tokens[$i + 1]->id === T_DOUBLE_COLON
                        && strtolower($tokens[$i + 2]->text) === 'query'
                        && $tokens[$i + 3]->text === '('
                        && $tokens[$i + 4]->text === ')'
                        && $tokens[$i + 5]->id === T_OBJECT_OPERATOR
                        && in_array(strtolower($tokens[$i + 6]->text), $writeMethods, true)
                    ) {
                        $this->fail("Only {$ownedModels[$tok->text]} may call {$tok->text}::query()->{$tokens[$i + 6]->text}() ({$file}:{$tok->line})");
                    }
                }

                // Token scan: DB::table('cashback_accruals'|'reward_claims'|'login_events')->insert|upsert|update
                if (
                    $tok->text === 'DB'
                    && isset($tokens[$i + 1], $tokens[$i + 2], $tokens[$i + 3], $tokens[$i + 4], $tokens[$i + 6], $tokens[$i + 7])
                    && $tokens[$i + 1]->id === T_DOUBLE_COLON
                    && strtolower($tokens[$i + 2]->text) === 'table'
                    && $tokens[$i + 3]->text === '('
                ) {
                    $tableName = trim($tokens[$i + 4]->text, "'\"");
                    if (
                        isset($ownedTables[$tableName])
                        && ! str_ends_with($normalized, $ownedTables[$tableName])
                        && $tokens[$i + 6]->id === T_OBJECT_OPERATOR
                        && in_array(strtolower($tokens[$i + 7]->text), ['insert', 'upsert', 'update'], true)
                    ) {
                        $this->fail("Only {$ownedTables[$tableName]} may write to DB::table('{$tableName}') ({$file}:{$tok->line})");
                    }
                }
            }
        }

        $this->addToAssertionCount(count($ownedModels));
    }

    public function test_filament_resources_register_pages_and_deny_unauthenticated_access(): void
    {
        $this->assertFalse(CashbackPolicyResource::canViewAny());
        $this->assertFalse(RewardCatalogItemResource::canViewAny());
        $this->assertFalse(MemberBankAccountResource::canViewAny());

        $this->assertArrayHasKey('index', CashbackPolicyResource::getPages());
        $this->assertArrayHasKey('index', RewardCatalogItemResource::getPages());
        $this->assertArrayHasKey('index', MemberBankAccountResource::getPages());
        $this->assertArrayHasKey('view', MemberBankAccountResource::getPages());
        $this->assertFalse(\App\Filament\Resources\RewardClaimResource::canViewAny());
        $this->assertArrayHasKey('index', \App\Filament\Resources\RewardClaimResource::getPages());

        $bankResourceSource = (string) file_get_contents(base_path('app/Filament/Resources/MemberBankAccountResource.php'));
        $this->assertDoesNotMatchRegularExpression('/account_number_encrypted/', $bankResourceSource);
        $this->assertStringContainsString('masked_number', $bankResourceSource);
    }

    public function test_dashboard_cache_key_and_forget_have_a_single_owner(): void
    {
        $allowed = [
            'app/Services/Home/DashboardCacheInvalidator.php',
            'app/Services/Home/PlayerDashboardSummaryService.php',
        ];

        foreach ($this->batch1PhpFiles() as $file) {
            $normalized = str_replace('\\', '/', $file);
            if (str_contains($normalized, '/tests/') || str_ends_with($normalized, 'config/rewards.php')) {
                continue;
            }

            $source = (string) file_get_contents($file);
            $isAllowed = false;
            foreach ($allowed as $owner) {
                $isAllowed = $isAllowed || str_ends_with($normalized, $owner);
            }

            if (! $isAllowed) {
                $this->assertStringNotContainsString(
                    'rewards.dashboard.cache_prefix',
                    $source,
                    "Dashboard cache key must only be built by DashboardCacheInvalidator ({$file}).",
                );
            }
        }
    }
}
