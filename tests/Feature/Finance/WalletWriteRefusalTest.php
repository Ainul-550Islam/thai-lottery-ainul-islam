<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\LedgerEntryType;
use App\Enums\WalletStatus;
use App\Exceptions\FinancialException;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

final class WalletWriteRefusalTest extends FinancialMasterTestCase
{
    private function fixture(string $operation, Currency $currency): Wallet
    {
        return Wallet::factory()->create(['currency' => $currency, 'balance' => '100.00', 'locked_balance' => '20.00', 'status' => $operation === 'unfreeze' ? WalletStatus::Locked : WalletStatus::Active, 'locked_reason' => 'synthetic existing reserve', 'locked_at' => '2026-10-21 01:00:00']);
    }

    private function invoke(string $operation, Wallet $wallet): Money|Wallet
    {
        $service = app(WalletService::class);
        $amount = Money::of('10.00', $wallet->currency);

        return match ($operation) {
            'credit' => $service->applyCredit($wallet, $amount, FinancialTransactionType::Deposit),
            'debit' => $service->applyDebit($wallet, $amount, FinancialTransactionType::Withdrawal),
            'reversal-credit' => $service->applyReversal($wallet, $amount, LedgerEntryType::Credit),
            'reversal-debit' => $service->applyReversal($wallet, $amount, LedgerEntryType::Debit),
            'reserve' => $service->lockFunds($wallet, $amount, 'synthetic requested reserve'),
            'release' => $service->unlockFunds($wallet, $amount),
            'freeze' => $service->lockWallet($wallet, 'synthetic requested freeze'),
            'unfreeze' => $service->unlockWallet($wallet),
        };
    }

    public static function refusals(): array
    {
        $rows = [];
        foreach (['credit', 'debit', 'reversal-credit', 'reversal-debit', 'reserve', 'release', 'freeze', 'unfreeze'] as $operation) {
            foreach (['saving', 'updating'] as $event) {
                foreach ([Currency::THB, Currency::BDT] as $currency) {
                    foreach ([1, 2] as $depth) {
                        $rows[$operation.'-'.$event.'-'.$currency->value.'-'.$depth] = [$operation, $event, $currency, $depth];
                    }
                }
            }
        }

        return $rows;
    }

