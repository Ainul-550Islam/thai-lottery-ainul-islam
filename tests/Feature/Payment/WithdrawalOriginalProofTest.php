<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\PaymentCallbackData;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\PaymentCallbackService;
use App\Services\Payment\WithdrawalDisbursementService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** Synthetic local fixtures, not authenticated provider acceptance or a historical repair facility. */
final class WithdrawalOriginalProofTest extends PaymentTestCase
{
    private function fixture(Currency $currency = Currency::THB, bool $payment = false): array
    {
        $f = $this->createPlayer('1000.00', $currency);
        $row = app(WithdrawalService::class)->request($f['wallet'], Money::of('100.00', $currency), PaymentMethod::BankTransfer,
            options: ['fee' => '5.00', 'payout_details' => ['account' => 'synthetic-only']]);
        app(WithdrawalApprovalService::class)->approve($row);
        $row->refresh();
        $f['row'] = $row;
        if ($payment) {
            $f['payment'] = Payment::query()->create(['reference_number' => 'PAY-SYNTHETIC-'.bin2hex(random_bytes(8)),
                'user_id' => $f['user']->id, 'payable_type' => $row::class, 'payable_id' => $row->id,
                'method' => $row->method, 'status' => PaymentStatus::Pending, 'currency' => $currency,
                'amount' => '100.00', 'fee' => '5.00', 'gateway' => 'bank_transfer',
                'gateway_reference' => 'SYNTHETIC-WITHDRAWAL-'.bin2hex(random_bytes(8)), 'metadata' => []]);
        }

        return $f;
    }

    private function settle(array $f): array
    {
        return app(WithdrawalCompletionService::class)->complete($f['row'], null,
            ['provider' => 'bank_transfer', 'provider_reference' => 'SYNTHETIC-SETTLED-'.$f['row']->id]);
    }

    private function originalCallback(Payment $payment, PaymentTransactionStatus $status = PaymentTransactionStatus::Succeeded): PaymentCallbackData
    {
        return PaymentCallbackData::fromInput('bank_transfer', $payment->gateway_reference, '100.00', $payment->currency->value,
            $status, null, null, hash('sha256', 'synthetic-original-withdrawal'));
    }

    private function refused(callable $action): void
    {
        try {
            $action();
            $this->fail('Contradictory financial evidence was accepted.');
        } catch (FinancialException) {
            $this->addToAssertionCount(1);
        }
    }

    public static function currencies(): array
    {
        return [[Currency::THB], [Currency::USD], [Currency::BDT]];
    }

