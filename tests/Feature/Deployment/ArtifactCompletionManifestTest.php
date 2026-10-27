<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\Web\BetPurchaseController;
use App\Http\Controllers\Web\MemberAuthController;
use App\Http\Controllers\Web\PlayerWebController;
use App\Http\Requests\Web\AccountVerificationRequest;
use App\Http\Requests\Web\RegisterRequest;
use App\Http\Requests\Web\UpdateResponsibleGamingLimitsRequest;
use App\Models\GloL6Ticket;
use App\Models\GloPrizeClaim;
use App\Models\Transaction;
use App\Models\WalletLedger;
use App\Services\Admin\AdminOperationService;
use App\Services\Agent\AgentCommissionService;
use App\Services\Audit\AuditLogService;
use App\Services\Betting\BulkBetService;
use App\Services\Compliance\ComplianceService;
use App\Services\Finance\FinancialReconciliationService;
use App\Services\Lottery\BingoLotteryService;
use App\Services\Lottery\GloL6ProportionalPrizeCalculator;
use App\Services\Lottery\GloL6SalesService;
use App\Services\Lottery\GloPrizeClaimService;
use App\Services\Lottery\GloResultPublicationService;
use App\Services\Lottery\GloStampDutyCalculator;
use App\Services\Lottery\NationalLotteryService;
use App\Services\Lottery\PcsoLotteryService;
use App\Services\Lottery\ResultImportService;
use App\Services\Lottery\WeeklyLotteryService;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\WithdrawalDisbursementService;
use App\Services\Payments\PublicPaymentMethodsService;
use App\Services\ResponsibleGaming\ResponsibleGamingEnforcementService;
use App\Services\ResponsibleGaming\ResponsibleGamingLimitService;
use App\Services\Security\ResponsibleGamingService;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P0-02 / P0-04: Automated Completion Manifest Test.
 *
 * Ensures every file, controller, request, route, and configuration
 * declared in the completion report is physically present and verified
 * in the release artifact.
 */
final class ArtifactCompletionManifestTest extends TestCase
{
    public function test_all_required_implementation_classes_exist_and_resolve(): void
    {
        $classes = [
            BetPurchaseController::class,
            PlayerWebController::class,
            UpdateResponsibleGamingLimitsRequest::class,
            PublicPaymentMethodsService::class,
            PaymentGatewayManager::class,
            ResponsibleGamingService::class,
            ResponsibleGamingLimitService::class,
            ResponsibleGamingEnforcementService::class,
            GloPrizeClaimService::class,
            GloStampDutyCalculator::class,
            GloL6SalesService::class,
            GloL6ProportionalPrizeCalculator::class,
            GloL6Ticket::class,
            GloPrizeClaim::class,
            GloResultPublicationService::class,
            WalletLedger::class,
            Transaction::class,
            BulkBetService::class,
            WithdrawalDisbursementService::class,
            FinancialReconciliationService::class,
            ResultImportService::class,
            NationalLotteryService::class,
            WeeklyLotteryService::class,
            BingoLotteryService::class,
            PcsoLotteryService::class,
            AdminController::class,
            AdminOperationService::class,
            ComplianceService::class,
            AuditLogService::class,
            AgentCommissionService::class,
            RegisterRequest::class,
            AccountVerificationRequest::class,
            MemberAuthController::class,
            ResultsController::class,
        ];

        foreach ($classes as $class) {
            $this->assertTrue(class_exists($class), "Class {$class} must exist in the artifact.");
        }
    }

    public function test_all_required_web_routes_are_registered(): void
    {
        $routes = [
            'player.bets.purchase',
            'player.limits.update',
            'player.password.update',
            'player.profile.update',
            'locale.switch',
            'sitemap',
            'results.index',
            'ticket-check',
            'sales-points',
        ];

        foreach ($routes as $routeName) {
            $this->assertNotNull(Route::getRoutes()->getByName($routeName), "Named route '{$routeName}' must be registered.");
        }
    }

