<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Finance\FinancialHoldData;
use App\DTOs\Finance\WalletReservationData;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\LedgerEntryPurpose;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Models\Payment;
use App\Services\Finance\FinancialHoldService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletHoldService;
use App\Services\Finance\WalletReservationService;
use App\Services\Finance\WalletService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Finance\WithdrawalReservationService;
use App\Services\Finance\WithdrawalService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

final class WithdrawalHoldFloorTest extends PaymentTestCase
{
    private function fixture(Currency $currency = Currency::THB): array
    {
        $f = $this->createPlayer('1000.00', $currency);
        $row = app(WithdrawalService::class)->request($f['wallet'], Money::of('100.00', $currency), PaymentMethod::BankTransfer,
            options: ['fee' => '5.00']);
        app(WithdrawalApprovalService::class)->approve($row);

        return $f + ['row' => $row->fresh()];
    }

    private function refused(callable $action): void
    {
        try {
            $action();
            $this->fail('Generic mechanics bypassed the original withdrawal floor.');
        } catch (FinancialException $error) {
            $this->assertSame('wallet_withdrawal_floor_conflict', $error->errorCode());
        }
    }

    public static function genericReleases(): array
    {
        $cases = [];
        foreach ([Currency::THB, Currency::USD, Currency::BDT] as $currency) {
            foreach (['unlock', 'alias', 'release', 'release_if_held', 'consume'] as $operation) {
                $cases[$currency->value.'-'.$operation] = [$currency, $operation];
            }
        }

        return $cases;
    }

