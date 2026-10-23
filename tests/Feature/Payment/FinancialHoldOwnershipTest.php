<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Finance\FinancialHoldData;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\RiskLevel;
use App\Exceptions\FinancialException;
use App\Exceptions\FinancialHoldException;
use App\Models\AuditLog;
use App\Models\FinancialHold;
use App\Models\FinancialTransaction;
use App\Services\Finance\FinancialHoldOwnership;
use App\Services\Finance\FinancialHoldService;
use App\Services\Finance\Money;
use App\Services\Finance\ReversalIntegrityGuard;
use App\Services\Finance\WalletHoldService;
use App\Services\Finance\WalletService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Finance\WithdrawalService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

final class FinancialHoldOwnershipTest extends PaymentTestCase
{
    private function fixture(Currency $currency = Currency::THB): array
    {
        $f = $this->createPlayer('1000.00', $currency);
        $data = FinancialHoldData::fromInput($f['wallet']->id, '100.00', $currency->value,
            'Synthetic internal compliance evidence.', 'SYNTHETIC-OWNED-HOLD');
        $hold = app(FinancialHoldService::class)->place($data)['hold'];

        return $f + ['data' => $data, 'hold' => $hold];
    }

    private function refused(callable $action, ?string $code = null): void
    {
        try {
            $action();
            $this->fail('An unproved financial-hold action was accepted.');
        } catch (FinancialException|FinancialHoldException $error) {
            $this->assertNotSame('', $error->getMessage());
            if ($code !== null) {
                $this->assertSame($code, $error->errorCode());
            }
        }
    }

    public static function currencies(): array
    {
        return [[Currency::THB], [Currency::USD], [Currency::BDT]];
    }

