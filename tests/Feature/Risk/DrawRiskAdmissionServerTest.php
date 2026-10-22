<?php

declare(strict_types=1);

namespace Tests\Feature\Risk;

use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Exceptions\RiskException;
use App\Models\NumberLimit;
use App\Models\User;
use App\Services\Draw\DrawCancellationService;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Finance\Money;
use App\Services\Risk\NumberLimitEngine;
use App\Services\Risk\RiskAssessmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Betting\Concerns\BuildsOwnedBetPurchase;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Tests\Support\RunsServerFinancialRaces;

final class DrawRiskAdmissionServerTest extends FinancialMasterTestCase
{
    use BuildsOwnedBetPurchase;
    use RunsServerFinancialRaces;

    public static function paths(): array
    {
        return [['single'], ['many'], ['facade']];
    }

    private function command(array $f, string $path): array
    {
        return ['action' => 'risk_reserve', 'path' => $path, 'draw_id' => $f['draw']->id];
    }

    private function reserve(array $f, string $path): void
    {
        $entry = ['number' => '007', 'stake' => Money::of('10.00', Currency::THB), 'multiplier' => '1'];
        match ($path) {
            'single' => app(NumberLimitEngine::class)->reserve($f['draw']->id, BetType::ThreeD, '007', $entry['stake'], '1'),
            'many' => app(NumberLimitEngine::class)->reserveMany($f['draw']->id, BetType::ThreeD, [$entry]),
            'facade' => app(RiskAssessmentService::class)->reserveBet($f['draw']->id, BetType::ThreeD,
                [['number' => '007', 'stake' => '10.00', 'multiplier' => '1']]),
        };
    }

    #[DataProvider('paths')]
    public function test_two_risk_writers_wait_on_draw_before_limits_and_only_one_consumes_the_last_capacity(string $path): void
    {
        $this->requireServerRace();
        $f = $this->purchaseFixture();
        DB::table('number_limits')->where('id', $f['claim']->number_limit_id)->update(['max_amount' => '20.00']);
        $transactions = DB::table('financial_transactions')->count();
        $results = $this->serverRace([$this->command($f, $path), $this->command($f, $path)], 'draws', $f['draw']->id);
        $outcomes = array_column($results, 'outcome');
        sort($outcomes);
        $this->assertSame(['refused', 'risk_reserved'], $outcomes, json_encode($results));
        $this->assertSame('20.00', NumberLimit::find($f['claim']->number_limit_id)->current_amount);
        $this->assertSame($transactions, DB::table('financial_transactions')->count());
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
    }

    #[DataProvider('paths')]
    public function test_risk_racing_draw_refund_either_commits_first_and_holds_cancellation_or_refuses_after_it(string $path): void
    {
        $this->requireServerRace();
        $f = $this->purchaseFixture();
        $this->seed(RolePermissionSeeder::class);
        $actor = User::factory()->create();
        $actor->assignRole('admin');
        $results = $this->serverRace([
            ['action' => 'draw_cancel', 'actor_id' => $actor->id, 'draw_id' => $f['draw']->id], $this->command($f, $path),
        ], 'draws', $f['draw']->id);
        if ($results[1]['outcome'] === 'risk_reserved') {
            $this->assertSame('refused', $results[0]['outcome'], json_encode($results));
            $this->assertSame('draw_cancellation_unmanaged_risk_review_required', $results[0]['code']);
            $this->assertSame(DrawStatus::Open, $f['draw']->fresh()->status);
            $this->assertSame('90.00', $f['wallet']->fresh()->balance);
            $this->assertSame(0, DB::table('draw_cancellation_ownerships')->count());
            $this->assertSame('20.00', NumberLimit::find($f['claim']->number_limit_id)->current_amount);
            // Explicit release of only the test's anonymous surplus. Original
            // member risk remains owned; no guessed refund recipient is created.
            DB::transaction(fn () => app(NumberLimitEngine::class)->release($f['draw']->id, BetType::ThreeD,
                '007', Money::of('10.00', Currency::THB), '1'));
            app(DrawCancellationService::class)->cancel($actor->id, $f['draw']->id, 'Synthetic local operator cancellation');
        } else {
            $this->assertSame('draw_cancelled', $results[0]['outcome'], json_encode($results));
            $this->assertSame('refused', $results[1]['outcome']);
            $this->assertSame('DRAW_RISK_ADMISSION_REFUSED', $results[1]['code']);
        }
        $this->assertSame(DrawStatus::Cancelled, $f['draw']->fresh()->status);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', NumberLimit::find($f['claim']->number_limit_id)->current_amount);
        $this->assertSame(1, DB::table('draw_cancellation_ownerships')->count());
    }

