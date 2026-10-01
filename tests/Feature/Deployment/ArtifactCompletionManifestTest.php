<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use App\Http\Controllers\Web\BetPurchaseController;
use App\Http\Controllers\Web\PlayerWebController;
use App\Http\Requests\Web\UpdateResponsibleGamingLimitsRequest;
use App\Services\Lottery\GloL6ProportionalPrizeCalculator;
use App\Services\Lottery\GloL6SalesService;
use App\Services\Lottery\GloPrizeClaimService;
use App\Services\Lottery\GloStampDutyCalculator;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payments\PublicPaymentMethodsService;
use App\Services\ResponsibleGaming\ResponsibleGamingEnforcementService;
use App\Services\ResponsibleGaming\ResponsibleGamingLimitService;
use App\Services\Security\ResponsibleGamingService;
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
            \App\Models\GloL6Ticket::class,
            \App\Models\GloPrizeClaim::class,
            \App\Services\Lottery\GloResultPublicationService::class,
            \App\Models\WalletLedger::class,
            \App\Models\Transaction::class,
            \App\Services\Betting\BulkBetService::class,
            \App\Services\Payment\WithdrawalDisbursementService::class,
            \App\Services\Finance\FinancialReconciliationService::class,
            \App\Services\Lottery\ResultImportService::class,
            \App\Services\Lottery\NationalLotteryService::class,
            \App\Services\Lottery\WeeklyLotteryService::class,
            \App\Services\Lottery\BingoLotteryService::class,
            \App\Services\Lottery\PcsoLotteryService::class,
            \App\Http\Controllers\Admin\AdminController::class,
            \App\Services\Admin\AdminOperationService::class,
            \App\Services\Compliance\ComplianceService::class,
            \App\Services\Audit\AuditLogService::class,
            \App\Services\Agent\AgentCommissionService::class,
            \App\Http\Requests\Web\RegisterRequest::class,
            \App\Http\Requests\Web\AccountVerificationRequest::class,
            \App\Http\Controllers\Web\MemberAuthController::class,
            \App\Http\Controllers\ResultsController::class,
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
        $this->assertFalse((bool) config('national_lottery.sources.fixture.enabled', true));
        $this->assertFalse((bool) config('weekly_lottery.sources.fixture.enabled', true));
        $this->assertFalse((bool) config('bingo_lottery.sources.fixture.enabled', true));
        $this->assertFalse((bool) config('pcso_lottery.sources.fixture.enabled', true));
    }
}
