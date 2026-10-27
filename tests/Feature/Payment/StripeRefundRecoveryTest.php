<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\FinancialTransactionType;
use App\Exceptions\FinancialException;
use App\Exceptions\InsufficientBalanceException;
use App\Filament\Resources\DepositResource\DepositDecisionActions;
use App\Models\FinancialTransaction;
use App\Services\Finance\Money;
use App\Services\Finance\ReversalIntegrityGuard;
use App\Services\Finance\WalletService;
use App\Services\Payment\StripeDepositFollowupInbox;
use App\Services\Payment\StripeDepositSettlementService;
use App\Services\Payment\StripeRefundRecoveryAccess;
use App\Services\Payment\StripeRefundRecoveryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StripeRefundRecoveryFixture;

final class StripeRefundRecoveryTest extends PaymentTestCase
{
    use StripeRefundRecoveryFixture;

    public function test_exact_money_cash_liability_and_read_only_replay(): void
    {
        $f = $this->refundFixture();
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $out = $this->recoverRefund($f);
        $this->assertTrue($out['recovered']);
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
        $lines = DB::table('ledger_entries')->where('financial_transaction_id', $out['financial_transaction_id'])->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame(['debit', 'credit'], $lines->pluck('type')->all());
        $this->assertSame(0, bccomp((string) $lines[0]->amount, '100', 2));
        $this->assertSame(0, bccomp((string) $lines[1]->amount, '100', 2));
        $this->assertSame((int) $f['wallet']->id, (int) $lines[0]->wallet_id);
        $codes = DB::table('ledger_accounts')->whereIn('id', $lines->pluck('ledger_account_id'))->pluck('code', 'id');
        $this->assertSame('2000', $codes[$lines[0]->ledger_account_id]);
        $this->assertSame('1000', $codes[$lines[1]->ledger_account_id]);
        $paper = $this->moneyPaper();
        $this->assertTrue($this->recoverRefund($f)['replayed']);
        $this->assertSame($paper, $this->moneyPaper());
        Http::assertSentCount(4);
        $this->assertSame('confirmed', $f['deposit']->fresh()->status->value);
        $this->assertSame('captured', $f['payment']->fresh()->status->value);
    }

