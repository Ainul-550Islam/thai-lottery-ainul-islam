<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\DTOs\ResponsibleGaming\ResponsibleGamingLimitData;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\ResponsibleGamingLimitType;
use App\Enums\WalletType;
use App\Exceptions\FinancialException;
use App\Exceptions\ResponsibleGamingLimitException;
use App\Models\Deposit;
use App\Models\Wallet;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;
use App\Services\ResponsibleGaming\ResponsibleGamingLimitService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Tests\Support\RunsServerFinancialRaces;

final class PlayerDepositAdmissionServerTest extends FinancialMasterTestCase
{
    use RunsServerFinancialRaces;

    private function fixture(): array
    {
        $f = $this->createPlayer('0.00');
        $f['other'] = Wallet::factory()->for($f['user'])->create(['currency' => Currency::THB, 'type' => WalletType::Bonus]);
        $this->assertNotSame($f['wallet']->id, $f['other']->id);

        return $f;
    }

    private function cap(array $f, string $amount): void
    {
        app(ResponsibleGamingLimitService::class)->choose(new ResponsibleGamingLimitData($f['user']->id, 'daily_deposit', $amount, 'THB'));
    }

    private function oldApproved(Wallet $wallet): Deposit
    {
        $this->travel(-2)->days();
        try {
            $row = app(DepositService::class)->request($wallet, Money::of('100.00', Currency::THB), PaymentMethod::BankTransfer);
            app(DepositApprovalService::class)->approve($row);

            return $row->fresh();
        } finally {
            $this->travelBack();
        }
    }

    private function assertOneBudgetWinner(array $results, string $success): void
    {
        $outcomes = array_column($results, 'outcome');
        sort($outcomes);
        $expected = [$success, 'refused'];
        sort($expected);
        $this->assertSame($expected, $outcomes, json_encode($results));
        $denied = array_values(array_filter($results, fn ($r) => $r['outcome'] === 'refused'));
        $this->assertSame(ResponsibleGamingLimitException::class, $denied[0]['exception'], json_encode($results));
        $this->assertNull($denied[0]['sqlstate']);
    }

    public function test_distinct_wallet_requests_share_one_player_budget_and_both_really_wait_on_player(): void
    {
        $this->requireServerRace();
        $f = $this->fixture();
        $this->cap($f, '150.00');
        $base = ['action' => 'deposit_admission', 'operation' => 'request'];
        $r = $this->serverRace([$base + ['wallet_id' => $f['wallet']->id, 'key' => 'synthetic-first-request'],
            $base + ['wallet_id' => $f['other']->id, 'key' => 'synthetic-second-request']], 'users', $f['user']->id);
        $this->assertOneBudgetWinner($r, 'requested');
        $this->assertDatabaseCount('deposits', 1);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', $f['other']->fresh()->balance);
    }

    public function test_distinct_wallet_late_completions_cannot_both_spend_the_same_current_deposit_headroom(): void
    {
        $this->requireServerRace();
        $f = $this->fixture();
        $a = $this->oldApproved($f['wallet']);
        $b = $this->oldApproved($f['other']);
        $this->cap($f, '150.00');
        $base = ['action' => 'deposit_admission', 'operation' => 'complete'];
        $r = $this->serverRace([$base + ['deposit_id' => $a->id], $base + ['deposit_id' => $b->id]], 'users', $f['user']->id);
        $this->assertOneBudgetWinner($r, 'credited');
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertSame('100.00', bcadd($f['wallet']->fresh()->balance, $f['other']->fresh()->balance, 2));
    }

    public function test_current_request_and_old_credit_reserve_one_common_budget_without_lock_order_inversion(): void
    {
        $this->requireServerRace();
        $f = $this->fixture();
        $row = $this->oldApproved($f['wallet']);
        $this->cap($f, '150.00');
        $r = $this->serverRace([
            ['action' => 'deposit_admission', 'operation' => 'complete', 'deposit_id' => $row->id],
            ['action' => 'deposit_admission', 'operation' => 'request', 'wallet_id' => $f['other']->id, 'key' => 'synthetic-mixed-request'],
        ], 'users', $f['user']->id);
        $this->assertCount(1, array_filter($r, fn ($x) => $x['outcome'] === 'refused'), json_encode($r));
        $this->assertCount(1, array_filter($r, fn ($x) => in_array($x['outcome'], ['credited', 'requested'], true)), json_encode($r));
        foreach ($r as $result) {
            if ($result['outcome'] === 'refused') {
                $this->assertSame(ResponsibleGamingLimitException::class, $result['exception']);
                $this->assertNull($result['sqlstate']);
            }
        }
    }

