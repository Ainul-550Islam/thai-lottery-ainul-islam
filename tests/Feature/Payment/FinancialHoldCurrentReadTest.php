<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Finance\FinancialHoldData;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Exceptions\FinancialException;
use App\Services\Finance\FinancialHoldService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** Two real SQL sessions overlap; the reader snapshot predates the committed new owner. */
final class FinancialHoldCurrentReadTest extends PaymentTestCase
{
    public static function operations(): array
    {
        return [['release'], ['spend_deficit'], ['mask_deficit']];
    }

    #[DataProvider('operations')]
    public function test_current_claim_read_survives_an_older_repeatable_read_snapshot(string $operation): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires owned disposable MySQL/MariaDB test store; SQLite is not current-read proof.');
        }
        $f = $this->createPlayer('1000.00');
        $name = DB::getDefaultConnection();
        $peer = 'financial_hold_owned_peer';
        config(['database.connections.'.$peer => config('database.connections.'.$name)]);
        DB::purge($peer);
        $reader = DB::connection($name);
        $reader->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        // MariaDB snapshot-abort protection is disabled ONLY in these owned test sessions
        // so a missing current-read guard cannot pass merely because the engine aborts.
        if (str_contains($reader->selectOne('SELECT VERSION() AS version')->version, 'MariaDB')) {
            $reader->statement('SET SESSION innodb_snapshot_isolation = OFF');
            DB::connection($peer)->statement('SET SESSION innodb_snapshot_isolation = OFF');
        }
        $this->assertNotSame($reader->selectOne('SELECT CONNECTION_ID() AS id')->id,
            DB::connection($peer)->selectOne('SELECT CONNECTION_ID() AS id')->id);
        $reader->beginTransaction();
        try {
            $this->assertSame(0, $reader->table('financial_holds')->count());
            $this->assertSame(0, $reader->table('financial_hold_claims')->count());
            DB::setDefaultConnection($peer);
            app(FinancialHoldService::class)->place(FinancialHoldData::fromInput($f['wallet']->id, '100.00', 'THB',
                'Synthetic native session hold evidence.', 'SYNTHETIC-NATIVE-OWNED-HOLD'));
            $locked = match ($operation) {
                'release' => '100.00', 'spend_deficit' => '50.00', 'mask_deficit' => '0.00'
            };
            DB::table('wallets')->where('id', $f['wallet']->id)->update(['locked_balance' => $locked]);
            DB::setDefaultConnection($name);
            // Ordinary reads still see the old empty snapshot. A locking read must not.
            $this->assertSame(0, $reader->table('financial_holds')->count());
            $this->assertSame(0, $reader->table('financial_hold_claims')->count());
            try {
                match ($operation) {
                    'release' => app(WalletService::class)->unlockFunds($f['wallet'], Money::of('1.00', Currency::THB)),
                    'spend_deficit' => app(WalletService::class)->debit($f['wallet'], Money::of('1.00', Currency::THB), FinancialTransactionType::Adjustment, 'synthetic-native-hold-debit'),
                    'mask_deficit' => app(WalletService::class)->lockFunds($f['wallet'], Money::of('100.00', Currency::THB)),
                };
                $this->fail('An old snapshot hid the committed financial-hold owner.');
            } catch (FinancialException $error) {
                $this->assertSame('wallet_withdrawal_floor_conflict', $error->errorCode());
            }
            $reader->commit();
            $this->assertSame($locked, $f['wallet']->fresh()->locked_balance);
            $this->assertSame('1000.00', $f['wallet']->fresh()->balance);
            $this->assertSame('active', DB::table('financial_hold_claims')->sole()->state);
            $this->assertDatabaseCount('financial_transactions', 0);
            $this->assertDatabaseCount('ledger_entries', 0);
        } finally {
            DB::setDefaultConnection($name);
            while ($reader->transactionLevel() > 0) {
                $reader->rollBack();
            }
            DB::purge($peer);
        }
    }

    public function test_terminal_proof_is_current_even_when_an_old_snapshot_contains_a_good_journal(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires owned disposable MySQL/MariaDB test store.');
        }
        $f = $this->createPlayer('1000.00');
        $service = app(FinancialHoldService::class);
        $hold = $service->place(FinancialHoldData::fromInput($f['wallet']->id, '100.00', 'THB',
            'Synthetic native replay authority.', 'SYNTHETIC-NATIVE-REPLAY-HOLD'))['hold'];
        $service->review($hold, $f['user']->id, 'Synthetic native local hold review.');
        $service->convert($hold, $f['user']->id, 'Synthetic native local conversion evidence.');
        $name = DB::getDefaultConnection();
        $peer = 'financial_hold_replay_peer';
        config(['database.connections.'.$peer => config('database.connections.'.$name)]);
        DB::purge($peer);
        $reader = DB::connection($name);
        $reader->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        if (str_contains($reader->selectOne('SELECT VERSION() AS version')->version, 'MariaDB')) {
            $reader->statement('SET SESSION innodb_snapshot_isolation = OFF');
            DB::connection($peer)->statement('SET SESSION innodb_snapshot_isolation = OFF');
        }
        $reader->beginTransaction();
        try {
            $this->assertEquals('100.00', $reader->table('ledger_entries')->where('type', 'credit')->value('amount'));
            DB::connection($peer)->table('ledger_entries')->where('type', 'credit')->update(['amount' => '1.00']);
            $this->assertEquals('100.00', $reader->table('ledger_entries')->where('type', 'credit')->value('amount'));
            try {
                $service->convert($hold, $f['user']->id, 'Synthetic native local conversion evidence.');
                $this->fail('A stale good journal authorized terminal replay after a committed corruption.');
            } catch (FinancialException $error) {
                $this->assertSame('financial_hold_ownership_conflict', $error->errorCode());
            }
            $reader->commit();
            $this->assertSame('900.00', $f['wallet']->fresh()->balance);
            $this->assertSame('0.00', $f['wallet']->fresh()->locked_balance);
            $this->assertDatabaseCount('financial_transactions', 1);
        } finally {
            DB::setDefaultConnection($name);
            while ($reader->transactionLevel() > 0) {
                $reader->rollBack();
            }
            DB::purge($peer);
        }
    }
}