    private function refuses(array $f, string $exception = FinancialException::class): void
    {
        $paper = $this->moneyPaper();
        try {
            $this->recoverRefund($f);
            $this->fail('The unproved financial operation must refuse.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e, $e->getMessage());
        }
        $this->assertSame($paper, $this->moneyPaper());
    }

    public function test_reviewer_or_inherited_super_admin_is_not_a_financial_capability(): void
    {
        $f = $this->refundFixture();
        $f['actor']->revokePermissionTo(StripeRefundRecoveryAccess::RECOVER);
        $f['actor']->assignRole('super-admin');
        $this->refuses($f, AuthorizationException::class);
        Http::assertNothingSent();
        $this->assertDatabaseCount(StripeRefundRecoveryService::INTENTS, 0);
    }

    public function test_default_off_gate_and_ambient_framework_or_pdo_transaction_refuse_before_http(): void
    {
        $f = $this->refundFixture();
        config(['payment.refund_recovery.stripe.enabled' => false]);
        $this->refuses($f);
        Http::assertNothingSent();
        config(['payment.refund_recovery.stripe.enabled' => true]);
        DB::beginTransaction();
        try {
            $this->refuses($f);
        } finally {
            DB::rollBack();
        }
        DB::connection()->getPdo()->beginTransaction();
        try {
            $this->refuses($f);
        } finally {
            DB::connection()->getPdo()->rollBack();
        }
        Http::assertNothingSent();
    }

    public static function invalidProofs(): array
    {
        return [
            'pending refund' => ['refund.status', 'pending'],
            'failed refund' => ['refund.status', 'failed'],
            'partial amount' => ['refund.amount', 9999],
            'floating amount' => ['refund.amount', 10000.0],
            'foreign refund charge' => ['refund.charge', 'ch_FOREIGN'],
            'foreign refund intent' => ['refund.payment_intent', 'pi_FOREIGN'],
            'foreign refund currency' => ['refund.currency', 'usd'],
            'partial charge' => ['charge.amount_refunded', 9999],
            'disputed charge' => ['charge.disputed', true],
            'uncaptured charge' => ['charge.captured', false],
            'foreign latest charge' => ['intent.latest_charge', 'ch_FOREIGN'],
            'wrong live mode' => ['intent.livemode', true],
            'foreign charge currency' => ['charge.currency', 'usd'],
            'connect destination' => ['charge.transfer_data', ['destination' => 'acct_FOREIGN']],
            'connect fee' => ['charge.application_fee_amount', 0],
            'refund reversal' => ['refund.transfer_reversal', 'trr_FOREIGN'],
            'incomplete list' => ['list.has_more', true],
            'empty list' => ['list.data', []],
            'list refund pending' => ['list.data.0.status', 'pending'],
        ];
    }

    #[DataProvider('invalidProofs')]
    public function test_unproved_provider_facts_never_debit(string $path, mixed $value): void
    {
        $f = $this->refundFixture();
        data_set($f['proof'], $path, $value);
        // JSON wire has no meaningful float/int distinction without a fractional part; force it for the integer control.
        if ($path === 'refund.amount' && is_float($value)) {
            $value = 10000.5;
            data_set($f['proof'], $path, $value);
        }
        $this->fakeRefundProof($f['proof']);
        $this->refuses($f);
        $this->assertDatabaseCount(StripeRefundRecoveryService::INTENTS, 0);
        $this->assertDatabaseCount(StripeRefundRecoveryService::RECOVERIES, 0);
    }

    public function test_multiple_refunds_and_redirect_have_no_retry_or_provider_write(): void
    {
        $f = $this->refundFixture();
        $f['proof']['list']['data'][] = $f['refund'];
        $this->fakeRefundProof($f['proof']);
        $this->refuses($f);
        Http::assertSentCount(4);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.stripe.com/*' => Http::response('', 302, ['Location' => 'https://foreign.example/refund'])]);
        $this->refuses($f);
        Http::assertSentCount(1);
    }

    public function test_signed_partial_pending_foreign_and_non_refund_sources_refuse_before_http(): void
    {
        $f = $this->refundFixture();
        foreach (['amount' => 9999, 'status' => 'pending', 'charge' => 'ch_FOREIGN', 'currency' => 'usd'] as $key => $value) {
            $e = $this->followupEvent('refund.updated', 'evt_BAD'.strtoupper($key));
            $e['data']['object'] = $f['refund'];
            $e['data']['object'][$key] = $value;
            $f['inbox'] = $this->receiveFollowup($e)['inbox_id'];
            $this->refuses($f);
        }
        $e = $this->followupEvent('charge.dispute.created', 'evt_SYNTHETICDISPUTE');
        $f['inbox'] = $this->receiveFollowup($e)['inbox_id'];
        $this->refuses($f);
        Http::assertNothingSent();
    }

    public function test_spendable_shortfall_commits_only_proof_then_explicit_retry_collects_once(): void
    {
        $f = $this->refundFixture();
        app(WalletService::class)->debit($f['wallet'], Money::of('90', $f['wallet']->currency), FinancialTransactionType::Adjustment, 'synthetic-refund-prior-spend');
        $this->refuses($f, InsufficientBalanceException::class);
        $this->assertSame('10.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount(StripeRefundRecoveryService::INTENTS, 1);
        $this->assertDatabaseCount(StripeRefundRecoveryService::RECEIPTS, 1);
        $this->assertDatabaseCount(StripeRefundRecoveryService::RECOVERIES, 0);
        app(WalletService::class)->credit($f['wallet']->fresh(), Money::of('90', $f['wallet']->currency), FinancialTransactionType::Adjustment, 'synthetic-refund-later-funds');
        $this->assertTrue($this->recoverRefund($f)['recovered']);
        $this->assertTrue($this->recoverRefund($f)['replayed']);
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount(StripeRefundRecoveryService::RECEIPTS, 2);
        Http::assertSentCount(8);
    }

    public function test_held_funds_are_not_collected_or_unlocked(): void
    {
        $f = $this->refundFixture();
        app(WalletService::class)->lock($f['wallet'], Money::of('1', $f['wallet']->currency));
        $this->refuses($f, InsufficientBalanceException::class);
        $this->assertSame('1.00', $f['wallet']->fresh()->locked_balance);
        $this->assertDatabaseCount(StripeRefundRecoveryService::RECEIPTS, 1);
    }

    public function test_revoked_direct_duty_and_changed_configuration_during_http_are_rechecked(): void
    {
        $f = $this->refundFixture();
        $this->fakeRefundProof($f['proof'], function () use ($f): void {
            $f['actor']->revokePermissionTo(StripeRefundRecoveryAccess::RECOVER);
        });
        $this->refuses($f, AuthorizationException::class);
        $this->assertDatabaseCount(StripeRefundRecoveryService::INTENTS, 0);
        $f['actor']->givePermissionTo(StripeRefundRecoveryAccess::RECOVER);
        $this->fakeRefundProof($f['proof'], function (): void {
            config(['payment.gateways.stripe.secret' => 'sk_test_CHANGEDCREDENTIAL']);
        });
        $this->refuses($f);
        $this->assertDatabaseCount(StripeRefundRecoveryService::INTENTS, 0);
    }

    public function test_provider_snapshot_expiry_during_http_is_not_retimestamped_fresh(): void
    {
        $f = $this->refundFixture();
        $advanced = false;
        $this->fakeRefundProof($f['proof'], function () use (&$advanced): void {
            if (! $advanced) {
                $advanced = true;
                $this->travel(301)->seconds();
            }
        });
        try {
            $this->refuses($f);
            $this->assertDatabaseCount(StripeRefundRecoveryService::RECEIPTS, 0);
        } finally {
            $this->travelBack();
        }
    }

    public function test_financial_and_intent_header_tampering_refuses_read_only_replay(): void
    {
        $f = $this->refundFixture();
        $out = $this->recoverRefund($f);
        DB::table('ledger_entries')->where('financial_transaction_id', $out['financial_transaction_id'])->update(['description' => 'SYNTHETIC TAMPERING']);
        $this->refuses($f);
        Http::assertSentCount(4);
    }

    public function test_immutable_intent_header_is_bound_to_cipher(): void
    {
        $f = $this->refundFixture();
        $this->recoverRefund($f);
        DB::table(StripeRefundRecoveryService::INTENTS)->update(['created_epoch' => (int) DB::table(StripeRefundRecoveryService::INTENTS)->sole()->created_epoch - 1]);
        $this->refuses($f);
        Http::assertSentCount(4);
    }

    public function test_signed_alias_event_replays_one_recovery_without_more_provider_reads(): void
    {
        $f = $this->refundFixture();
        $out = $this->recoverRefund($f);
        $e = $this->followupEvent('refund.updated', 'evt_SIGNEDALIAS');
        $e['data']['object'] = $f['refund'];
        $f['inbox'] = $this->receiveFollowup($e)['inbox_id'];
        $again = $this->recoverRefund($f);
        $this->assertTrue($again['replayed']);
        $this->assertSame($out['financial_transaction_id'], $again['financial_transaction_id']);
        $this->assertDatabaseCount(StripeRefundRecoveryService::RECOVERIES, 1);
        Http::assertSentCount(4);
    }

    public function test_managed_recovery_cannot_be_generically_reversed_even_with_removed_morph_and_key(): void
    {
        $f = $this->refundFixture();
        $out = $this->recoverRefund($f);
        DB::table('financial_transactions')->where('id', $out['financial_transaction_id'])->update(['reference_type' => null, 'reference_id' => null, 'idempotency_key' => 'synthetic-forged-generic-key']);
        $tx = FinancialTransaction::findOrFail($out['financial_transaction_id']);
        $this->expectException(FinancialException::class);
        DB::transaction(fn () => app(ReversalIntegrityGuard::class)->assertUnmanaged($tx));
    }

    public function test_missing_recovery_record_never_adopts_existing_financial_transaction(): void
    {
        $f = $this->refundFixture();
        $this->recoverRefund($f);
        DB::table(StripeRefundRecoveryService::RECOVERIES)->delete();
        $this->refuses($f);
        $this->assertDatabaseCount(StripeRefundRecoveryService::RECOVERIES, 0);
    }

    public function test_atomic_financial_failure_retains_receipt_without_partial_wallet_or_ledger(): void
    {
        $f = $this->refundFixture();
        $armed = true;
        DB::listen(function (QueryExecuted $q) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($q->sql), 'insert into "ledger_entries"')) {
                $armed = false;
                throw new \RuntimeException('SYNTHETIC ledger fault');
            }
            if ($armed && str_starts_with(strtolower($q->sql), 'insert into `ledger_entries`')) {
                $armed = false;
                throw new \RuntimeException('SYNTHETIC ledger fault');
            }
        });
        $this->refuses($f, \RuntimeException::class);
        $this->assertFalse($armed);
        $this->assertDatabaseCount(StripeRefundRecoveryService::RECEIPTS, 1);
        $this->assertDatabaseCount(StripeRefundRecoveryService::RECOVERIES, 0);
        $this->assertTrue($this->recoverRefund($f)['recovered']);
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
    }

    public function test_receipt_expires_after_custody_before_financial_root_and_remains_retryable(): void
    {
        $f = $this->refundFixture();
        $armed = true;
        DB::listen(function (QueryExecuted $q) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($q->sql), 'insert into') && str_contains($q->sql, 'stripe_refund_recovery_receipts')) {
                $armed = false;
                $this->travel(301)->seconds();
            }
        });
        try {
            $this->refuses($f);
            $this->assertFalse($armed);
            $this->assertDatabaseCount(StripeRefundRecoveryService::RECEIPTS, 1);
            $this->assertDatabaseCount(StripeRefundRecoveryService::RECOVERIES, 0);
            $this->assertTrue($this->recoverRefund($f)['recovered']);
        } finally {
            $this->travelBack();
        }
    }

    public function test_pending_receipt_corruption_is_not_replaced_or_healed_by_a_new_provider_read(): void
    {
        $f = $this->refundFixture();
        app(WalletService::class)->lock($f['wallet'], Money::of('1', $f['wallet']->currency));
        $this->refuses($f, InsufficientBalanceException::class);
        DB::table(StripeRefundRecoveryService::RECEIPTS)->update(['response_cipher' => 'SYNTHETIC corrupt ciphertext']);
        $this->refuses($f);
        $this->assertDatabaseCount(StripeRefundRecoveryService::RECEIPTS, 1);
        Http::assertSentCount(4);
    }

    public function test_original_capture_and_signed_source_custody_are_required_before_http(): void
    {
        $f = $this->refundFixture();
        DB::table(StripeDepositSettlementService::TABLE)->update(['response_hash' => str_repeat('0', 64)]);
        $this->refuses($f);
        Http::assertNothingSent();
    }

    public function test_source_custody_corruption_blocks_applied_replay_without_healing(): void
    {
        $f = $this->refundFixture();
        $this->recoverRefund($f);
        DB::table(StripeDepositFollowupInbox::TABLE)->where('id', $f['inbox'])->update(['response_hash' => str_repeat('0', 64)]);
        $this->refuses($f);
        Http::assertSentCount(4);
    }

    public function test_later_wallet_activity_does_not_turn_replay_into_a_second_debit(): void
    {
        $f = $this->refundFixture();
        $this->recoverRefund($f);
        app(WalletService::class)->credit($f['wallet']->fresh(), Money::of('5', $f['wallet']->currency), FinancialTransactionType::Adjustment, 'synthetic-refund-unrelated-funds');
        $paper = $this->moneyPaper();
        $this->assertTrue($this->recoverRefund($f)['replayed']);
        $this->assertSame($paper, $this->moneyPaper());
        $this->assertSame('5.00', $f['wallet']->fresh()->balance);
        Http::assertSentCount(4);
    }

    public function test_financial_action_is_separately_gated_and_accepts_no_amount_or_url(): void
    {
        $f = $this->refundFixture();
        $this->actingAs($f['actor']);
        $f['deposit'] = $f['deposit']->fresh();
        $spec = collect(DepositDecisionActions::specs())->firstWhere('name', 'recoverConfirmedStripeRefund');
        $this->assertNotNull($spec);
        $this->assertTrue(($spec['visible'])($f['deposit']));
        $this->assertSame(['inbox_id'], array_map(fn ($field) => $field->getName(), $spec['form']));
        config(['payment.refund_recovery.stripe.enabled' => false]);
        $this->assertFalse(($spec['visible'])($f['deposit']));
        config(['payment.refund_recovery.stripe.enabled' => true]);
        ($spec['action'])($f['deposit'], ['inbox_id' => $f['inbox'], 'amount' => '0.01', 'provider_url' => 'https://foreign.example']);
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount(StripeRefundRecoveryService::RECOVERIES, 1);
    }
}