    #[DataProvider('refusals')]
    public function test_refused_wallet_save_never_returns_a_claimed_movement_or_availability_state(string $operation, string $event, Currency $currency, int $depth): void
    {
        $wallet = $this->fixture($operation, $currency);
        $attributes = $wallet->getAttributes();
        $marker = User::factory()->create();
        $before = LedgerPostingCurrentReadTest::book();
        DB::beginTransaction();
        DB::table('users')->where('id', $marker->id)->update(['name' => 'wallet refusal caller marker']);
        if ($depth === 2) {
            DB::beginTransaction();
        }
        $originalDispatcher = Model::getEventDispatcher();
        $dispatcher = clone $originalDispatcher;
        Model::setEventDispatcher($dispatcher);
        $calls = 0;
        $dispatcher->listen('eloquent.'.$event.': '.Wallet::class, function (Wallet $candidate) use ($wallet, &$calls): ?bool {
            if ((int) $candidate->getKey() !== (int) $wallet->id) {
                return null;
            }
            $calls++;

            return false;
        });
        $error = null;
        $returned = null;
        try {
            $returned = $this->invoke($operation, $wallet);
        } catch (Throwable $caught) {
            $error = $caught;
        } finally {
            Model::setEventDispatcher($originalDispatcher);
        }
        $actualDepth = DB::transactionLevel();
        $physical = DB::connection()->getPdo()->inTransaction();
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }
        $stored = $wallet->fresh();
        $claim = $returned instanceof Money ? ['amount' => $returned->toString()] : ($returned instanceof Wallet ? ['balance' => $returned->balance, 'locked_balance' => $returned->locked_balance, 'status' => $returned->status->value, 'version' => $returned->version] : null);
        $diagnostic = json_encode(['exception' => $error === null ? null : $error::class, 'domain_code' => $error instanceof FinancialException ? $error->errorCode() : null, 'claimed' => $claim, 'stored_balance' => $stored->balance, 'stored_locked_balance' => $stored->locked_balance, 'stored_status' => $stored->status->value, 'stored_version' => $stored->version, 'committed_total_entries' => DB::table('ledger_entries')->count(), 'committed_caller_marker_rows' => (int) ($marker->fresh()->name === 'wallet refusal caller marker'), 'caller_depth' => $actualDepth, 'physical' => $physical], JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(FinancialException::class, $error, $diagnostic);
        $this->assertSame('wallet_write_refused', $error->errorCode(), $diagnostic);
        $this->assertSame(['wallet_id' => (int) $wallet->id, 'operation' => str_starts_with($operation, 'reversal-') ? 'reversal' : $operation], $error->context());
        $this->assertNull($error->getPrevious());
        $this->assertNull($returned);
        $this->assertSame(1, $calls);
        $this->assertSame($depth, $actualDepth);
        $this->assertTrue($physical);
        $this->assertSame($attributes, $wallet->getAttributes());
        $this->assertSame($before, LedgerPostingCurrentReadTest::book(), $diagnostic);
        $this->assertSame('wallet refusal caller marker', $marker->fresh()->name);
        $this->assertSame($originalDispatcher, Model::getEventDispatcher());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public static function orchestrated(): array
    {
        $rows = [];
        foreach (['credit', 'debit'] as $direction) {
            foreach (['saving', 'updating'] as $event) {
                foreach ([Currency::THB, Currency::BDT] as $currency) {
                    foreach (['owned', 'ambient'] as $owner) {
                        $rows[$direction.'-'.$event.'-'.$currency->value.'-'.$owner] = [$direction, $event, $currency, $owner];
                    }
                }
            }
        }

        return $rows;
    }

    #[DataProvider('orchestrated')]
    public function test_refused_orchestrated_movement_rolls_back_its_owned_unit(string $direction, string $event, Currency $currency, string $owner): void
    {
        $wallet = Wallet::factory()->create(['currency' => $currency]);
        $this->creditFixture($wallet, '100.00', 'Part88 synthetic opening funds');
        $wallet->refresh();
        $attributes = $wallet->getAttributes();
        $marker = User::factory()->create();
        $before = LedgerPostingCurrentReadTest::book();
        if ($owner === 'ambient') {
            DB::beginTransaction();
            DB::table('users')->where('id', $marker->id)->update(['name' => 'wallet refusal caller marker']);
        }
        $originalDispatcher = Model::getEventDispatcher();
        $dispatcher = clone $originalDispatcher;
        Model::setEventDispatcher($dispatcher);
        $calls = 0;
        $dispatcher->listen('eloquent.'.$event.': '.Wallet::class, function (Wallet $candidate) use ($wallet, &$calls): ?bool {
            if ((int) $candidate->getKey() !== (int) $wallet->id) {
                return null;
            }
            $calls++;

            return false;
        });
        $key = 'part88-refused:'.$direction;
        $error = null;
        $returned = null;
        try {
            $returned = app(WalletService::class)->{$direction}($wallet, Money::of('10.00', $currency), idempotencyKey: $key);
        } catch (Throwable $caught) {
            $error = $caught;
        } finally {
            Model::setEventDispatcher($originalDispatcher);
        }
        $actualDepth = DB::transactionLevel();
        $physical = DB::connection()->getPdo()->inTransaction();
        if ($owner === 'ambient') {
            DB::commit();
        } else {
            DB::transaction(fn () => DB::table('users')->where('id', $marker->id)->update(['name' => 'wallet refusal caller marker']));
        }
        $diagnostic = json_encode(['exception' => $error === null ? null : $error::class, 'domain_code' => $error instanceof FinancialException ? $error->errorCode() : null, 'returned_status' => $returned?->status->value, 'stored_balance' => $wallet->fresh()->balance, 'committed_target_transactions' => DB::table('financial_transactions')->where('idempotency_key', $key)->count(), 'committed_total_entries' => DB::table('ledger_entries')->count(), 'committed_caller_marker_rows' => (int) ($marker->fresh()->name === 'wallet refusal caller marker'), 'caller_depth' => $actualDepth, 'physical' => $physical], JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(FinancialException::class, $error, $diagnostic);
        $this->assertSame('wallet_write_refused', $error->errorCode(), $diagnostic);
        $this->assertSame(['wallet_id' => (int) $wallet->id, 'operation' => $direction], $error->context());
        $this->assertNull($returned);
        $this->assertSame(1, $calls);
        $this->assertSame($owner === 'ambient' ? 1 : 0, $actualDepth);
        $this->assertSame($owner === 'ambient', $physical);
        $this->assertSame($attributes, $wallet->getAttributes());
        $this->assertSame($before, LedgerPostingCurrentReadTest::book(), $diagnostic);
        $this->assertSame(0, DB::table('financial_transactions')->where('idempotency_key', $key)->count());
        $this->assertSame('wallet refusal caller marker', $marker->fresh()->name);
        $this->assertSame($originalDispatcher, Model::getEventDispatcher());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public static function allowed(): array
    {
        $rows = [];
        foreach (['credit', 'debit', 'reversal-credit', 'reversal-debit', 'reserve', 'release', 'freeze', 'unfreeze'] as $operation) {
            foreach ([Currency::THB, Currency::BDT] as $currency) {
                $rows[$operation.'-'.$currency->value] = [$operation, $currency];
            }
        }

        return $rows;
    }

    #[DataProvider('allowed')]
    public function test_nonrefusing_listeners_preserve_each_mutation(string $operation, Currency $currency): void
    {
        $wallet = $this->fixture($operation, $currency);
        $originalDispatcher = Model::getEventDispatcher();
        $dispatcher = clone $originalDispatcher;
        Model::setEventDispatcher($dispatcher);
        $calls = 0;
        foreach (['saving', 'updating'] as $event) {
            $dispatcher->listen('eloquent.'.$event.': '.Wallet::class, function () use ($currency, &$calls): ?bool {
                $calls++;

                return $currency === Currency::THB ? true : null;
            });
        }
        DB::beginTransaction();
        try {
            $this->invoke($operation, $wallet);
        } finally {
            Model::setEventDispatcher($originalDispatcher);
        }
        DB::commit();
        $stored = $wallet->fresh();
        $this->assertSame(2, $calls);
        $this->assertSame((int) $wallet->version + 1, $stored->version);
        $this->assertSame(match ($operation) {
            'credit', 'reversal-debit' => '110.00', 'debit', 'reversal-credit' => '90.00', default => '100.00'
        }, $stored->balance);
        $this->assertSame(match ($operation) {
            'reserve' => '30.00', 'release' => '10.00', default => '20.00'
        }, $stored->locked_balance);
        $this->assertSame($operation === 'freeze' ? WalletStatus::Locked : WalletStatus::Active, $stored->status);
        $this->assertSame($operation === 'credit' ? '10.00' : '0.00', $stored->total_deposited);
        $this->assertSame($operation === 'debit' ? '10.00' : '0.00', $stored->total_withdrawn);
        $this->assertSame(0, DB::table('ledger_entries')->count());
        $this->assertSame($originalDispatcher, Model::getEventDispatcher());
    }

    public static function retries(): array
    {
        return [['credit', Currency::THB], ['credit', Currency::BDT], ['debit', Currency::THB], ['debit', Currency::BDT]];
    }

    #[DataProvider('retries')]
    public function test_refused_key_is_not_consumed_and_retry_completes_once(string $direction, Currency $currency): void
    {
        $wallet = Wallet::factory()->create(['currency' => $currency]);
        $this->creditFixture($wallet, '100.00', 'Part88 synthetic retry opening funds');
        $wallet->refresh();
        $originalDispatcher = Model::getEventDispatcher();
        $dispatcher = clone $originalDispatcher;
        Model::setEventDispatcher($dispatcher);
        $dispatcher->listen('eloquent.saving: '.Wallet::class, fn () => false);
        $key = 'part88-retry:'.$direction;
        try {
            app(WalletService::class)->{$direction}($wallet, Money::of('10.00', $currency), idempotencyKey: $key);
            $this->fail('The veto must abort the first attempt.');
        } catch (FinancialException $error) {
            $this->assertSame('wallet_write_refused', $error->errorCode());
            $this->assertSame(0, DB::table('financial_transactions')->where('idempotency_key', $key)->count());
        } finally {
            Model::setEventDispatcher($originalDispatcher);
        }
        $first = app(WalletService::class)->{$direction}($wallet, Money::of('10.00', $currency), idempotencyKey: $key);
        $replay = app(WalletService::class)->{$direction}($wallet, Money::of('10.00', $currency), idempotencyKey: $key);
        $this->assertSame($first->id, $replay->id);
        $this->assertSame('completed', $first->status->value);
        $this->assertSame($direction === 'credit' ? '110.00' : '90.00', $wallet->fresh()->balance);
        $this->assertSame(1, DB::table('financial_transactions')->where('idempotency_key', $key)->count());
        $this->assertSame(4, DB::table('ledger_entries')->count());
        $this->assertSame(0, DB::transactionLevel());
    }
}
