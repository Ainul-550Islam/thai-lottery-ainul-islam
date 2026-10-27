<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Models\Withdrawal;
use App\Services\Payment\Drivers\ThaiBankPayoutGateway;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\WithdrawalDisbursementService;

final class PromptPayWithdrawalRoutingTest extends Batch01TestCase
{
    public function test_promptpay_withdrawal_resolves_to_the_scb_thai_bank_rail(): void
    {
        $this->configureScbPromptPayLane();
        $gateways = app(PaymentGatewayManager::class);

        self::assertTrue($gateways->isWithdrawalCapable(PaymentMethod::PromptPay));
        self::assertInstanceOf(ThaiBankPayoutGateway::class, $gateways->withdrawalDriver(PaymentMethod::PromptPay));

        $withdrawal = new Withdrawal;
        $withdrawal->method = PaymentMethod::PromptPay;
        $withdrawal->reference_number = 'WD-PROMPTPAY-TEST-01';
        $withdrawal->amount = '250.00';
        $withdrawal->currency = Currency::THB;
        $withdrawal->payout_details = [
            'destination_type' => 'promptpay_mobile',
            'mobile' => '0812345678',
            'account_name' => 'Test Recipient',
        ];

        self::assertSame('thai_bank', app(WithdrawalDisbursementService::class)->resolveGatewayKey($withdrawal));
    }

    private function configureScbPromptPayLane(): void
    {
        config([
            'payment.currency.default' => 'THB',
            'payment.country' => 'TH',
            'payment.markets.promptpay' => ['currencies' => ['THB'], 'countries' => ['TH']],
            'payment.markets.thai_bank' => ['currencies' => ['THB'], 'countries' => ['TH']],
            'payment.withdrawal_routing.prefer_automated_payout' => true,
            'thai_payout.enabled' => true,
            'thai_payout.default_bank' => 'scb',
            'thai_payout.promptpay.enabled' => true,
            'thai_payout.promptpay.proxy_types' => ['mobile', 'national_id'],
            'thai_payout.promptpay.banks.scb' => true,
            'thai_payout.banks.scb' => [
                'enabled' => true, 'contract_confirmed' => true,
                'base_url' => 'https://scb.example.test', 'token_path' => '/oauth/token',
                'transfer_path' => '/transfers', 'status_path' => '/transfers/{reference}',
                'status_method' => 'GET', 'client_id' => 'test-client', 'client_secret' => 'test-secret',
                'resource_owner_id' => 'test-owner', 'webhook_secret' => 'test-webhook-secret',
                'source_account' => '1234567890', 'timeout_seconds' => 10,
            ],
        ]);
    }
}
