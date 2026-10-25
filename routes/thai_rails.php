<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Payment\SlipVerificationController;
use App\Http\Controllers\Api\V1\Payment\ThaiBankPayoutWebhookController;
use App\Http\Controllers\Api\V1\Payment\TruemoneyVoucherController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    Route::post('v1/deposit/truemoney/redeem', [TruemoneyVoucherController::class, 'redeem'])
        ->middleware(['throttle:deposit', 'adult', 'legal.current'])
        ->name('api.v1.deposit.truemoney.redeem');

    Route::post('v1/deposit/slip-verification', [SlipVerificationController::class, 'store'])
        ->middleware(['throttle:deposit', 'adult', 'legal.current'])
        ->name('api.v1.deposit.slip.verify');

    Route::get('v1/deposit/slip-verification/{verification}', [SlipVerificationController::class, 'show'])
        ->name('api.v1.deposit.slip.show');
});

Route::post('v1/payments/payout-webhook/scb', [ThaiBankPayoutWebhookController::class, 'handle'])
    ->defaults('bank', 'scb')
    ->middleware(['throttle:webhook', 'webhook.signature:scb'])
    ->name('api.v1.payments.payout-webhook.scb');

Route::post('v1/payments/payout-webhook/kbank', [ThaiBankPayoutWebhookController::class, 'handle'])
    ->defaults('bank', 'kbank')
    ->middleware(['throttle:webhook', 'webhook.signature:kbank'])
    ->name('api.v1.payments.payout-webhook.kbank');
