<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\DTOs\Finance\FinancialHoldData;
use App\Exceptions\BetDomainException;
use App\Exceptions\FinancialException;
use App\Models\Wallet;
use App\Services\Agent\AgentCommissionSettlementService;
use App\Services\Betting\BetCancellationService;
use App\Services\Finance\FinancialHoldService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Agent\Concerns\BuildsPaidCommissionCancellation;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Tests\Support\RunsServerFinancialRaces;

final class PaidCommissionCurrentReadTest extends FinancialMasterTestCase
{
    use BuildsPaidCommissionCancellation;
    use RunsServerFinancialRaces;

    public static function peerOperations(): array
    {
        return [['spent'], ['hold'], ['book_deficit'], ['receipt_corrupt'], ['compensated'], ['new_payout']];
    }

    #[DataProvider('peerOperations')]
    public function test_current_paid_leg_wins_over_old_repeatable_read_snapshot(string $operation): void
    {
        $this->requireServerRace();
        $this->enablePolicy();
        $f = $operation === 'new_payout' ? $this->commissionFixture() : $this->paidFixture();
        $name = DB::getDefaultConnection();
        $peer = 'paid_compensation_committed_peer';
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
            $this->assertSame('0.50', DB::table('agents')->where('id', $f['agent']->id)->value('total_commission_earned'));
            $this->assertSame('0.00', DB::table('wallets')->where('id', $f['agentWallet']->id)->value('locked_balance'));
            $this->assertSame(0, DB::table('agent_commission_compensations')->count());
            $this->assertSame($operation === 'new_payout' ? 0 : 2, DB::table('agent_commission_payouts')->count());
            DB::setDefaultConnection($peer);
            switch ($operation) {
                // A model created on the reader remains bound to that named
                // connection even after switching the default. Requery through
                // the peer so the committed debit is really a peer write and
                // satisfies the canonical-writer guard.
                case 'spent': $this->debitFixture(Wallet::query()->findOrFail($f['agentWallet']->id), '0.50', 'Synthetic paid commission spent by committed peer');
                    break;
                case 'hold': app(FinancialHoldService::class)->place(FinancialHoldData::fromInput($f['agentWallet']->id, '0.50', 'THB', 'Synthetic peer owned hold', 'synthetic-peer-commission-hold'));
                    break;
                case 'book_deficit': DB::table('agents')->where('id', $f['agent']->id)->update(['total_commission_paid' => '0.49']);
                    break;
                case 'receipt_corrupt': DB::table('agent_commission_payouts')->where('wallet_id', $f['agentWallet']->id)->update(['original_hash' => str_repeat('0', 64)]);
                    break;
                case 'compensated': app(BetCancellationService::class)->cancel($this->cancellation($f));
                    break;
                case 'new_payout': app(AgentCommissionSettlementService::class)->settleForDraw($f['draw']->id);
                    break;
            }
            $before = $this->snapshot();
            DB::setDefaultConnection($name);
            $this->assertSame('0.00', DB::table('wallets')->where('id', $f['agentWallet']->id)->value('locked_balance'));
            $this->assertSame(0, DB::table('agent_commission_compensations')->count());
            if ($operation === 'compensated') {
                $this->assertTrue(app(BetCancellationService::class)->cancel($this->cancellation($f))->isReplay());
            } else {
                try {
                    app(BetCancellationService::class)->cancel($this->cancellation($f));
                    $this->fail('A stale paid-leg snapshot authorized fresh movement.');
                } catch (BetDomainException|FinancialException $error) {
                    $this->assertNotSame('', $error->getMessage());
                }
            }
            DB::setDefaultConnection($peer);
            $this->assertSame($before, $this->snapshot());
        } finally {
            DB::setDefaultConnection($name);
            while ($reader->transactionLevel() > 0) {
                $reader->rollBack();
            }
            DB::purge($peer);
        }
    }

    public function test_two_real_paid_cancellers_wait_on_the_first_sorted_wallet_then_refund_once(): void
    {
        $this->requireServerRace();
        $f = $this->paidFixture();
        $parentWallet = Wallet::where('user_id', $f['parent']->user_id)->sole();
        $this->assertLessThan($parentWallet->id, $f['agentWallet']->id);
        $this->assertLessThan($f['wallet']->id, $f['agentWallet']->id);
        $command = ['action' => 'bet_cancel', 'user_id' => $f['user']->id, 'bet_id' => $f['bet']->id];
        $out = $this->serverRace([$command, $command], 'wallets', $f['agentWallet']->id,
            ['agent.commission.paid_cancellation.enabled' => true,
                'agent.commission.paid_cancellation.policy_reference' => 'synthetic-local-agent-contract-v1']);
        $outcomes = array_column($out, 'outcome');
        sort($outcomes);
        $this->assertSame(['cancelled', 'replayed'], $outcomes, json_encode($out));
        $this->assertSame($out[0]['transaction_id'], $out[1]['transaction_id']);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', $parentWallet->fresh()->balance);
        $this->assertSame('0.00', $f['agentWallet']->fresh()->balance);
        $this->assertDatabaseCount('agent_commission_compensations', 2);
        $this->assertSame(['cancelled', 'cancelled'], DB::table('bet_commission_ownerships')->orderBy('id')->pluck('state')->all());
    }
}
