<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Tests\Feature\Payment\PaymentTestCase;

final class ThaiRailsPreflightCommandTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'truemoney.enabled' => false,
            'slip_verification.enabled' => false,
            'thai_payout.enabled' => false,
        ]);
    }

    public function test_disabled_lanes_are_skipped_and_exit_successfully_without_secrets(): void
    {
        $this->artisan('thai-rails:preflight')
            ->expectsOutput('PASS [truemoney] disabled (skipped)')
            ->expectsOutput('PASS [slip_verification] disabled (skipped)')
            ->expectsOutput('PASS [thai_payout] disabled (skipped)')
            ->assertExitCode(0);
    }

    public function test_enabled_but_incomplete_lanes_fail_closed(): void
    {
        config([
            'truemoney.enabled' => true,
            'slip_verification.enabled' => true,
            'thai_payout.enabled' => true,
            'thai_payout.default_bank' => 'scb',
            'thai_payout.banks.scb.enabled' => true,
        ]);

        $this->artisan('thai-rails:preflight')
            ->expectsOutput('FAIL [truemoney] acknowledgement, HTTPS endpoint and merchant configuration required')
            ->expectsOutput('FAIL [slip_verification] selected provider credentials and receiving accounts required')
            ->expectsOutput('FAIL [thai_payout] bank credentials, confirmed request/response contract, and secure endpoints required')
            ->assertExitCode(1);
    }

    public function test_slip_lane_rejects_credentials_targeted_at_a_non_provider_host(): void
    {
        config([
            'slip_verification.enabled' => true,
            'slip_verification.provider' => 'easyslip',
            'slip_verification.receiving_accounts' => [[
                'bank' => 'SCB', 'account_last_digits' => '1234', 'name' => 'Example Receiver',
            ]],
            'slip_verification.providers.easyslip.base_url' => 'https://untrusted.example/v2',
            'slip_verification.providers.easyslip.token' => 'secret-easyslip-token',
        ]);

        $this->artisan('thai-rails:preflight')
            ->expectsOutput('FAIL [slip_verification] selected provider credentials and receiving accounts required')
            ->assertExitCode(1);
    }

    public function test_slip_preflight_rejects_receiver_suffixes_longer_than_provider_masks_expose(): void
    {
        config([
            'slip_verification.enabled' => true,
            'slip_verification.provider' => 'easyslip',
            'slip_verification.receiving_accounts' => [[
                'bank' => 'SCB', 'account_last_digits' => '001234', 'name' => 'Example Receiver',
            ]],
            'slip_verification.providers.easyslip.base_url' => 'https://api.easyslip.com/v2',
            'slip_verification.providers.easyslip.verify_path' => '/verify/bank',
            'slip_verification.providers.easyslip.token' => 'configured-token',
            'slip_verification.providers.easyslip.timeout' => 5,
        ]);

        $this->artisan('thai-rails:preflight')
            ->expectsOutput('FAIL [slip_verification] selected provider credentials and receiving accounts required')
            ->assertExitCode(1);
    }

    public function test_complete_configuration_passes_each_selected_lane_without_printing_secrets(): void
    {
        config([
            'truemoney.enabled' => true,
            'truemoney.acknowledged_unofficial_api' => true,
            'truemoney.merchant_mobile' => '+66812345678',
            'truemoney.base_url' => 'https://gift.truemoney.com',
            'truemoney.allowed_host' => 'gift.truemoney.com',
            'truemoney.redeem_path' => '/campaign/vouchers/{voucher_code}/redeem',
            'truemoney.timeout_seconds' => 5,
            'slip_verification.enabled' => true,
            'slip_verification.provider' => 'easyslip',
            'slip_verification.receiving_accounts' => [[
                'bank' => 'SCB', 'account_last_digits' => '1234', 'name' => 'Example Receiver',
            ]],
            'slip_verification.providers.easyslip.base_url' => 'https://api.easyslip.com',
            'slip_verification.providers.easyslip.token' => 'secret-easyslip-token',
            'slip_verification.providers.easyslip.timeout' => 5,
            'thai_payout.enabled' => true,
            'thai_payout.default_bank' => 'scb',
            'thai_payout.banks.scb.enabled' => true,
            'thai_payout.banks.scb.contract_confirmed' => true,
            'thai_payout.banks.scb.status_method' => 'GET',
            'thai_payout.banks.scb.base_url' => 'https://api.scb.test',
            'thai_payout.banks.scb.client_id' => 'secret-scb-client-id',
            'thai_payout.banks.scb.client_secret' => 'secret-scb-client-secret',
            'thai_payout.banks.scb.resource_owner_id' => 'partner-owner-id',
            'thai_payout.banks.scb.webhook_secret' => 'secret-scb-webhook',
            'thai_payout.banks.scb.source_account' => 'treasury-account',
            'thai_payout.banks.scb.token_path' => '/oauth/token',
            'thai_payout.banks.scb.transfer_path' => '/partner/transfer',
            'thai_payout.banks.scb.status_path' => '/partner/status/{reference}',
            'thai_payout.banks.scb.timeout_seconds' => 5,
        ]);

        $exitCode = \Illuminate\Support\Facades\Artisan::call('thai-rails:preflight');
        $output = \Illuminate\Support\Facades\Artisan::output();
        self::assertSame(0, $exitCode);
        self::assertStringContainsString('PASS [truemoney] configuration complete', $output);
        self::assertStringContainsString('PASS [slip_verification] configuration complete', $output);
        self::assertStringContainsString('PASS [thai_payout] configuration complete', $output);
        self::assertStringNotContainsString('secret-easyslip-token', $output);
        self::assertStringNotContainsString('secret-scb-client-secret', $output);
        self::assertStringNotContainsString('secret-scb-webhook', $output);

        config(['thai_payout.banks.kbank.enabled' => true]);
        $secondaryBankExitCode = \Illuminate\Support\Facades\Artisan::call('thai-rails:preflight');
        $secondaryBankOutput = \Illuminate\Support\Facades\Artisan::output();
        self::assertSame(1, $secondaryBankExitCode);
        self::assertStringContainsString('FAIL [thai_payout] bank credentials, confirmed request/response contract, and secure endpoints required', $secondaryBankOutput);
    }
}
