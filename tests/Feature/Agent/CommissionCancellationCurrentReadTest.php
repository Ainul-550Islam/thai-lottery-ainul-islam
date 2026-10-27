<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\DTOs\BetPurchaseData;
use App\Enums\Currency;
use App\Exceptions\BetDomainException;
use App\Models\AgentCommission;
use App\Models\User;
use App\Services\Agent\AgentCommissionAccrualService;
use App\Services\Agent\AgentCommissionSettlementService;
use App\Services\Betting\BetCancellationService;
use App\Services\Betting\BetPurchaseService;
use App\Services\Finance\WalletService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Agent\Concerns\BuildsCommissionCancellation;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Tests\Support\RunsServerFinancialRaces;

final class CommissionCancellationCurrentReadTest extends FinancialMasterTestCase
{
    use BuildsCommissionCancellation, RunsServerFinancialRaces;

    public static function operations(): array
    {
        return ['accrual' => ['accrual'], 'book_deficit' => ['book_deficit'], 'cancelled' => ['cancelled'], 'paid' => ['paid']];
    }

    #[DataProvider('operations')]
    public function test_commission_authority_uses_current_rows_not_the_older_snapshot(string $operation): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires independent sessions on the owned disposable SQL store.');
        }
        $f = $this->commissionFixture(parent: false);
        $third = null;
        if ($operation === 'accrual') {
            // Isolated accrual mechanics on an already funded, initially
            // unattributed synthetic sale. No nested purchase is permitted.
            $user = User::factory()->create();
            $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::THB);
            $this->creditFixture($wallet, '100.00', 'Synthetic native accrual source');
            $third = app(BetPurchaseService::class)->purchase(new BetPurchaseData(
                userId: $user->id, drawId: $f['draw']->id, marketKey: '3d_direct', rawNumber: '007', rawStake: '10.00', idempotencyKey: 'native-isolated-accrual-source'))->bet;
            $user->forceFill(['preferences' => ['referred_by_agent_id' => $f['agent']->id]])->save();
        }
        $name = DB::getDefaultConnection();
        $peer = 'commission_owned_peer';
        config(['database.connections.'.$peer => config('database.connections.'.$name)]);
        DB::purge($peer);
        $reader = DB::connection($name);
        $reader->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        if (str_contains($reader->selectOne('SELECT VERSION() AS v')->v, 'MariaDB')) {
            $reader->statement('SET SESSION innodb_snapshot_isolation = OFF');
            DB::connection($peer)->statement('SET SESSION innodb_snapshot_isolation = OFF');
        }
        $this->assertNotSame($reader->selectOne('SELECT CONNECTION_ID() AS id')->id, DB::connection($peer)->selectOne('SELECT CONNECTION_ID() AS id')->id);
        $reader->beginTransaction();
        try {
            $this->assertEquals('0.50', $reader->table('agents')->where('id', $f['agent']->id)->value('total_commission_earned'));
            $this->assertSame(1, $reader->table('bet_commission_ownerships')->count());
            $this->assertSame('accrued', $reader->table('agent_commissions')->sole()->status);
            DB::setDefaultConnection($peer);
            if (in_array($operation, ['accrual', 'book_deficit'], true)) {
                $this->secondPurchase($f);
                if ($operation === 'book_deficit') {
                    DB::table('agents')->where('id', $f['agent']->id)->update(['total_commission_earned' => '0.50']);
                }
            } elseif ($operation === 'cancelled') {
                app(BetCancellationService::class)->cancel($this->cancellation($f));
            } else {
                app(AgentCommissionSettlementService::class)->settleForDraw($f['draw']->id);
            }
            DB::setDefaultConnection($name);
            $this->assertEquals('0.50', $reader->table('agents')->where('id', $f['agent']->id)->value('total_commission_earned'));
            $this->assertSame(1, $reader->table('bet_commission_ownerships')->count());
            $this->assertSame('accrued', $reader->table('agent_commissions')->sole()->status);
            if ($operation === 'accrual') {
                app(AgentCommissionAccrualService::class)->accrueForBet($third);
            } elseif ($operation === 'cancelled') {
                $this->assertTrue(app(BetCancellationService::class)->cancel($this->cancellation($f))->isReplay());
            } else {
                try {
                    app(BetCancellationService::class)->cancel($this->cancellation($f));
                    $this->fail('Stale commission state authorized an unpaired member refund.');
                } catch (BetDomainException $error) {
                    $this->assertSame('bet_cancellation_commission_workflow_required', $error->errorCode());
                }
            }
            $reader->commit();
            $this->assertSame(match ($operation) {
                'accrual' => '1.50', 'cancelled' => '0.00', default => '0.50'
            }, $f['agent']->fresh()->total_commission_earned);
            $this->assertSame(match ($operation) {
                'accrual' => '80.00', 'book_deficit' => '80.00', 'cancelled' => '100.00', default => '90.00'
            }, $f['wallet']->fresh()->balance);
        } finally {
            DB::setDefaultConnection($name);
            while ($reader->transactionLevel() > 0) {
                $reader->rollBack();
            }
            DB::purge($peer);
        }
    }

    public function test_two_actual_requests_refund_and_cancel_both_commission_levels_once(): void
    {
        $this->requireServerRace();
        $f = $this->commissionFixture();
        $command = ['action' => 'bet_cancel', 'user_id' => $f['user']->id, 'bet_id' => $f['bet']->id];
        $out = $this->serverRace([$command, $command], 'wallets', $f['wallet']->id);
        $outcomes = array_column($out, 'outcome');
        sort($outcomes);
        $this->assertSame(['cancelled', 'replayed'], $outcomes, json_encode($out));
        $this->assertSame($out[0]['transaction_id'], $out[1]['transaction_id']);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', $f['agent']->fresh()->total_commission_earned);
        $this->assertSame('0.00', $f['parent']->fresh()->total_commission_earned);
        $this->assertSame(2, AgentCommission::where('status', 'cancelled')->count());
        $this->assertSame(2, DB::table('bet_commission_ownerships')->where('state', 'cancelled')->count());
        $this->assertDatabaseCount('financial_transactions', 3);
    }
}
