<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Exceptions\FinancialException;
use App\Services\Agent\AgentCommissionSettlementService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Agent\Concerns\BuildsCommissionCancellation;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Tests\Support\RunsServerFinancialRaces;

final class CommissionPayoutCurrentReadTest extends FinancialMasterTestCase
{
    use BuildsCommissionCancellation;
    use RunsServerFinancialRaces;

    protected function drawPay(array $f)
    {
        return app(AgentCommissionSettlementService::class)->settleForDraw($f['draw']->id);
    }

    public static function operations(): array
    {
        return ['paid_counter' => ['paid_counter'], 'book_deficit' => ['book_deficit'],
            'paid_replay' => ['paid_replay'], 'account_hidden' => ['account_hidden']];
    }

    #[DataProvider('operations')]
    public function test_current_payout_authority_does_not_use_old_reader_rows(string $operation): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires independent SQL sessions; SQLite is not current-read proof.');
        }
        $f = $this->commissionFixture(parent: false);
        $second = $operation === 'paid_counter' ? $this->secondPurchase($f) : null;
        $name = DB::getDefaultConnection();
        $peer = 'commission_payout_owned_peer';
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
            $this->assertEquals('0.00', $reader->table('agents')->where('id', $f['agent']->id)->value('total_commission_paid'));
            $this->assertSame(0, $reader->table('agent_commission_payouts')->count());
            DB::setDefaultConnection($peer);
            if ($operation === 'paid_counter') {
                app(AgentCommissionSettlementService::class)->settleForDraw($second->bet->draw_id);
            } elseif ($operation === 'book_deficit') {
                $this->secondPurchase($f);
                DB::table('agents')->where('id', $f['agent']->id)->update(['total_commission_earned' => '0.50']);
            } else {
                $this->drawPay($f);
                if ($operation === 'account_hidden') {
                    DB::table('ledger_accounts')->where('code', '5100')->update(['deleted_at' => now()]);
                }
            }
            DB::setDefaultConnection($name);
            $this->assertEquals('0.00', $reader->table('agents')->where('id', $f['agent']->id)->value('total_commission_paid'));
            $this->assertSame(0, $reader->table('agent_commission_payouts')->count());
            if (in_array($operation, ['book_deficit', 'account_hidden'], true)) {
                try {
                    $this->drawPay($f);
                    $this->fail('Old reader data authorized an unproved payout/replay.');
                } catch (FinancialException) {
                    $this->assertTrue(true);
                }
            } else {
                $result = $this->drawPay($f);
                $this->assertSame($operation === 'paid_counter' ? 1 : 0, $result->commissionsSettled);
                $this->assertSame($operation === 'paid_replay', $result->alreadySettled);
            }
            $reader->commit();
            $this->assertSame(match ($operation) {
                'paid_counter' => '1.00', 'book_deficit' => '0.00', default => '0.50'
            }, $f['agent']->fresh()->total_commission_paid);
            $this->assertSame(match ($operation) {
                'paid_counter' => '1.00', 'book_deficit' => '0.00', default => '0.50'
            }, $f['agentWallet']->fresh()->balance);
            $this->assertSame($operation === 'paid_counter' ? 2 : ($operation === 'book_deficit' ? 0 : 1), DB::table('agent_commission_payouts')->count());
        } finally {
            DB::setDefaultConnection($name);
            while ($reader->transactionLevel() > 0) {
                $reader->rollBack();
            }
            DB::purge($peer);
        }
    }

    public function test_draw_and_manual_actual_children_compete_for_one_original_credit(): void
    {
        $this->requireServerRace();
        $f = $this->commissionFixture(parent: false);
        $out = $this->serverRace([
            ['action' => 'commission_draw', 'draw_id' => $f['draw']->id],
            ['action' => 'commission_agent', 'agent_id' => $f['agent']->id],
        ], 'wallets', $f['agentWallet']->id);
        $counts = array_column($out, 'settled');
        sort($counts);
        $this->assertSame([0, 1], $counts, json_encode($out));
        $this->assertSame('0.50', $f['agentWallet']->fresh()->balance);
        $this->assertSame('0.50', $f['agent']->fresh()->total_commission_paid);
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('agent_commission_payouts', 1);
        $this->assertDatabaseCount('financial_transactions', 3);
    }
}
