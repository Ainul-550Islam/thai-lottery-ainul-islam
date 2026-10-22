<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\WithdrawalStatus;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalRequestWithHoldService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Tests\Support\RunsServerFinancialRaces;

final class WithdrawalInitialHoldHandoffServerTest extends FinancialMasterTestCase
{
    use RunsServerFinancialRaces;

    private function requestCommands(array $p, bool $sameKey): array
    {
        return [['action' => 'initial_withdrawal_request', 'wallet_id' => $p['wallet']->id, 'key' => 'native-initial-first-key'],
            ['action' => 'initial_withdrawal_request', 'wallet_id' => $p['wallet']->id, 'key' => $sameKey ? 'native-initial-first-key' : 'native-initial-other-key']];
    }

    private function row(array $p)
    {
        config(['finance.withdrawal.min' => '1.00']);

        return app(WithdrawalRequestWithHoldService::class)->request($p['wallet'], Money::of('10', Currency::THB), PaymentMethod::Manual, 'native-initial-owner-row');
    }

    public function test_two_simultaneous_identical_requests_create_one_source_and_one_ten_unit_hold(): void
    {
        $this->requireServerRace();
        $p = $this->createPlayer('100', Currency::THB);
        $rows = $this->serverRace($this->requestCommands($p, true), 'wallets', $p['wallet']->id);
        $this->assertSame(['requested', 'requested'], array_column($rows, 'outcome'), json_encode($rows));
        $this->assertSame($rows[0]['withdrawal_id'], $rows[1]['withdrawal_id']);
        $this->assertDatabaseCount('withdrawals', 1);
        $this->assertDatabaseCount('withdrawal_initial_holds', 1);
        $this->assertSame('10.00', $p['wallet']->fresh()->locked_balance);
        $this->assertSame('100.00', $p['wallet']->fresh()->balance);
    }

    public function test_competing_new_requests_cannot_double_reserve_a_ten_unit_wallet(): void
    {
        $this->requireServerRace();
        $p = $this->createPlayer('10', Currency::THB);
        $rows = $this->serverRace($this->requestCommands($p, false), 'wallets', $p['wallet']->id);
        $outcomes = array_column($rows, 'outcome');
        sort($outcomes);
        $this->assertSame(['refused', 'requested'], $outcomes, json_encode($rows));
        $this->assertDatabaseCount('withdrawals', 1);
        $this->assertDatabaseCount('withdrawal_initial_holds', 1);
        $this->assertSame('10.00', $p['wallet']->fresh()->locked_balance);
    }

    public function test_two_approvals_transfer_one_owner_without_a_second_hold(): void
    {
        $this->requireServerRace();
        $p = $this->createPlayer('10', Currency::THB);
        $row = $this->row($p);
        $command = ['action' => 'initial_withdrawal_approve', 'withdrawal_id' => $row->id];
        $rows = $this->serverRace([$command, $command], 'wallets', $p['wallet']->id);
        $outcomes = array_column($rows, 'outcome');
        sort($outcomes);
        $this->assertSame(['refused', 'reserved'], $outcomes, json_encode($rows));
        $this->assertSame('10.00', $p['wallet']->fresh()->locked_balance);
        $this->assertDatabaseCount('withdrawal_reservations', 1);
        $this->assertDatabaseHas('withdrawal_initial_holds', ['state' => 'transferred']);
    }

    public function test_cancel_and_handoff_race_leaves_no_stranded_or_duplicate_hold(): void
    {
        $this->requireServerRace();
        $p = $this->createPlayer('100', Currency::THB);
        $row = $this->row($p);
        $rows = $this->serverRace([['action' => 'initial_withdrawal_approve', 'withdrawal_id' => $row->id],
            ['action' => 'initial_withdrawal_cancel', 'withdrawal_id' => $row->id]], 'wallets', $p['wallet']->id);
        $this->assertContains($rows[0]['outcome'], ['reserved', 'refused'], json_encode($rows));
        $this->assertSame('cancelled', $rows[1]['outcome'], json_encode($rows));
        $this->assertSame(WithdrawalStatus::Cancelled, $row->fresh()->status);
        $this->assertSame('100.00', $p['wallet']->fresh()->balance);
        $this->assertSame('0.00', $p['wallet']->fresh()->locked_balance);
        $this->assertDatabaseHas('withdrawal_initial_holds', ['state' => 'released']);
        $this->assertSame(0, DB::table('withdrawal_reservations')->where('state', 'active')->count());
    }

    public function test_generic_unlock_observes_new_initial_ownership_after_two_old_empty_rr_snapshots(): void
    {
        $this->requireServerRace();
        $p = $this->createPlayer('100', Currency::THB);
        $command = ['action' => 'wallet_floor', 'wallet_id' => $p['wallet']->id, 'operation' => 'initial_snapshot_unlock'];
        $rows = $this->serverRace([$command, $command], 'wallets', $p['wallet']->id, [], function () use ($p): void {
            $this->row($p);
        });
        $this->assertSame(['refused', 'refused'], array_column($rows, 'outcome'), json_encode($rows));
        $this->assertSame(['wallet_withdrawal_floor_conflict', 'wallet_withdrawal_floor_conflict'], array_column($rows, 'code'));
        $this->assertSame('10.00', $p['wallet']->fresh()->locked_balance);
        $this->assertSame('100.00', $p['wallet']->fresh()->balance);
        $this->assertDatabaseCount('withdrawal_initial_holds', 1);
    }
}
