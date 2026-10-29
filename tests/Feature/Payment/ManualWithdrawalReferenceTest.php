<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Services\Finance\Money;
use App\Services\Finance\WalletHoldService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\WithdrawalDisbursementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

final class ManualWithdrawalReferenceTest extends PaymentTestCase
{
    /** @return array<string, array{string, string}> */
    public static function manualRails(): array
    {
        return [
            'bKash' => ['bkash', 'BDT'],
            'Nagad' => ['nagad', 'BDT'],
            'bank transfer' => ['bank_transfer', 'THB'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function unverifiedBdtRails(): array
    {
        return ['bKash' => ['bkash'], 'Nagad' => ['nagad']];
    }

    #[DataProvider('unverifiedBdtRails')]
    public function test_existing_bdt_obligation_is_queued_without_calling_the_unverified_driver(string $method): void
    {
        Http::preventStrayRequests();
        config(['payment.currency.default' => 'BDT', 'payment.country' => 'BD']);
        $player = $this->createPlayer('1000.00', Currency::BDT);
        $row = app(WithdrawalService::class)->request($player['wallet'], Money::of('400.00', Currency::BDT),
            PaymentMethod::from($method), options: ['payout_details' => ['wallet_number' => 'NOT-PAYABLE']]);
        app(WithdrawalApprovalService::class)->approve($row);

        $driver = $this->createMock(PaymentGatewayInterface::class);
        $driver->method('name')->willReturn($method);
        $driver->method('supportsCurrency')->willReturn(true);
        $driver->expects($this->never())->method('initiateWithdrawal');
        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->expects($this->once())->method('driver')->with($method)->willReturn($driver);
        $manager->expects($this->never())->method('withdrawalDriver');
        $this->app->instance(PaymentGatewayManager::class, $manager);

        $response = app(WithdrawalDisbursementService::class)->disburse($row->fresh());
        self::assertTrue($response->isPending);
        self::assertTrue($response->successful);
        self::assertNull($response->providerReference);
        self::assertTrue((bool) ($response->metadata['manual_disbursement_required'] ?? false));
        self::assertSame('1000.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame('400.00', (string) $player['wallet']->fresh()->locked_balance);
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(1, DB::table('manual_disbursement_tasks')->where('withdrawal_id', $row->id)->count());
        Http::assertNothingSent();
    }

    #[DataProvider('manualRails')]
    public function test_manual_pending_has_no_fabricated_reference_and_stays_in_evidence_queue(string $method, string $currency): void
    {
        Http::preventStrayRequests();
        config(['payment.gateways.bank_transfer.enabled' => true]);
        // The BDT cases exercise a Bangladesh merchant market, not the
        // default THB deployment. These are synthetic values; no HTTP is sent.
        if ($currency === 'BDT') {
            config([
                'payment.currency.default' => 'BDT',
                'payment.country' => 'BD',
                'payment.gateways.nagad.merchant_number' => 'test-merchant-number',
                'payment.gateways.nagad.app_key' => 'test-app-key',
                'payment.gateways.nagad.public_key' => 'test-public-key',
                'payment.gateways.nagad.private_key' => 'test-private-key',
                'payment.gateways.nagad.base_url' => 'https://nagad.example.test',
            ]);
        }
        $method = PaymentMethod::from($method);
        $currency = Currency::from($currency);
        $player = $this->createPlayer('1000.00', $currency);
        $details = $method === PaymentMethod::BankTransfer
            ? ['bank_name' => 'TEST ONLY', 'account_number' => 'NOT-PAYABLE', 'account_name' => 'TEST ONLY']
            : ['wallet_number' => 'NOT-PAYABLE'];
        $row = app(WithdrawalService::class)->request(
            $player['wallet'],
            Money::of('400.00', $currency),
            $method,
            options: ['payout_details' => $details],
        );
        app(WithdrawalApprovalService::class)->approve($row);

        $response = app(WithdrawalDisbursementService::class)->disburse($row->fresh());

        $this->assertTrue($response->successful);
        $this->assertTrue($response->isPending);
        $this->assertNull($response->providerReference);
        $this->assertTrue($response->metadata['manual_disbursement_required']);
        $fresh = $row->fresh();
        $this->assertSame(WithdrawalStatus::Processing, $fresh->status);
        $this->assertNull($fresh->provider_reference);
        $this->assertTrue($fresh->metadata['manual_disbursement_required']);
        $this->assertTrue($fresh->metadata['reconciliation_required']);
        $this->assertSame('withdrawal_reconciliation', $fresh->metadata['operator_payout_queue']);
        $this->assertSame('reconciliation_required', $fresh->metadata[WithdrawalDisbursementService::CLAIM]['state']);
        $this->assertSame($method === PaymentMethod::BankTransfer, ($fresh->metadata['manual_bank_wire'] ?? false) === true);
        $this->assertSame($method !== PaymentMethod::BankTransfer, ($fresh->metadata['requires_b2c_disbursement'] ?? false) === true);

        $payment = Payment::query()->where('payable_type', $fresh::class)->where('payable_id', $fresh->id)->sole();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertNull($payment->gateway_reference);
        $this->assertSame('1000.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame('400.00', (string) $player['wallet']->fresh()->locked_balance);
        $this->assertTrue(app(WalletHoldService::class)->hasHoldOfAtLeast($player['wallet']->fresh(), Money::of('400.00', $currency)));
        $this->assertSame(0, FinancialTransaction::query()->count());
        Http::assertNothingSent();

        try {
            app(WithdrawalCompletionService::class)->complete($fresh);
            $this->fail('A manual-pending withdrawal completed without provider evidence.');
        } catch (FinancialException $exception) {
            $this->assertSame('withdrawal_original_evidence_conflict', $exception->errorCode());
        }
        $this->assertSame(WithdrawalStatus::Processing, $fresh->fresh()->status);
        $this->assertSame('400.00', (string) $player['wallet']->fresh()->locked_balance);
        $this->assertTrue(app(WalletHoldService::class)->hasHoldOfAtLeast($player['wallet']->fresh(), Money::of('400.00', $currency)));
        $this->assertSame(0, FinancialTransaction::query()->count());
    }
}
