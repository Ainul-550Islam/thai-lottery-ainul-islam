<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentStatus;
use App\Enums\PrizePayoutMethod;
use App\Exceptions\FinancialException;
use App\Exceptions\PayoutTransferException;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Services\Finance\LedgerBalanceValidator;
use App\Services\Payment\PayoutTransferService;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesExternalPayout;

final class PayoutTransferCompletionTest extends PaymentTestCase
{
    use CreatesExternalPayout;

    public static function methods(): array
    {
        return [[PrizePayoutMethod::BankTransfer], [PrizePayoutMethod::Cheque]];
    }

    #[DataProvider('methods')]
    public function test_independent_confirmation_posts_balanced_external_books_without_wallet_credit(PrizePayoutMethod $method): void
    {
        $f = $this->externalFixture(method: $method);
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);
        $r = $service->complete($f['data'], 'SYNTHETIC-SETTLED', $f['reviewer'], true, 'Independently verified final provider evidence.');
        $this->assertSame($r, $service->complete($f['data'], 'SYNTHETIC-SETTLED', $f['reviewer'], true, 'Independently verified final provider evidence.'));
        $row = $f['row']->fresh();
        $this->assertSame('completed', $row->status->value);
        $this->assertSame('completed', $row->metadata['approval']['status']);
        $this->assertSame('captured', Payment::query()->sole()->status->value);
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame(2, LedgerEntry::query()->count());
        $this->assertNull(FinancialTransaction::query()->sole()->wallet_id);
        app(LedgerBalanceValidator::class)->assertTransactionBalanced($row->financialTransaction);
        $this->assertSame('1000.00', (string) $f['owner']['wallet']->fresh()->balance);
        $this->assertSame('0.00', (string) $f['owner']['wallet']->fresh()->locked_balance);
    }

    public function test_missing_actor_cannot_complete_an_external_payment(): void
    {
        $f = $this->externalFixture();
        app(PayoutTransferService::class)->transfer($f['data']);
        $this->expectException(AuthorizationException::class);
        app(PayoutTransferService::class)->complete($f['data'], 'SYNTHETIC-NO-ACTOR');
    }

    public function test_maker_cannot_confirm_their_transfer(): void
    {
        $f = $this->externalFixture();
        app(PayoutTransferService::class)->transfer($f['data']);
        $this->expectException(PayoutTransferException::class);
        app(PayoutTransferService::class)->complete($f['data'], 'SYNTHETIC-MAKER', $f['maker'], true, 'Maker tries confirming original own request.');
    }

    public function test_unverified_result_stays_processing_and_unbooked(): void
    {
        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);
        try {
            $service->complete($f['data'], 'SYNTHETIC-UNKNOWN', $f['reviewer'], false, 'Not found is not a final failure result.');
            $this->fail('Unverified result completed.');
        } catch (PayoutTransferException) {
            $this->assertSame('processing', $f['row']->fresh()->status->value);
            $this->assertDatabaseCount('financial_transactions', 0);
        }
    }

    public function test_missing_payment_cannot_be_silently_completed(): void
    {
        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);
        Payment::query()->sole()->delete();
        $this->expectException(PayoutTransferException::class);
        $service->complete($f['data'], 'SYNTHETIC-MISSING-PAYMENT', $f['reviewer'], true, 'Independently reviewed synthetic result.');
    }

    public function test_changed_reference_on_completed_replay_is_refused(): void
    {
        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);
        $service->complete($f['data'], 'SYNTHETIC-ORIGINAL', $f['reviewer'], true, 'Independently verified original provider result.');
        $this->expectException(PayoutTransferException::class);
        $service->complete($f['data'], 'SYNTHETIC-REPLACEMENT', $f['reviewer'], true, 'Different provider reference cannot be replay.');
    }

    public function test_final_refusal_has_no_ledger_and_never_opens_a_fresh_attempt(): void
    {
        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);
        $r = $service->fail($f['data'], 'Independently confirmed final provider refusal.', $f['reviewer'], true);
        $this->assertSame('failed', $r['lane_status']);
        $this->assertSame($r, $service->fail($f['data'], 'Independently confirmed final provider refusal.', $f['reviewer'], true));
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame($r, $service->transfer($f['data']));
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_inactive_ledger_account_rolls_back_reference_and_all_financial_state(): void
    {
        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);
        LedgerAccount::query()->update(['is_active' => false]);
        try {
            $service->complete($f['data'], 'SYNTHETIC-ROLLBACK', $f['reviewer'], true, 'Independently verified synthetic provider result.');
            $this->fail('Posted to disabled chart.');
        } catch (FinancialException) {
            $this->assertSame('processing', $f['row']->fresh()->status->value);
            $this->assertDatabaseCount('payout_transfer_references', 0);
            $this->assertDatabaseCount('financial_transactions', 0);
            $this->assertSame('pending', Payment::query()->sole()->status->value);
        }
    }

    public function test_current_kyc_revocation_blocks_confirmation(): void
    {
        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);
        $f['owner']['user']->kycDocuments()->update(['status' => 'rejected']);
        $this->expectException(PayoutTransferException::class);
        $service->complete($f['data'], 'SYNTHETIC-KYC-REVOKED', $f['reviewer'], true, 'Current beneficiary KYC is no longer verified.');
    }

    public static function paymentMutations(): array
    {
        return [['user_id'], ['currency'], ['gateway'], ['amount'], ['status']];
    }

    #[DataProvider('paymentMutations')]
    public function test_incompatible_payment_cannot_be_captured(string $field): void
    {
        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);
        $p = Payment::query()->sole();
        $p->{$field} = match ($field) {
            'user_id' => $f['maker']->id, 'currency' => Currency::USD,
            'gateway' => 'crypto', 'amount' => '401.00', 'status' => PaymentStatus::Failed,
        };
        $p->save();
        $this->expectException(PayoutTransferException::class);
        $service->complete($f['data'], 'SYNTHETIC-PAYMENT-DRIFT', $f['reviewer'], true, 'Recorded Payment facts must match the obligation.');
    }
}