    #[DataProvider('currencies')]
    public function test_owned_hold_canonical_gross_debit_fee_and_replay_are_exact(Currency $currency): void
    {
        $f = $this->fixture($currency);
        $out = $this->settle($f);
        $this->assertTrue($out['debited']);
        $this->assertSame(app(WithdrawalCompletionService::class)->idempotencyKeyFor($f['row']), $out['transaction']->idempotency_key);
        $this->assertSame('100.00', $out['transaction']->amount);
        $this->assertSame('5.00', $out['transaction']->fee);
        $this->assertSame('900.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', $f['wallet']->fresh()->locked_balance);
        $record = DB::table('withdrawal_reservations')->sole();
        $this->assertSame('consumed', $record->state);
        $this->assertSame((int) $out['transaction']->id, (int) $record->financial_transaction_id);
        $f['row']->refresh();
        $this->assertTrue(app(WithdrawalCompletionService::class)->complete($f['row'], 'a-different-delivery-key')['replayed']);
        $this->assertEquals($record, DB::table('withdrawal_reservations')->sole());
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public static function corruptions(): array
    {
        return array_map(fn ($v) => [$v], ['amount', 'fee', 'net_amount', 'user_id', 'method', 'payout_details',
            'transaction_amount', 'transaction_fee', 'transaction_status', 'transaction_type', 'transaction_key',
            'transaction_reference', 'transaction_deleted', 'ledger_amount', 'ledger_wallet', 'ledger_deleted',
            'reservation_state', 'reservation_hash', 'completed_at', 'missing_link', 'reference_owner']);
    }

    #[DataProvider('corruptions')]
    public function test_completed_replay_refuses_corrupt_original_proof_without_repair(string $kind): void
    {
        $f = $this->fixture();
        $out = $this->settle($f);
        $row = $f['row']->fresh();
        $tx = $out['transaction'];
        if (in_array($kind, ['amount', 'fee', 'net_amount'], true)) {
            DB::table('withdrawals')->where('id', $row->id)->update([$kind => '6.00']);
        } elseif ($kind === 'user_id') {
            $foreign = $this->createPlayer('0.00');
            DB::table('withdrawals')->where('id', $row->id)->update(['user_id' => $foreign['user']->id]);
        } elseif ($kind === 'method') {
            DB::table('withdrawals')->where('id', $row->id)->update(['method' => 'bkash']);
        } elseif ($kind === 'payout_details') {
            $row->payout_details = ['account' => 'not-original'];
            $row->save();
        } elseif (str_starts_with($kind, 'transaction_')) {
            [$field, $value] = match ($kind) {
                'transaction_amount' => ['amount', '90.00'], 'transaction_fee' => ['fee', '0.00'],
                'transaction_status' => ['status', 'failed'], 'transaction_type' => ['type', 'deposit'],
                'transaction_key' => ['idempotency_key', 'not-canonical'], 'transaction_reference' => ['reference_id', $row->id + 1000],
                'transaction_deleted' => ['deleted_at', now()],
            };
            DB::table('financial_transactions')->where('id', $tx->id)->update([$field => $value]);
        } elseif (str_starts_with($kind, 'ledger_')) {
            $entry = $tx->ledgerEntries()->where('type', 'debit')->firstOrFail();
            [$field, $value] = match ($kind) {
                'ledger_amount' => ['amount', '90.00'], 'ledger_wallet' => ['wallet_id', null], 'ledger_deleted' => ['deleted_at', now()],
            };
            DB::table('ledger_entries')->where('id', $entry->id)->update([$field => $value]);
        } elseif (str_starts_with($kind, 'reservation_')) {
            DB::table('withdrawal_reservations')->where('withdrawal_id', $row->id)->update($kind === 'reservation_state'
                ? ['state' => 'released'] : ['terms_hash' => str_repeat('0', 64)]);
        } elseif ($kind === 'completed_at') {
            DB::table('withdrawals')->where('id', $row->id)->update(['completed_at' => null]);
        } elseif ($kind === 'missing_link') {
            DB::table('withdrawals')->where('id', $row->id)->update(['financial_transaction_id' => null]);
        } else {
            DB::table('outbound_provider_references')->where('withdrawal_id', $row->id)->delete();
        }
        $before = DB::table('withdrawals')->where('id', $row->id)->first();
        $this->refused(fn () => app(WithdrawalCompletionService::class)->complete($row->fresh()));
        $this->assertEquals($before, DB::table('withdrawals')->where('id', $row->id)->first());
        $this->assertFalse(app(WithdrawalCompletionService::class)->isSettled($row->fresh()));
        $this->assertSame('900.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', $f['wallet']->fresh()->locked_balance);
    }

    public function test_foreign_transaction_and_unmarked_debit_never_heal_or_consume_neighbours_hold(): void
    {
        $f = $this->fixture();
        $tx = app(WalletService::class)->credit($f['wallet'], Money::of('1.00', Currency::THB), FinancialTransactionType::Deposit, 'synthetic-foreign-credit');
        DB::table('withdrawals')->where('id', $f['row']->id)->update(['financial_transaction_id' => $tx->id]);
        $this->refused(fn () => app(WithdrawalCompletionService::class)->complete($f['row']));
        $this->assertSame(WithdrawalStatus::Approved, $f['row']->fresh()->status);
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public static function ownershipActions(): array
    {
        return [['complete'], ['reject'], ['cancel'], ['failed'], ['processing']];
    }

    #[DataProvider('ownershipActions')]
    public function test_aggregate_money_cannot_substitute_for_missing_obligation_ownership(string $action): void
    {
        $f = $this->fixture();
        $unowned = app(WithdrawalService::class)->request($f['wallet'], Money::of('100.00', Currency::THB), PaymentMethod::BankTransfer);
        DB::table('withdrawals')->where('id', $unowned->id)->update(['status' => 'approved', 'approved_at' => now()]);
        $this->refused(fn () => match ($action) {
            'complete' => app(WithdrawalCompletionService::class)->complete($unowned),
            'reject' => app(WithdrawalApprovalService::class)->reject($unowned, 'synthetic'),
            'cancel' => app(WithdrawalService::class)->cancel($unowned),
            'failed' => app(WithdrawalApprovalService::class)->markFailed($unowned, 'synthetic'),
            'processing' => app(WithdrawalApprovalService::class)->markProcessing($unowned),
        });
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame('1000.00', $f['wallet']->fresh()->balance);
        $this->assertSame('active', DB::table('withdrawal_reservations')->sole()->state);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_unowned_processing_row_cannot_enter_external_dispatch(): void
    {
        $f = $this->fixture();
        $unowned = app(WithdrawalService::class)->request($f['wallet'], Money::of('100.00', Currency::THB), PaymentMethod::BankTransfer);
        DB::table('withdrawals')->where('id', $unowned->id)->update(['status' => 'processing', 'approved_at' => now()]);
        $this->refused(fn () => app(WithdrawalDisbursementService::class)->disburse($unowned));
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $this->assertNull(data_get($unowned->fresh()->metadata, 'outbound_dispatch'));
    }

    public function test_identity_detention_does_not_erase_owned_hold_and_safe_cancellation_releases_only_that_owner(): void
    {
        $f = $this->fixture();
        DB::table('withdrawals')->where('id', $f['row']->id)->update(['status' => 'kyc_required']);
        app(WithdrawalService::class)->cancel($f['row']);
        $this->assertSame('released', DB::table('withdrawal_reservations')->sole()->state);
        $this->assertSame('0.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame('1000.00', $f['wallet']->fresh()->balance);
    }

    public function test_aggregate_deficit_cannot_be_hidden_by_another_withdrawal(): void
    {
        $f = $this->fixture();
        $second = app(WithdrawalService::class)->request($f['wallet'], Money::of('100.00', Currency::THB), PaymentMethod::BankTransfer);
        app(WithdrawalApprovalService::class)->approve($second);
        DB::table('wallets')->where('id', $f['wallet']->id)->update(['locked_balance' => '100.00']);
        $this->refused(fn () => $this->settle($f));
        $this->refused(fn () => app(WithdrawalApprovalService::class)->reject($second, 'not owned aggregate'));
        $this->assertSame(2, DB::table('withdrawal_reservations')->where('state', 'active')->count());
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public static function releases(): array
    {
        return [['reject'], ['cancel'], ['failed']];
    }

    #[DataProvider('releases')]
    public function test_owned_release_leaves_neighbours_reservation_untouched(string $action): void
    {
        $f = $this->fixture();
        $second = app(WithdrawalService::class)->request($f['wallet'], Money::of('100.00', Currency::THB), PaymentMethod::BankTransfer);
        app(WithdrawalApprovalService::class)->approve($second);
        match ($action) {
            'reject' => app(WithdrawalApprovalService::class)->reject($f['row'], 'synthetic'),
            'cancel' => app(WithdrawalService::class)->cancel($f['row']),
            'failed' => app(WithdrawalApprovalService::class)->markFailed($f['row'], 'synthetic'),
        };
        $this->assertSame('released', DB::table('withdrawal_reservations')->where('withdrawal_id', $f['row']->id)->value('state'));
        $this->assertSame('active', DB::table('withdrawal_reservations')->where('withdrawal_id', $second->id)->value('state'));
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame('1000.00', $f['wallet']->fresh()->balance);
    }

    public function test_unknown_dispatch_cannot_be_cancelled_rejected_or_manually_failed(): void
    {
        $f = $this->fixture();
        $row = $f['row'];
        $row->metadata = array_merge($row->metadata, ['outbound_dispatch' => ['state' => 'unknown']]);
        $row->save();
        $this->refused(fn () => app(WithdrawalService::class)->cancel($row));
        $this->refused(fn () => app(WithdrawalApprovalService::class)->reject($row, 'timeout is not evidence'));
        $this->refused(fn () => app(WithdrawalApprovalService::class)->markFailed($row, 'operator guess'));
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame('active', DB::table('withdrawal_reservations')->sole()->state);
    }

    public function test_callback_uses_canonical_debit_and_captured_browser_requires_actual_proof(): void
    {
        $f = $this->fixture(payment: true);
        $service = app(PaymentCallbackService::class);
        $this->assertFalse($service->apply($this->originalCallback($f['payment']))['replayed']);
        $this->assertSame('900.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame(app(WithdrawalCompletionService::class)->idempotencyKeyFor($f['row']), FinancialTransaction::query()->sole()->idempotency_key);
        $before = DB::table('payments')->sole();
        $this->assertTrue($service->apply($this->originalCallback($f['payment']))['replayed']);
        $this->assertEquals($before, DB::table('payments')->sole());
        $this->assertTrue($service->browserReturnProjection($f['payment']->reference_number, null, null, $f['user']->id)['paid']);
        DB::table('withdrawal_reservations')->where('withdrawal_id', $f['row']->id)->update(['state' => 'released']);
        $this->assertFalse($service->browserReturnProjection($f['payment']->reference_number, null, null, $f['user']->id)['paid']);
        $this->refused(fn () => $service->apply($this->originalCallback($f['payment'])));
    }

    public function test_captured_label_alone_never_proves_a_withdrawal_and_reversal_word_cannot_refund(): void
    {
        $f = $this->fixture(payment: true);
        $service = app(PaymentCallbackService::class);
        DB::table('payments')->where('id', $f['payment']->id)->update(['status' => 'captured']);
        $this->assertFalse($service->browserReturnProjection($f['payment']->reference_number, null, null, $f['user']->id)['paid']);
        $this->refused(fn () => $service->apply($this->originalCallback($f['payment'])));
        $this->refused(fn () => $service->apply($this->originalCallback($f['payment'], PaymentTransactionStatus::Reversed)));
        $this->assertSame(PaymentStatus::Captured, $f['payment']->fresh()->status);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
    }

    public function test_verified_failure_releases_once_and_duplicate_cannot_release_a_later_hold(): void
    {
        $f = $this->fixture(payment: true);
        $service = app(PaymentCallbackService::class);
        $this->assertFalse($service->apply($this->originalCallback($f['payment'], PaymentTransactionStatus::Failed))['replayed']);
        $second = app(WithdrawalService::class)->request($f['wallet'], Money::of('100.00', Currency::THB), PaymentMethod::BankTransfer);
        app(WithdrawalApprovalService::class)->approve($second);
        $this->assertTrue($service->apply($this->originalCallback($f['payment'], PaymentTransactionStatus::Failed))['replayed']);
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame('1000.00', $f['wallet']->fresh()->balance);
    }

    public function test_caught_refusal_in_ambient_transaction_cannot_commit_partial_reservation_consumption(): void
    {
        $f = $this->fixture();
        DB::transaction(function () use ($f): void {
            $key = app(WithdrawalCompletionService::class)->idempotencyKeyFor($f['row']);
            app(WalletService::class)->credit($f['wallet'], Money::of('1.00', Currency::THB), FinancialTransactionType::Deposit, $key);
            $this->refused(fn () => $this->settle($f));
            $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
            $this->assertSame('active', DB::table('withdrawal_reservations')->sole()->state);
        });
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame(WithdrawalStatus::Approved, $f['row']->fresh()->status);
        $this->assertDatabaseCount('financial_transactions', 1);
    }
}
