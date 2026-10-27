<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LifecycleBoundaryOperation as Operation;
use Tests\Support\RunsServerFinancialRaces;

final class LifecycleTransactionBoundaryServerTest extends FinancialMasterTestCase
{
    use RunsServerFinancialRaces;

    public static function profiles(): array
    {
        return [
            ['deposit-lock', 'deposits', 'confirmed', null],
            ['withdrawal-lock', 'withdrawals', 'completed', null],
            ['transaction-lock', 'financial_transactions', 'completed', null],
            ['deposit-transition', 'deposits', 'confirmed', 'financial_state_is_terminal'],
            ['withdrawal-transition', 'withdrawals', 'completed', 'financial_state_is_terminal'],
            ['transaction-fail', 'financial_transactions', 'completed', 'transaction_invalid_status_transition'],
        ];
    }

    #[DataProvider('profiles')]
    public function test_two_old_snapshot_callers_use_current_lifecycle_row_after_real_parent_lock(string $operation, string $table, string $terminal, ?string $code): void
    {
        $this->requireServerRace();
        $wallet = $this->createPlayer()['wallet'];
        $fields = ['user_id' => $wallet->user_id, 'wallet_id' => $wallet->id];
        $deposit = Deposit::factory()->create($fields);
        $withdrawal = Withdrawal::factory()->create($fields);
        $transaction = FinancialTransaction::factory()->create($fields);
        $entity = match ($table) {
            'deposits' => $deposit,
            'withdrawals' => $withdrawal,
            'financial_transactions' => $transaction,
        };
        $command = ['action' => 'lifecycle_transaction_boundary', 'operation' => $operation,
            'deposit_id' => $deposit->id, 'withdrawal_id' => $withdrawal->id,
            'transaction_id' => $transaction->id, 'table' => $table, 'entity_id' => $entity->id];
        $paper = null;
        $rows = $this->serverRace([$command, $command], $table, $entity->id,
            afterLifecycleLock: function () use ($table, $entity, $terminal, &$paper): void {
                // Lifecycle-only race fixture: no claim this synthetic label posts money.
                DB::table($table)->where('id', $entity->id)->update(['status' => $terminal]);
                $paper = Operation::paper();
            });
        $this->assertSame(array_fill(0, 2, ['table' => $table, 'status' => 'pending']), array_column($rows, 'snapshot'));
        $this->assertSame([1, 1], array_column($rows, 'level'));
        $this->assertSame([true, true], array_column($rows, 'physical'));
        $this->assertSame([$code, $code], array_column($rows, 'code'), json_encode($rows));
        $this->assertSame(array_fill(0, 2, $code === null ? 'locked' : 'refused'), array_column($rows, 'outcome'));
        $this->assertSame(array_fill(0, 2, $code === null ? $terminal : null), array_column($rows, 'current'));
        $this->assertSame($paper, Operation::paper());
    }
}