    #[DataProvider('genericReleases')]
    public function test_generic_release_and_consume_cannot_reduce_owned_withdrawal_floor(Currency $currency, string $operation): void
    {
        $f = $this->fixture($currency);
        $amount = Money::of('100.00', $currency);
        $before = DB::table('withdrawal_reservations')->sole();
        $this->refused(fn () => match ($operation) {
            'unlock' => app(WalletService::class)->unlockFunds($f['wallet'], $amount),
            'alias' => app(WalletService::class)->unlock($f['wallet'], $amount),
            'release' => app(WalletHoldService::class)->release($f['wallet'], $amount),
            'release_if_held' => app(WalletHoldService::class)->releaseIfHeld($f['wallet'], $amount),
            'consume' => DB::transaction(fn () => app(WalletHoldService::class)->consume($f['wallet'], $amount)),
        });
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame('1000.00', $f['wallet']->fresh()->balance);
        $this->assertEquals($before, DB::table('withdrawal_reservations')->sole());
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public static function deficitActions(): array
    {
        return array_map(fn ($v) => [$v], ['hold', 'credit', 'debit', 'reverse_credit', 'reverse_debit']);
    }

    #[DataProvider('deficitActions')]
    public function test_new_holds_or_balance_changes_cannot_camouflage_existing_owner_deficit(string $operation): void
    {
        $f = $this->fixture();
        $second = app(WithdrawalService::class)->request($f['wallet'], Money::of('100.00', Currency::THB), PaymentMethod::BankTransfer);
        app(WithdrawalApprovalService::class)->approve($second);
        DB::table('wallets')->where('id', $f['wallet']->id)->update(['locked_balance' => '100.00']);
        $wallets = app(WalletService::class);
        $this->refused(fn () => match ($operation) {
            'hold' => $wallets->lockFunds($f['wallet'], Money::of('100.00', Currency::THB)),
            'credit' => $wallets->credit($f['wallet'], Money::of('100.00', Currency::THB), FinancialTransactionType::Deposit, 'synthetic-floor-credit'),
            'debit' => $wallets->debit($f['wallet'], Money::of('100.00', Currency::THB), FinancialTransactionType::Adjustment, 'synthetic-floor-debit'),
            'reverse_credit', 'reverse_debit' => DB::transaction(fn () => $wallets->applyReversal($f['wallet'], Money::of('1.00', Currency::THB),
                $operation === 'reverse_credit' ? LedgerEntryType::Credit : LedgerEntryType::Debit)),
        });
        $this->assertSame('1000.00', $f['wallet']->fresh()->balance);
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame(2, DB::table('withdrawal_reservations')->where('state', 'active')->count());
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public static function legacyLifecycles(): array
    {
        return [['reservation', 'release'], ['reservation', 'consume'], ['reservation', 'expire'],
            ['financial', 'release'], ['financial', 'convert'], ['financial', 'expire']];
    }

    #[DataProvider('legacyLifecycles')]
    public function test_generic_record_lifecycle_cannot_release_another_obligations_money(string $type, string $operation): void
    {
        $f = $this->fixture();
        if ($type === 'reservation') {
            $service = app(WalletReservationService::class);
            $legacy = $service->reserve(WalletReservationData::fromInput($f['wallet']->id, 'SYNTHETIC-FLOOR-RESERVATION', '50.00', 'THB',
                LedgerEntryPurpose::Reservation, now()->subHour()->toIso8601String()))['reservation'];
        } else {
            $service = app(FinancialHoldService::class);
            $legacy = $service->place(FinancialHoldData::fromInput($f['wallet']->id, '50.00', 'THB', 'Synthetic independent hold.',
                'SYNTHETIC-FLOOR-FINANCIAL', now()->subHour()->toIso8601String()))['hold'];
            if ($operation === 'convert') {
                $legacy = $service->review($legacy, $f['user']->id, 'Synthetic local fixture review.');
            }
        }
        // An injected historical aggregate deficit has erased only the generic
        // record's coverage. Its old row must not take the live withdrawal hold.
        DB::table('wallets')->where('id', $f['wallet']->id)->update(['locked_balance' => '100.00']);
        $before = $legacy->fresh()->getRawOriginal();
        $this->refused(fn () => match ($operation) {
            'release' => $service->release($legacy, 'Synthetic local release.'),
            'expire' => $service->expire($legacy),
            'consume' => $service->consume($legacy),
            'convert' => $service->convert($legacy, $f['user']->id, 'Synthetic conversion evidence.'),
        });
        $this->assertSame($before, $legacy->fresh()->getRawOriginal());
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame('active', DB::table('withdrawal_reservations')->sole()->state);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public static function corruptOwners(): array
    {
        return [['currency'], ['user'], ['closed'], ['zero'], ['negative']];
    }

    #[DataProvider('corruptOwners')]
    public function test_malformed_active_owner_never_lowers_the_central_floor(string $kind): void
    {
        $f = $this->fixture();
        $changes = match ($kind) {
            'currency' => ['currency' => 'USD'],
            'user' => ['user_id' => $this->createPlayer('0.00')['user']->id],
            'closed' => ['closed_at' => now()],
            'zero' => ['amount' => '0.00'],
            'negative' => ['amount' => '-1.00'],
        };
        DB::table('withdrawal_reservations')->where('withdrawal_id', $f['row']->id)->update($changes);
        $this->refused(fn () => app(WalletService::class)->unlockFunds($f['wallet'], Money::of('1.00', Currency::THB)));
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame('1000.00', $f['wallet']->fresh()->balance);
    }

    public function test_unprotected_excess_can_release_and_then_only_the_owned_withdrawal_can_settle(): void
    {
        $f = $this->fixture();
        $wallets = app(WalletService::class);
        $wallets->lockFunds($f['wallet'], Money::of('25.00', Currency::THB));
        $wallets->unlockFunds($f['wallet'], Money::of('25.00', Currency::THB));
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $out = app(WithdrawalCompletionService::class)->complete($f['row']);
        $this->assertTrue($out['debited']);
        $this->assertSame('900.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame('consumed', DB::table('withdrawal_reservations')->sole()->state);
    }

    public function test_settlement_savepoint_restores_owner_and_unlock_when_debit_fails_and_caller_catches(): void
    {
        $f = $this->fixture();
        DB::table('wallets')->where('id', $f['wallet']->id)->update(['status' => 'suspended']);
        $before = DB::table('withdrawal_reservations')->sole();
        DB::transaction(function () use ($f): void {
            try {
                app(WithdrawalReservationService::class)->settle($f['row'], $f['wallet']->fresh());
                $this->fail('Suspended wallet unexpectedly debited.');
            } catch (FinancialException) {
                $this->addToAssertionCount(1);
            }
        });
        $this->assertEquals($before, DB::table('withdrawal_reservations')->sole());
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame('1000.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_settlement_proof_failure_after_posting_rolls_back_debit_journal_unlock_and_owner(): void
    {
        $f = $this->fixture();
        Payment::query()->create(['reference_number' => 'PAY-SYNTHETIC-FLOOR-PENDING',
            'user_id' => $f['user']->id, 'payable_type' => $f['row']::class, 'payable_id' => $f['row']->id,
            'method' => PaymentMethod::BankTransfer, 'status' => 'pending', 'currency' => Currency::THB,
            'amount' => '100.00', 'fee' => '5.00', 'gateway' => 'bank_transfer', 'gateway_reference' => 'SYNTHETIC-FLOOR-PENDING']);
        $before = DB::table('withdrawal_reservations')->sole();
        DB::transaction(function () use ($f): void {
            try {
                app(WithdrawalReservationService::class)->settle($f['row'], $f['wallet']->fresh());
                $this->fail('Pending Payment was accepted as final settlement proof.');
            } catch (FinancialException $error) {
                $this->assertSame('withdrawal_original_evidence_conflict', $error->errorCode());
            }
        });
        $this->assertEquals($before, DB::table('withdrawal_reservations')->sole());
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame('1000.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_release_savepoint_restores_its_owner_after_central_floor_refuses_a_stale_snapshot(): void
    {
        $f = $this->fixture();
        $other = app(WithdrawalService::class)->request($f['wallet'], Money::of('100.00', Currency::THB), PaymentMethod::BankTransfer);
        app(WithdrawalApprovalService::class)->approve($other);
        $stale = $f['wallet']->fresh();
        DB::table('wallets')->where('id', $stale->id)->update(['locked_balance' => '100.00']);
        DB::transaction(fn () => $this->refused(fn () => app(WithdrawalReservationService::class)->release($f['row'], $stale)));
        $this->assertSame(2, DB::table('withdrawal_reservations')->where('state', 'active')->count());
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame('1000.00', $f['wallet']->fresh()->balance);
    }

    public static function staleMutations(): array
    {
        return [['credit'], ['debit'], ['reversal']];
    }

    #[DataProvider('staleMutations')]
    public function test_internal_balance_mutation_re_reads_the_actual_locked_wallet(string $operation): void
    {
        $f = $this->fixture();
        $stale = $f['wallet']->fresh();
        DB::table('wallets')->where('id', $stale->id)->update(['balance' => '800.00']);
        DB::transaction(fn () => match ($operation) {
            'credit' => app(WalletService::class)->applyCredit($stale, Money::of('1.00', Currency::THB), FinancialTransactionType::Adjustment),
            'debit' => app(WalletService::class)->applyDebit($stale, Money::of('1.00', Currency::THB), FinancialTransactionType::Adjustment),
            'reversal' => app(WalletService::class)->applyReversal($stale, Money::of('1.00', Currency::THB), LedgerEntryType::Credit),
        });
        $this->assertSame($operation === 'credit' ? '801.00' : '799.00', $stale->fresh()->balance);
        $this->assertSame('100.00', $stale->fresh()->locked_balance);
    }
}
