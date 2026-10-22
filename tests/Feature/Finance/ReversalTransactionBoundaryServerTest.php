<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\TransactionStatus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Payment\PaymentTestCase;
use Tests\Support\ReversalBoundaryOperation as Operation;
use Tests\Support\RunsServerFinancialRaces;

final class ReversalTransactionBoundaryServerTest extends PaymentTestCase
{
    use RunsServerFinancialRaces;

    public static function profiles(): array
    {
        return [
            ['reverse-new', 'commit'], ['reverse-new', 'rollback'],
            ['existing-empty', 'commit'], ['existing-empty', 'rollback'],
            ['reverse-replay', 'commit'], ['existing-replay', 'rollback'],
            ['reverse-new', 'managed-rollback'], ['reverse-new', 'managed-commit'],
            ['reverse-new', 'owned-commit'],
        ];
    }

    #[DataProvider('profiles')]
    public function test_real_wallet_wait_then_lost_boundary_refuses_reversal_or_verified_replay(string $mode, string $finish): void
    {
        $this->requireServerRace();
        $f = Operation::fixture('wallet-credit', str_ends_with($mode, 'replay'));
        $snapshot = Operation::snapshot($f['wallet']->id, $f['original']->id);
        $newBalance = bcsub($snapshot['balance'], '50.00', 2);
        $command = ['action' => 'reversal_transaction_boundary', 'wallet_id' => $f['wallet']->id,
            'original_id' => $f['original']->id, 'operation' => $mode, 'finish' => $finish,
            'expected_reversal_snapshot' => $snapshot];
        $committed = null;
        $rows = $this->serverRace([$command, $command], 'wallets', $f['wallet']->id, [],
            function () use ($f, $newBalance, &$committed): void {
                DB::table('wallets')->where('id', $f['wallet']->id)->update(['balance' => $newBalance]);
                $committed = Operation::paper();
            });
        $this->assertSame([$snapshot, $snapshot], array_column($rows, 'snapshot'), json_encode($rows));
        $owned = $finish === 'owned-commit';
        $this->assertSame(array_fill(0, 2, $owned ? 0 : 1), array_column($rows, 'level_before'));
        $this->assertSame(array_fill(0, 2, $owned ? 0 : 1), array_column($rows, 'level_after'));
        if (in_array($finish, ['commit', 'rollback'], true)) {
            $this->assertSame(['refused', 'refused'], array_column($rows, 'outcome'), json_encode($rows));
            $this->assertSame(['wallet_transaction_state_invalid', 'wallet_transaction_state_invalid'], array_column($rows, 'code'));
            $this->assertSame([$newBalance, $newBalance], array_column($rows, 'current'));
            $this->assertSame([false, false], array_column($rows, 'physical_before'));
            $this->assertSame([false, false], array_column($rows, 'physical_after'));
            $this->assertSame([0, 0], array_column($rows, 'queries'), 'No routing SQL after physical loss.');
            $this->assertSame($committed, Operation::paper());
        } else {
            $this->assertSame(['completed', 'completed'], array_column($rows, 'outcome'), json_encode($rows));
            $this->assertSame(array_fill(0, 2, ! $owned), array_column($rows, 'physical_before'));
            $this->assertSame(array_fill(0, 2, ! $owned), array_column($rows, 'physical_after'));
            if ($finish === 'managed-rollback') {
                $this->assertSame([$newBalance, $newBalance], array_column($rows, 'current'));
                $this->assertSame($committed, Operation::paper());
            } else {
                $this->assertSame($rows[0]['compensation_id'], $rows[1]['compensation_id'], 'Only one original-bound compensation may commit.');
                $this->assertSame(bcsub($newBalance, '100.00', 2), $f['wallet']->fresh()->balance);
                $this->assertSame(TransactionStatus::Reversed, $f['original']->fresh()->status);
                $this->assertDatabaseCount('financial_transactions', 2);
                $this->assertDatabaseCount('ledger_entries', 4);
            }
        }
    }
}
