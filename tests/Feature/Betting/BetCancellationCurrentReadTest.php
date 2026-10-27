<?php

declare(strict_types=1);

namespace Tests\Feature\Betting;

use App\DTOs\BetPurchaseData;
use App\Exceptions\BetDomainException;
use App\Exceptions\RiskException;
use App\Models\NumberLimit;
use App\Services\Betting\BetCancellationService;
use App\Services\Betting\BetPurchaseService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Betting\Concerns\BuildsOwnedBetPurchase;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Tests\Support\RunsServerFinancialRaces;

final class BetCancellationCurrentReadTest extends FinancialMasterTestCase
{
    use BuildsOwnedBetPurchase, RunsServerFinancialRaces;

    public static function staleCases(): array
    {
        return ['committed_cancel' => ['committed_cancel'], 'original_journal' => ['original_journal'],
            'refund_journal' => ['refund_journal'], 'new_owner_deficit' => ['new_owner_deficit']];
    }

    #[DataProvider('staleCases')]
    public function test_current_reads_defeat_older_repeatable_read_authority(string $operation): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires two independent sessions on the owned disposable SQL test store.');
        }
        $f = $this->purchaseFixture();
        $service = app(BetCancellationService::class);
        $outcome = $operation === 'refund_journal' ? $service->cancel($this->cancelData($f)) : null;
        $name = DB::getDefaultConnection();
        $peer = 'owned_bet_current_peer';
        config(['database.connections.'.$peer => config('database.connections.'.$name)]);
        DB::purge($peer);
        $reader = DB::connection($name);
        $reader->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        if (str_contains($reader->selectOne('SELECT VERSION() AS version')->version, 'MariaDB')) {
            $reader->statement('SET SESSION innodb_snapshot_isolation = OFF');
            DB::connection($peer)->statement('SET SESSION innodb_snapshot_isolation = OFF');
        }
        $this->assertNotSame($reader->selectOne('SELECT CONNECTION_ID() AS id')->id, DB::connection($peer)->selectOne('SELECT CONNECTION_ID() AS id')->id);
        $reader->beginTransaction();
        try {
            $this->assertSame(1, $reader->table('bet_purchase_ownerships')->count());
            $journalId = $outcome?->refund->id ?? $f['claim']->original_transaction_id;
            $this->assertEquals('10.00', $reader->table('ledger_entries')->where('financial_transaction_id', $journalId)->value('amount'));
            DB::setDefaultConnection($peer);
            if ($operation === 'committed_cancel') {
                $service->cancel($this->cancelData($f));
            } elseif ($operation === 'new_owner_deficit') {
                app(BetPurchaseService::class)->purchase(new BetPurchaseData(userId: $f['user']->id, drawId: $f['draw']->id,
                    marketKey: '3d_direct', rawNumber: '007', rawStake: '10.00', idempotencyKey: 'native-committed-second-owner'));
                DB::table('number_limits')->where('id', $f['claim']->number_limit_id)->update(['current_amount' => '10.00']);
            } else {
                DB::table('ledger_entries')->where('financial_transaction_id', $journalId)->update(['amount' => '1.00']);
            }
            DB::setDefaultConnection($name);
            // Both ordinary reads STILL see the prior committed snapshot.
            $this->assertSame(1, $reader->table('bet_purchase_ownerships')->count());
            $this->assertEquals('10.00', $reader->table('ledger_entries')->where('financial_transaction_id', $journalId)->value('amount'));
            if ($operation === 'committed_cancel') {
                $replay = $service->cancel($this->cancelData($f));
                $this->assertTrue($replay->isReplay());
                $this->assertSame('10.00', $replay->refundAmount);
            } else {
                try {
                    $service->cancel($this->cancelData($f));
                    $this->fail('A stale snapshot authorized an unproved cancellation or replay.');
                } catch (BetDomainException|RiskException $error) {
                    $this->assertSame($operation === 'new_owner_deficit' ? 'bet_risk_ownership_conflict' : 'bet_cancellation_ownership_conflict', $error->errorCode());
                }
            }
            $reader->commit();
            $this->assertSame(in_array($operation, ['committed_cancel', 'refund_journal'], true) ? '100.00' : ($operation === 'new_owner_deficit' ? '80.00' : '90.00'), $f['wallet']->fresh()->balance);
            $this->assertSame($operation === 'committed_cancel' || $operation === 'refund_journal' ? '0.00' : '10.00', NumberLimit::find($f['claim']->number_limit_id)->current_amount);
        } finally {
            DB::setDefaultConnection($name);
            while ($reader->transactionLevel() > 0) {
                $reader->rollBack();
            }
            DB::purge($peer);
        }
    }

    public function test_two_actual_cancellers_wait_then_commit_one_refund_and_one_replay(): void
    {
        $this->requireServerRace();
        $f = $this->purchaseFixture();
        $command = ['action' => 'bet_cancel', 'user_id' => $f['user']->id, 'bet_id' => $f['bet']->id];
        $out = $this->serverRace([$command, $command], 'wallets', $f['wallet']->id);
        $outcomes = array_column($out, 'outcome');
        sort($outcomes);
        $this->assertSame(['cancelled', 'replayed'], $outcomes, json_encode($out));
        $this->assertSame($out[0]['transaction_id'], $out[1]['transaction_id']);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', NumberLimit::find($f['claim']->number_limit_id)->current_amount);
        $this->assertSame('cancelled', DB::table('bet_purchase_ownerships')->sole()->state);
        $this->assertDatabaseCount('financial_transactions', 3);
        $this->assertDatabaseCount('ledger_entries', 6);
    }
}
