<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\DrawResultIngestionController;
use App\Http\Controllers\Api\V1\Admin\Security\BlockedIpController;
use App\Http\Controllers\Api\V1\Admin\Security\FraudAlertController;
use App\Http\Controllers\Api\V1\Admin\Security\MalwareReviewController;
use App\Http\Controllers\Api\V1\Admin\Security\SecurityOverviewController;
use App\Http\Controllers\Api\V1\AgentNetworkController;
use App\Http\Controllers\Api\V1\DepositWebhookController;
use App\Http\Controllers\Api\V1\MetricsController;
use App\Http\Controllers\Api\V1\OrderPlacementController;
use App\Http\Controllers\Api\V1\Payment\KBankWebhookController;
use App\Http\Controllers\Api\V1\Payment\PromptPayController;
use App\Http\Controllers\Api\V1\Payment\ScbWebhookController;
use App\Http\Controllers\Api\V1\WithdrawalController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // 1. Public & Operational Health / Prometheus Metrics
    Route::middleware(['api', 'rate.sliding'])->group(function () {
        Route::get('/health', fn() => response()->json(['status' => 'UP', 'timestamp' => now()->toIso8601String()]));
        Route::get('/metrics', [MetricsController::class, 'index'])->name('api.v1.metrics');
    });

    // 2. Thailand Payment Gateway Webhooks (Replay Deduplication + IP Whitelisting)
    Route::prefix('webhooks/payment')->group(function () {
        Route::post('/scb', [ScbWebhookController::class, 'handle'])->name('api.v1.webhooks.scb');
        Route::post('/kbank', [KBankWebhookController::class, 'handle'])->name('api.v1.webhooks.kbank');
        Route::post('/generic', [DepositWebhookController::class, 'handle'])->name('api.v1.webhooks.deposit');
    });

    // 3. Authenticated Financial Routes (User / Agent)
    Route::middleware([
        'auth:sanctum',
        'rate.sliding',
        'bot.detect',
        'security.ip_blocklist',
    ])->group(function () {
        // Dynamic PromptPay QR Code Generation
        Route::post('/payments/promptpay/generate', [PromptPayController::class, 'generate'])->name('api.v1.payments.promptpay.generate');

        // Order Placement with Redlock Idempotency & HMAC
        Route::post('/orders/place', [OrderPlacementController::class, 'store'])
            ->middleware(['hmac.verify', 'idempotency.lock'])
            ->name('api.v1.orders.place');

        // Withdrawal Request with Fraud Gate
        Route::post('/withdrawals/request', [WithdrawalController::class, 'store'])
            ->middleware(['fraud.gate'])
            ->name('api.v1.withdrawals.request');

        // Agent Hierarchy & Commission Analytics
        Route::get('/agent/tree', [AgentNetworkController::class, 'tree'])->name('api.v1.agent.tree');
        Route::get('/agent/commissions', [AgentNetworkController::class, 'commissions'])->name('api.v1.agent.commissions');
    });

    // 4. Admin Security Operations Center (SOC) & GLO Boundaries
    Route::prefix('admin')->middleware([
        'auth:sanctum',
        'rate.sliding',
        'security.ip_blocklist',
    ])->group(function () {
        // GLO 4-Eyes Certification Boundary
        Route::post('/draws/results/ingest', [DrawResultIngestionController::class, 'ingest'])->name('api.v1.admin.draws.ingest');
        Route::post('/draws/results/{id}/confirm', [DrawResultIngestionController::class, 'confirm'])->name('api.v1.admin.draws.confirm');

        // Security Operations Center (SOC) APIs
        Route::prefix('security')->group(function () {
            Route::get('/overview', [SecurityOverviewController::class, 'overview'])->name('api.v1.admin.security.overview');

            // IP / CIDR Block Management
            Route::get('/ip-blocks', [BlockedIpController::class, 'index'])->name('api.v1.admin.security.ip-blocks.index');
            Route::post('/ip-blocks', [BlockedIpController::class, 'store'])->name('api.v1.admin.security.ip-blocks.store');
            Route::delete('/ip-blocks/{id}', [BlockedIpController::class, 'unblock'])->name('api.v1.admin.security.ip-blocks.unblock');

            // Fraud Case Management & Maker-Checker Review
            Route::get('/fraud-alerts', [FraudAlertController::class, 'index'])->name('api.v1.admin.security.fraud-alerts.index');
            Route::get('/fraud-alerts/{id}', [FraudAlertController::class, 'show'])->name('api.v1.admin.security.fraud-alerts.show');
            Route::post('/fraud-alerts/{id}/review', [FraudAlertController::class, 'review'])->name('api.v1.admin.security.fraud-alerts.review');

            // Malware & Quarantined Upload Review
            Route::get('/malware-reviews', [MalwareReviewController::class, 'index'])->name('api.v1.admin.security.malware.index');
            Route::post('/malware-reviews/{id}/rescan', [MalwareReviewController::class, 'rescan'])->name('api.v1.admin.security.malware.rescan');
        });
    });
});
