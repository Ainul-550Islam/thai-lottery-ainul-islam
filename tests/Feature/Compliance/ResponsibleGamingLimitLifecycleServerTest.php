<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\DTOs\ResponsibleGaming\ResponsibleGamingLimitData;
use App\Services\ResponsibleGaming\ResponsibleGamingLimitService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Betting\Concerns\BuildsOwnedBetPurchase;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Tests\Support\RunsServerFinancialRaces;

final class ResponsibleGamingLimitLifecycleServerTest extends FinancialMasterTestCase
{
    use BuildsOwnedBetPurchase;
    use RunsServerFinancialRaces;

    public static function requestRaces(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('requestRaces')]
    public function test_same_explicit_request_contends_on_player_and_can_never_fork_or_change_payload(bool $conflict): void
    {
        $this->requireServerRace();
        $f = $this->purchaseFixture();
        $base = ['action' => 'limit_lifecycle', 'operation' => 'pronounce', 'user_id' => $f['user']->id, 'key' => 'shared-policy-request'];
        $before = DB::table('financial_transactions')->count();
        $results = $this->serverRace([$base + ['amount' => '20.00'], $base + ['amount' => $conflict ? '30.00' : '20.00']], 'users', $f['user']->id);
        $outcomes = array_column($results, 'outcome');
        sort($outcomes);
        $this->assertSame($conflict ? ['policy_created', 'refused'] : ['policy_created', 'policy_replayed'], $outcomes, json_encode($results));
        $this->assertSame(1, DB::table('responsible_gaming_limit_versions')->count());
        $this->assertSame(1, DB::table('responsible_gaming_limit_ownerships')->count());
        $this->assertSame($before, DB::table('financial_transactions')->count());
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
    }

    private function duePolicy(array $f): void
    {
        $this->travel(-25)->hours();
        $svc = app(ResponsibleGamingLimitService::class);
        $svc->pronounce(new ResponsibleGamingLimitData($f['user']->id, 'single_bet', '20.00', 'THB', requestKey: 'initial-owned-policy'));
        $svc->pronounce(new ResponsibleGamingLimitData($f['user']->id, 'single_bet', '30.00', 'THB', requestKey: 'pending-owned-policy'));
        $this->travelBack();
    }

    public function test_due_increase_racing_fresh_lowering_can_never_undo_the_safer_choice(): void
    {
        $this->requireServerRace();
        $f = $this->purchaseFixture();
        $this->duePolicy($f);
        $base = ['action' => 'limit_lifecycle', 'user_id' => $f['user']->id];
        $r = $this->serverRace([$base + ['operation' => 'activate'], $base + ['operation' => 'pronounce', 'amount' => '5.00', 'key' => 'fresh-safer-policy']], 'users', $f['user']->id);
        $this->assertSame('swept', $r[0]['outcome'], json_encode($r));
        $this->assertSame('policy_created', $r[1]['outcome'], json_encode($r));
        $svc = app(ResponsibleGamingLimitService::class);
        $this->assertSame('5.00', $svc->getActiveSingleBetLimit($f['user']->id));
        $this->assertSame(0, $svc->activateDuePending());
        $this->assertSame('5.00', $svc->getActiveSingleBetLimit($f['user']->id));
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
    }

    public function test_atomic_summary_reduction_and_actual_purchase_share_the_same_decisive_player_lock(): void
    {
        $this->requireServerRace();
        $f = $this->purchaseFixture();
        $svc = app(ResponsibleGamingLimitService::class);
        $svc->setSingleBetLimit($f['user']->id, '20.00');
        $svc->setSingleBetLimit($f['user']->id, '30.00');
        $r = $this->serverRace([['action' => 'limit_lifecycle', 'operation' => 'summary', 'user_id' => $f['user']->id],
            ['action' => 'wager_context', 'user_id' => $f['user']->id, 'draw_id' => $f['draw']->id,
                'wallet_id' => $f['wallet']->id, 'key' => 'buy-racing-safer-summary']], 'users', $f['user']->id);
        $this->assertSame('summary', $r[0]['outcome'], json_encode($r));
        $this->assertSame('5.00', $r[0]['binding']);
        $this->assertContains($r[1]['outcome'], ['purchased', 'refused'], json_encode($r));
        $this->assertSame($r[1]['outcome'] === 'purchased' ? '80.00' : '90.00', $f['wallet']->fresh()->balance);
        $this->assertSame('5.00', $svc->getActiveSingleBetLimit($f['user']->id));
        $this->assertSame(0, $svc->activateDuePending());
    }

    public static function stalePolicies(): array
    {
        return [['lower'], ['ban']];
    }

    #[DataProvider('stalePolicies')]
    public function test_old_snapshot_of_due_pending_cannot_override_current_reduction_or_account_protection(string $action): void
    {
        $this->requireServerRace();
        $f = $this->purchaseFixture();
        $this->duePolicy($f);
        $name = DB::getDefaultConnection();
        $peerName = 'limit_lifecycle_peer';
        config(['database.connections.'.$peerName => config('database.connections.'.$name)]);
        DB::purge($peerName);
        $reader = DB::connection($name);
        $peer = DB::connection($peerName);
        $reader->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $mode = null;
        if (str_contains(strtolower($reader->selectOne('SELECT VERSION() AS v')->v), 'mariadb')) {
            $mode = (int) $reader->selectOne('SELECT @@SESSION.innodb_snapshot_isolation AS v')->v;
            $reader->statement('SET SESSION innodb_snapshot_isolation = OFF');
            $peer->statement('SET SESSION innodb_snapshot_isolation = OFF');
        }
        $this->assertNotSame($reader->selectOne('SELECT CONNECTION_ID() AS id')->id, $peer->selectOne('SELECT CONNECTION_ID() AS id')->id);
        $reader->beginTransaction();
        try {
            $before = $reader->table('responsible_gaming_limit_versions')->where('limit_status', 'pending')->count();
            $this->assertSame(1, $before);
            $this->assertSame('active', $reader->table('users')->where('id', $f['user']->id)->value('status'));
            DB::setDefaultConnection($peerName);
            try {
                if ($action === 'lower') {
                    app(ResponsibleGamingLimitService::class)->pronounce(new ResponsibleGamingLimitData($f['user']->id,
                        'single_bet', '5.00', 'THB', requestKey: 'peer-safer-policy'));
                } else {
                    $peer->table('users')->where('id', $f['user']->id)->update(['status' => 'banned']);
                }
            } finally {
                DB::setDefaultConnection($name);
            }
            $this->assertSame($before, $reader->table('responsible_gaming_limit_versions')->where('limit_status', 'pending')->count());
            $this->assertSame('active', $reader->table('users')->where('id', $f['user']->id)->value('status'));
            $this->assertSame(0, app(ResponsibleGamingLimitService::class)->activateDuePending());
            $reader->commit();
            $this->assertSame($action === 'lower' ? '5.00' : '20.00', app(ResponsibleGamingLimitService::class)->getActiveSingleBetLimit($f['user']->id));
            $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        } finally {
            if ($reader->transactionLevel() > 0) {
                $reader->rollBack();
            }
            if ($mode !== null) {
                $reader->statement('SET SESSION innodb_snapshot_isolation = '.($mode ? 'ON' : 'OFF'));
            }
            DB::purge($peerName);
        }
    }
}
