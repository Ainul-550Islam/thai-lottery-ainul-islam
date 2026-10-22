<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\WalletService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Payment\ProductionPaymentExecutionHubService;
use App\Services\Security\KycVerificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Feature\Finance\FinancialMasterTestCase;

/** Manual instructions/pending responses must not be presented as executed money. */
final class EnterprisePaymentBoundaryTest extends FinancialMasterTestCase
{
    public function test_manual_facade_initiation_and_replay_credit_nothing_and_have_no_redirect(): void
    {
        // Explicit non-payable test configuration, never a real settlement account.
        Config::set('payment.deposit.allowed_methods', ['bank_transfer']);
        Config::set('payment.gateways.bank_transfer.enabled', true);
        Config::set('payment.gateways.bank_transfer.supports_deposit', true);
        Config::set('payment.gateways.bank_transfer.settlement', [
            'bank_name' => 'TEST ONLY', 'account_number' => 'TEST-NOT-PAYABLE', 'account_name' => 'TEST ONLY',
        ]);
        $user = User::factory()->create();
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::THB);
        $hub = app(ProductionPaymentExecutionHubService::class);
        $input = ['amount' => '2500.00', 'channel' => 'bank_transfer', 'currency' => 'THB'];
        $first = $hub->initiateDeposit($user, $input, 'enterprise-manual-initiation');
        $replay = $hub->initiateDeposit($user, $input, 'enterprise-manual-initiation');
        $this->assertSame('pending', $first['status']);
        $this->assertNull($first['redirect_url']);
        $this->assertSame($first['payment_id'], $replay['payment_id']);
        $this->assertSame($first['reference_id'], $replay['reference_id']);
        $this->assertSame(1, Deposit::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame('0.00', (string) $wallet->fresh()->balance);
        $this->assertSame(0, FinancialTransaction::count());
        Http::assertNothingSent();
    }

    public function test_bank_facade_pending_disbursement_does_not_complete_or_debit(): void
    {
        Config::set('payment.gateways.bank_transfer.enabled', true);
        Config::set('payment.gateways.bank_transfer.supports_withdrawal', true);
        $user = User::factory()->create();
        $operator = User::factory()->create();
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::THB);
        $this->creditFixture($wallet, '3000.00', 'Enterprise pending withdrawal fixture');
        $withdrawal = $this->pendingWithdrawalForMaster($wallet, '1000.00');
        app(WithdrawalApprovalService::class)->approve($withdrawal, $operator->id);
        $before = FinancialTransaction::count();
        $response = app(ProductionPaymentExecutionHubService::class)->disburseWithdrawal($withdrawal->fresh(), $operator);
        $this->assertTrue($response->successful);
        $this->assertTrue($response->isPending);
        $this->assertSame(WithdrawalStatus::Processing, $withdrawal->fresh()->status);
        $this->assertNull($withdrawal->fresh()->completed_at);
        $this->assertSame('3000.00', (string) $wallet->fresh()->balance);
        $this->assertSame('1000.00', (string) $wallet->fresh()->locked_balance);
        $this->assertSame($before, FinancialTransaction::count());
        Http::assertNothingSent();
    }

    public function test_private_identity_document_cannot_be_self_reviewed(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $service = app(KycVerificationService::class);
        $upload = UploadedFile::fake()->createWithContent('identity.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j7n8AAAAASUVORK5CYII='));
        $document = $service->submitDocument($user, KycDocumentType::NationalId, $upload);
        try {
            $service->reviewDocument($document, $user, true);
            $this->fail('Self-review of identity evidence must be refused.');
        } catch (InvalidArgumentException $error) {
            $this->assertSame('Self-review of a KYC submission is forbidden.', $error->getMessage());
        }
        $this->assertSame(KycStatus::Pending, $document->fresh()->status);
        $this->assertNull($document->fresh()->verified_by);
        $this->assertFalse($user->fresh()->kycStatus()->isVerified());
        $this->assertSame(0, FinancialTransaction::count());
    }

    public function test_facade_respects_deposit_method_allowlist_before_creating_an_intent(): void
    {
        Config::set('payment.deposit.allowed_methods', ['stripe']);
        $user = User::factory()->create();
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::THB);
        try {
            app(ProductionPaymentExecutionHubService::class)->initiateDeposit($user,
                ['amount' => '100.00', 'channel' => 'bank_transfer', 'currency' => 'THB'], 'enterprise-disallowed-method');
            $this->fail('Registered drivers must not bypass the configured deposit allowlist.');
        } catch (FinancialException $error) {
            $this->assertSame('payment_method_not_allowed', $error->errorCode());
        }
        $this->assertSame(0, Deposit::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, FinancialTransaction::count());
        $this->assertSame('0.00', (string) $wallet->fresh()->balance);
    }
}
