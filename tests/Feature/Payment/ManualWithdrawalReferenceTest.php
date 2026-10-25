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
use App\Services\Payment\WithdrawalDisbursementService;
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

    #[DataProvider('manualRails')]
    public function test_manual_pending_has_no_fabricated_reference_and_stays_in_evidence_queue(string $method, string $currency): void
    {
        Http::preventStrayRequests();
        config(['payment.gateways.bank_transfer.enabled' => true]);
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