    public function test_all_required_feature_tests_exist(): void
    {
        $testFiles = [
            'tests/Feature/Player/ResponsibleGamingWebTest.php',
            'tests/Feature/Player/BetPurchaseWebTest.php',
            'tests/Feature/Payment/PublicPaymentCapabilityParityTest.php',
            'tests/Feature/Payment/WithdrawalDestinationValidationTest.php',
            'tests/Feature/Payment/FixturePublicationSafetyTest.php',
            'tests/Feature/SEO/SitemapPublicationFilterTest.php',
            'tests/Feature/Glo/GloPrizeClaimTest.php',
            'tests/Feature/Glo/GloL6ProportionalCalculatorTest.php',
            'tests/Feature/RealMoneyBusinessReadinessTest.php',
        ];

        foreach ($testFiles as $filePath) {
            $this->assertFileExists(base_path($filePath), "Regression test file {$filePath} must be present.");
        }
    }

    public function test_pwa_and_release_artifacts_exist(): void
    {
        $this->assertFileExists(public_path('manifest.webmanifest'), 'PWA webmanifest must exist.');
        $this->assertFileExists(public_path('sw.js'), 'PWA service worker must exist.');
        $this->assertFileExists(public_path('build/manifest.json'), 'Vite production build manifest must exist.');
    }

    public function test_glo_configuration_parameters(): void
    {
        $this->assertSame('80.00', (string) config('glo.l6.ticket_price'));
        $this->assertSame('48000000.00', (string) config('glo.l6.full_allocation'));
        $this->assertSame(14168, (int) config('glo.l6.total_prize_count'));
        $this->assertFalse((bool) config('glo.ticket.sold_in_pairs', true));
    }

    public function test_fixture_configurations_default_to_false(): void
    {
        foreach ([
            ['NATIONAL_LOTTERY_FIXTURE_ENABLED', 'national_lottery.php'],
            ['WEEKLY_LOTTERY_FIXTURE_ENABLED', 'weekly_lottery.php'],
            ['BINGO_LOTTERY_FIXTURE_ENABLED', 'bingo_lottery.php'],
            ['PCSO_LOTTERY_FIXTURE_ENABLED', 'pcso_lottery.php'],
        ] as [$envName, $configFile]) {
            $this->assertFalse(
                (bool) $this->configuredDefault($envName, $configFile, 'sources.fixture.enabled'),
                $configFile.' enables its fixture source by default, so a stock deployment could publish fabricated results.',
            );
        }
    }

    /**
     * Resolve a config value with its environment variable removed.
     *
     * These assertions are about the configured DEFAULT - what a stock
     * deployment gets when it copies .env.example and runs - not about
     * whatever the current process exports. phpunit.xml enables the fixture
     * lanes on purpose, because the four lane suites need a source to read,
     * so asking config() directly only reports that override back.
     *
     * The variable has to be cleared from all three places a value can hide.
     * PHPUnit writes its <env> entries through putenv() and into $_ENV, and
     * Laravel's environment repository does not own either of those, so
     * Env::getRepository()->clear() alone leaves getenv() still answering.
     * All three are cleared, the config file is re-evaluated, and every
     * original value is put back.
     */
    private function configuredDefault(string $envName, string $configFile, string $key): mixed
    {
        $repository = Env::getRepository();

        $originalRepository = $repository->get($envName);
        $originalPutenv = getenv($envName);
        $hadEnv = array_key_exists($envName, $_ENV);
        $hadServer = array_key_exists($envName, $_SERVER);
        $originalEnv = $_ENV[$envName] ?? null;
        $originalServer = $_SERVER[$envName] ?? null;

        $repository->clear($envName);
        putenv($envName);
        unset($_ENV[$envName], $_SERVER[$envName]);

        try {
            /** @var array<string, mixed> $fresh */
            $fresh = require config_path($configFile);

            return data_get($fresh, $key);
        } finally {
            if ($originalPutenv !== false) {
                putenv($envName.'='.$originalPutenv);
            }

            if ($hadEnv) {
                $_ENV[$envName] = $originalEnv;
            }

            if ($hadServer) {
                $_SERVER[$envName] = $originalServer;
            }

            if ($originalRepository !== null) {
                $repository->set($envName, $originalRepository);
            }
        }
    }
}