    #[DataProvider('currencies')]
    public function test_canonical_amount_replay_has_one_owner_and_release_is_exactly_once(Currency $currency): void
    {
        $f = $this->fixture($currency);
        $service = app(FinancialHoldService::class);
        $replay = $service->place(new FinancialHoldData($f['wallet']->id, '0100.0', strtolower($currency->value),
            $f['data']->reason, strtolower($f['data']->sourceReference)));
        $this->assertTrue($replay['replayed']);
        $this->assertSame($f['hold']->id, $replay['hold']->id);
        $this->assertDatabaseCount('financial_hold_claims', 1);
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $released = $service->release($f['hold'], 'Synthetic release authority evidence.');
        $before = $released->fresh()->getRawOriginal();
        $service->release($f['hold'], 'Synthetic release authority evidence.');
        $this->assertSame($before, $released->fresh()->getRawOriginal());
        $this->assertSame('released', DB::table('financial_hold_claims')->sole()->state);
        $this->assertSame('1000.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', $f['wallet']->fresh()->locked_balance);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    #[DataProvider('currencies')]
    public function test_conversion_posts_one_proved_debit_and_terminal_replay_is_read_only(Currency $currency): void
    {
        $f = $this->fixture($currency);
        $service = app(FinancialHoldService::class);
        $service->review($f['hold'], $f['user']->id, 'Synthetic internal review evidence.');
        $out = $service->convert($f['hold'], $f['user']->id, 'Synthetic internal conversion authority.');
        $this->assertSame(RiskLevel::Critical, AuditLog::query()->latest('id')->first()->risk_level);
        $before = $f['hold']->fresh()->getRawOriginal();
        $again = $service->convert($f['hold'], $f['user']->id, 'Synthetic internal conversion authority.');
        $this->assertSame($out['conversion_reference'], $again['conversion_reference']);
        $this->assertSame($before, $f['hold']->fresh()->getRawOriginal());
        $this->assertSame('900.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', $f['wallet']->fresh()->locked_balance);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertSame('consumed', DB::table('financial_hold_claims')->sole()->state);
        $this->assertSame(FinancialHold::class, FinancialTransaction::query()->sole()->reference_type);
        $this->assertTrue($service->place($f['data'])['replayed']);
    }

    public static function mutations(): array
    {
        return array_map(fn ($v) => [$v], ['release', 'hold', 'credit', 'debit', 'consume']);
    }

    #[DataProvider('mutations')]
    public function test_generic_mechanics_cannot_release_or_conceal_a_financial_owner_deficit(string $operation): void
    {
        $f = $this->fixture();
        $wallets = app(WalletService::class);
        if ($operation !== 'release' && $operation !== 'consume') {
            DB::table('wallets')->where('id', $f['wallet']->id)->update(['locked_balance' => '50.00']);
        }
        $before = $f['wallet']->fresh()->getRawOriginal();
        $this->refused(fn () => match ($operation) {
            'release' => $wallets->unlockFunds($f['wallet'], Money::of('1.00', Currency::THB)),
            'hold' => $wallets->lockFunds($f['wallet'], Money::of('100.00', Currency::THB)),
            'credit' => $wallets->credit($f['wallet'], Money::of('100.00', Currency::THB), FinancialTransactionType::Adjustment, 'synthetic-fin-hold-credit'),
            'debit' => $wallets->debit($f['wallet'], Money::of('1.00', Currency::THB), FinancialTransactionType::Adjustment, 'synthetic-fin-hold-debit'),
            'consume' => DB::transaction(fn () => app(WalletHoldService::class)->consume($f['wallet'], Money::of('1.00', Currency::THB))),
        });
        $this->assertSame($before, $f['wallet']->fresh()->getRawOriginal());
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame('active', DB::table('financial_hold_claims')->sole()->state);
    }

    public function test_withdrawal_and_financial_minimums_add_instead_of_overlapping(): void
    {
        $f = $this->fixture();
        $withdrawal = app(WithdrawalService::class)->request($f['wallet'], Money::of('100.00', Currency::THB), PaymentMethod::BankTransfer);
        app(WithdrawalApprovalService::class)->approve($withdrawal);
        $this->assertSame('200.00', $f['wallet']->fresh()->locked_balance);
        $this->refused(fn () => app(WalletService::class)->unlockFunds($f['wallet'], Money::of('1.00', Currency::THB)));
        app(FinancialHoldService::class)->release($f['hold'], 'Synthetic independent hold release.');
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        app(WithdrawalCompletionService::class)->complete($withdrawal);
        $this->assertSame('900.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', $f['wallet']->fresh()->locked_balance);
    }

    public static function corruptions(): array
    {
        return array_map(fn ($v) => [$v], ['amount', 'currency', 'source_reference', 'reason', 'expires_at', 'claim_amount', 'claim_state', 'claim_user', 'claim_hash', 'missing_claim', 'row_status']);
    }

    #[DataProvider('corruptions')]
    public function test_corrupt_or_missing_ownership_never_adopts_aggregate_money(string $kind): void
    {
        $f = $this->fixture();
        $changes = match ($kind) {
            'amount' => ['amount' => '10.00'], 'currency' => ['currency' => 'USD'],
            'source_reference' => ['source_reference' => 'SYNTHETIC-FOREIGN-SOURCE'], 'reason' => ['reason' => 'Mutated internal hold reason.'],
            'expires_at' => ['expires_at' => now()->addDays(9)], 'row_status' => ['status' => 'released'], default => [],
        };
        if ($changes !== []) {
            DB::table('financial_holds')->where('id', $f['hold']->id)->update($changes);
        } elseif ($kind === 'missing_claim') {
            DB::table('financial_hold_claims')->delete();
        } else {
            $claimChanges = match ($kind) {
                'claim_amount' => ['amount' => '10.00'], 'claim_state' => ['state' => 'released'],
                'claim_user' => ['user_id' => $this->createPlayer('0.00')['user']->id], 'claim_hash' => ['terms_hash' => str_repeat('0', 64)],
            };
            DB::table('financial_hold_claims')->update($claimChanges);
        }
        $this->refused(fn () => app(FinancialHoldService::class)->release($f['hold'], 'Synthetic local release evidence.'));
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame('1000.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_clock_alone_does_not_release_and_only_reviewed_due_local_hold_can_expire(): void
    {
        $f = $this->fixture();
        $this->travel(5)->days();
        try {
            $service = app(FinancialHoldService::class);
            $this->refused(fn () => $service->expire($f['hold']));
            $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
            $service->review($f['hold'], $f['user']->id, 'Synthetic overdue local hold review.');
            $service->expire($f['hold']);
            $service->expire($f['hold']);
            $this->assertSame('expired', DB::table('financial_hold_claims')->sole()->state);
            $this->assertSame('0.00', $f['wallet']->fresh()->locked_balance);
            $this->assertSame('1000.00', $f['wallet']->fresh()->balance);
        } finally {
            $this->travelBack();
        }
    }

    public function test_reviewed_future_hold_cannot_expire_early(): void
    {
        $f = $this->fixture();
        $service = app(FinancialHoldService::class);
        $service->review($f['hold'], $f['user']->id, 'Synthetic local hold review evidence.');
        $this->refused(fn () => $service->expire($f['hold']));
        $this->assertSame('active', DB::table('financial_hold_claims')->sole()->state);
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
    }

    public function test_caught_failure_after_canonical_posting_rolls_back_every_layer(): void
    {
        $f = $this->fixture();
        $service = app(FinancialHoldService::class);
        $service->review($f['hold'], $f['user']->id, 'Synthetic internal hold review.');
        $before = $f['hold']->fresh()->getRawOriginal();
        AuditLog::creating(function ($row): void {
            if (str_starts_with($row->description, 'Converted owned')) {
                throw new \RuntimeException('Synthetic post-journal audit failure.');
            }
        });
        DB::transaction(function () use ($f, $service): void {
            try {
                $service->convert($f['hold'], $f['user']->id, 'Synthetic local conversion evidence.');
                $this->fail('Injected post-journal failure was not reached.');
            } catch (\RuntimeException $error) {
                $this->assertSame('Synthetic post-journal audit failure.', $error->getMessage());
            }
        });
        $this->assertSame($before, $f['hold']->fresh()->getRawOriginal());
        $this->assertSame('active', DB::table('financial_hold_claims')->sole()->state);
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame('1000.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_preclaimed_canonical_key_is_not_adopted_as_a_conversion(): void
    {
        $f = $this->fixture();
        $service = app(FinancialHoldService::class);
        $service->review($f['hold'], $f['user']->id, 'Synthetic local hold review evidence.');
        $key = app(FinancialHoldOwnership::class)->canonicalKey($f['hold']);
        app(WalletService::class)->debit($f['wallet'], Money::of('1.00', Currency::THB), FinancialTransactionType::Adjustment, $key);
        $this->refused(fn () => $service->convert($f['hold'], $f['user']->id, 'Synthetic local conversion evidence.'));
        $this->assertSame('active', DB::table('financial_hold_claims')->sole()->state);
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame('999.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 1);
    }

    public static function brokenProofs(): array
    {
        return [['reference'], ['amount'], ['deleted_entry'], ['wrong_account'], ['header_label'], ['claim_transaction']];
    }

    #[DataProvider('brokenProofs')]
    public function test_terminal_replay_requires_the_original_exact_debit_proof(string $kind): void
    {
        $f = $this->fixture();
        $service = app(FinancialHoldService::class);
        $service->review($f['hold'], $f['user']->id, 'Synthetic internal hold review.');
        $service->convert($f['hold'], $f['user']->id, 'Synthetic internal conversion evidence.');
        $tx = FinancialTransaction::query()->sole();
        match ($kind) {
            'reference' => DB::table('financial_holds')->where('id', $f['hold']->id)->update(['evidence' => json_encode(['converted' => ['reference' => 'FOREIGN']])]),
            'amount' => DB::table('ledger_entries')->where('financial_transaction_id', $tx->id)->update(['amount' => '1.00']),
            'deleted_entry' => DB::table('ledger_entries')->where('financial_transaction_id', $tx->id)->update(['deleted_at' => now()]),
            'wrong_account' => DB::table('ledger_entries')->where('financial_transaction_id', $tx->id)->where('type', 'credit')->update(['ledger_account_id' => DB::table('ledger_accounts')->where('code', WalletService::ACCOUNT_SYSTEM_CASH)->value('id')]),
            'header_label' => DB::table('financial_transactions')->where('id', $tx->id)->update(['reference_type' => 'foreign']),
            'claim_transaction' => DB::table('financial_hold_claims')->update(['financial_transaction_id' => null]),
        };
        $this->refused(fn () => $service->convert($f['hold'], $f['user']->id, 'Synthetic internal conversion evidence.'));
        $this->assertSame('900.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', $f['wallet']->fresh()->locked_balance);
        $this->assertDatabaseCount('financial_transactions', 1);
    }

    public function test_generic_reversal_cannot_undo_a_consumed_hold_even_after_header_label_is_removed(): void
    {
        $f = $this->fixture();
        $service = app(FinancialHoldService::class);
        $service->review($f['hold'], $f['user']->id, 'Synthetic local review evidence.');
        $service->convert($f['hold'], $f['user']->id, 'Synthetic local conversion authority.');
        $tx = FinancialTransaction::query()->sole();
        DB::table('financial_transactions')->where('id', $tx->id)->update(['reference_type' => null, 'reference_id' => null]);
        $this->refused(fn () => app(ReversalIntegrityGuard::class)->assertUnmanaged($tx->fresh()), 'reversal_requires_domain_workflow');
        $this->assertSame('900.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 1);
    }

    public function test_changed_input_reason_under_the_same_key_is_not_a_successful_replay(): void
    {
        $f = $this->fixture();
        $this->refused(fn () => app(FinancialHoldService::class)->place(new FinancialHoldData($f['wallet']->id,
            '100', 'THB', 'Different internal authority statement.', $f['data']->sourceReference)));
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $this->assertDatabaseCount('financial_hold_claims', 1);
    }

    public function test_changed_amount_cannot_create_a_second_claim_for_the_same_source(): void
    {
        $f = $this->fixture();
        $this->refused(fn () => app(FinancialHoldService::class)->place(new FinancialHoldData($f['wallet']->id,
            '50.00', 'THB', $f['data']->reason, $f['data']->sourceReference)));
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $this->assertDatabaseCount('financial_holds', 1);
        $this->assertDatabaseCount('financial_hold_claims', 1);
    }

    public static function invalidInputs(): array
    {
        return [['0', 'THB'], ['-1', 'THB'], ['1.001', 'THB'], ['1e2', 'THB'], ['1.00', 'EUR'], ['1000000000000000000.00', 'THB']];
    }

    #[DataProvider('invalidInputs')]
    public function test_public_dto_construction_cannot_bypass_input_validation(string $amount, string $currency): void
    {
        $f = $this->createPlayer('1000.00');
        $this->refused(fn () => app(FinancialHoldService::class)->place(new FinancialHoldData($f['wallet']->id,
            $amount, $currency, 'Synthetic internal hold authority.', 'SYNTHETIC-MALFORMED-HOLD')));
        $this->assertSame('0.00', $f['wallet']->fresh()->locked_balance);
        $this->assertDatabaseCount('financial_holds', 0);
        $this->assertDatabaseCount('financial_hold_claims', 0);
    }

    public function test_routine_rollback_cannot_drop_durable_claim_history(): void
    {
        $f = $this->fixture();
        $migration = require base_path('database/migrations/2026_10_16_000210_create_financial_hold_claims_table.php');
        try {
            $migration->down();
            $this->fail('A routine rollback erased owned financial-hold history.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('Controlled archival/migration authorization', $error->getMessage());
        }
        $this->assertDatabaseCount('financial_hold_claims', 1);
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
    }
}