    public static function staleCases(): array
    {
        $out = [];
        foreach (['single', 'many', 'facade'] as $path) {
            foreach (['close', 'window', 'owner'] as $operation) {
                $out[$path.'-'.$operation] = [$path, $operation];
            }
        }

        return $out;
    }

    #[DataProvider('staleCases')]
    public function test_old_repeatable_read_snapshot_never_grants_admission_after_a_peer_commits_a_barrier(string $path, string $operation): void
    {
        $this->requireServerRace();
        $f = $this->purchaseFixture();
        $name = DB::getDefaultConnection();
        $peerName = 'draw_risk_current_peer';
        config(['database.connections.'.$peerName => config('database.connections.'.$name), 'lottery.betting.require_open_draw' => false]);
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
            $this->assertSame('open', $reader->table('draws')->where('id', $f['draw']->id)->value('status'));
            $this->assertSame(0, $reader->table('draw_cancellation_ownerships')->count());
            $oldClose = $reader->table('draws')->where('id', $f['draw']->id)->value('betting_close_at');
            DB::setDefaultConnection($peerName);
            match ($operation) {
                'close' => app(DrawLifecycleService::class)->close($f['draw']),
                'window' => $peer->table('draws')->where('id', $f['draw']->id)->update(['betting_close_at' => now()->subMinute()]),
                'owner' => $peer->table('draw_cancellation_ownerships')->insert(['draw_id' => $f['draw']->id,
                    'actor_user_id' => $f['user']->id, 'previous_status' => 'open', 'reason' => 'Synthetic unresolved owner barrier',
                    'participants' => '[]', 'terms_hash' => str_repeat('0', 64), 'state' => 'preparing', 'created_at' => now(), 'updated_at' => now()]),
            };
            DB::setDefaultConnection($name);
            // These reads actually STILL see the older snapshot. A server's
            // automatic snapshot-conflict abort cannot substitute for this proof.
            $this->assertSame('open', $reader->table('draws')->where('id', $f['draw']->id)->value('status'));
            $this->assertSame(0, $reader->table('draw_cancellation_ownerships')->count());
            $this->assertSame($oldClose, $reader->table('draws')->where('id', $f['draw']->id)->value('betting_close_at'));
            try {
                $this->reserve($f, $path);
                $this->fail('Old snapshot granted new risk.');
            } catch (RiskException $e) {
                $this->assertSame('DRAW_RISK_ADMISSION_REFUSED', $e->errorCode());
                $this->assertSame($operation === 'owner' ? 'draw_cancellation_exists' : 'draw_not_open', $e->contextValue('reason'));
                $this->assertSame(1, $reader->transactionLevel());
            }
            $reader->commit();
            $this->assertSame('10.00', NumberLimit::find($f['claim']->number_limit_id)->current_amount);
            $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        } finally {
            DB::setDefaultConnection($name);
            while ($reader->transactionLevel() > 0) {
                $reader->rollBack();
            }
            if ($mode !== null) {
                $reader->statement('SET SESSION innodb_snapshot_isolation = '.($mode === 1 ? 'ON' : 'OFF'));
            }
            DB::purge($peerName);
        }
    }
}