    public function test_fresh_lowering_and_actual_credit_use_the_same_policy_mutex(): void
    {
        $this->requireServerRace();
        $f = $this->fixture();
        $row = $this->oldApproved($f['wallet']);
        $this->cap($f, '200.00');
        $r = $this->serverRace([
            ['action' => 'deposit_admission', 'operation' => 'complete', 'deposit_id' => $row->id],
            ['action' => 'deposit_admission', 'operation' => 'lower', 'user_id' => $f['user']->id],
        ], 'users', $f['user']->id);
        $this->assertSame('lowered', $r[1]['outcome'], json_encode($r));
        $this->assertContains($r[0]['outcome'], ['credited', 'refused'], json_encode($r));
        if ($r[0]['outcome'] === 'refused') {
            $this->assertSame(ResponsibleGamingLimitException::class, $r[0]['exception']);
            $this->assertNull($r[0]['sqlstate']);
        }
        $this->assertSame('50.00', app(ResponsibleGamingLimitService::class)->bindingLimitFor($f['user']->id, ResponsibleGamingLimitType::DailyDeposit, 'THB')->amount);
        $this->expectException(ResponsibleGamingLimitException::class);
        app(DepositService::class)->request($f['other'], Money::of('100.00', Currency::THB));
    }

    public static function currentBarriers(): array
    {
        return [['account'], ['limit'], ['volume']];
    }

    #[DataProvider('currentBarriers')]
    public function test_old_repeatable_read_snapshot_cannot_authorize_actual_new_credit(string $barrier): void
    {
        $this->requireServerRace();
        $f = $this->fixture();
        $row = $this->oldApproved($f['wallet']);
        $this->cap($f, $barrier === 'volume' ? '150.00' : '200.00');
        $name = DB::getDefaultConnection();
        $peerName = 'deposit_admission_peer';
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
            $before = $reader->table('deposits')->count();
            $this->assertSame(1, $before);
            $this->assertSame('active', $reader->table('users')->where('id', $f['user']->id)->value('status'));
            DB::setDefaultConnection($peerName);
            try {
                if ($barrier === 'account') {
                    $peer->table('users')->where('id', $f['user']->id)->update(['status' => 'banned']);
                } elseif ($barrier === 'limit') {
                    $this->cap($f, '50.00');
                } else {
                    $p = app(DepositService::class)->request($f['other'], Money::of('100.00', Currency::THB), PaymentMethod::BankTransfer);
                    app(DepositApprovalService::class)->approve($p);
                    app(DepositCompletionService::class)->complete($p, options: ['provider_reference' => 'SYNTHETIC-PEER-DEPOSIT']);
                }
            } finally {
                DB::setDefaultConnection($name);
            }
            $this->assertSame($before, $reader->table('deposits')->count(), 'The ordinary database snapshot must really remain old.');
            $this->assertSame('active', $reader->table('users')->where('id', $f['user']->id)->value('status'));
            $this->assertSame($barrier === 'volume' ? '150.00' : '200.00', $reader->table('responsible_gaming_limit_versions')->where('limit_status', 'active')->value('amount'));
            try {
                app(DepositCompletionService::class)->complete($row, options: ['provider_reference' => 'SYNTHETIC-STALE-DEPOSIT']);
                $this->fail('An old snapshot granted a real new deposit credit.');
            } catch (FinancialException|ResponsibleGamingLimitException $e) {
                $this->assertInstanceOf($barrier === 'account' ? FinancialException::class : ResponsibleGamingLimitException::class, $e);
            }
            $reader->commit();
            $this->assertSame('0.00', $f['wallet']->fresh()->balance);
            $this->assertSame($barrier === 'volume' ? 1 : 0, DB::table('financial_transactions')->count());
            $this->assertSame('approved', $row->fresh()->status->value);
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

    public function test_many_large_exact_gross_strings_cannot_round_away_one_cent_of_excess(): void
    {
        $this->requireServerRace();
        $f = $this->fixture();
        // Synthetic legacy budget evidence only: these rows are NOT credit or historical ownership proof.
        $template = $this->oldApproved($f['other'])->getRawOriginal();
        DB::table('deposits')->delete();
        unset($template['id']);
        $records = [];
        for ($i = 0; $i < 999; $i++) {
            $records[] = array_replace($template, ['reference_number' => 'DP-SYNTHETIC-EXACT-'.$i, 'uuid' => (string) Str::uuid(),
                'amount' => '999999999.99', 'net_amount' => '999999999.99', 'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString()]);
        }
        foreach (array_chunk($records, 100) as $chunk) {
            DB::table('deposits')->insert($chunk);
        }
        $this->cap($f, '999000000040.00');
        $this->expectException(ResponsibleGamingLimitException::class);
        app(DepositService::class)->request($f['wallet'], Money::of('50.00', Currency::THB));
    }
}
