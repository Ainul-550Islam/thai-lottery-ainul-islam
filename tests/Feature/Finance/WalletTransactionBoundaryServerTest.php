<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Wallet\Conversion\ConversionTestCase;
use Tests\Support\RunsServerFinancialRaces;
use Tests\Support\WalletBoundaryOperation as Operation;

final class WalletTransactionBoundaryServerTest extends ConversionTestCase
{
    use RunsServerFinancialRaces;

    public static function profiles(): array
    {
        return [
            ['apply-debit', 'commit', 'wallet_mutation_outside_transaction'],
            ['apply-credit', 'rollback', 'wallet_mutation_outside_transaction'],
            ['hold', 'commit', 'wallet_transaction_state_invalid'],
            ['release', 'rollback', 'wallet_transaction_state_invalid'],
            ['credit', 'commit', 'wallet_transaction_state_invalid'],
            ['debit', 'rollback', 'wallet_transaction_state_invalid'],
            ['credit', 'managed-rollback', null],
            ['credit', 'managed-commit', null],
        ];
    }

    #[DataProvider('profiles')]
    public function test_physical_loss_after_a_genuine_current_wallet_lock_cannot_authorize_money(string $operation, string $finish, ?string $code): void
    {
        $this->requireServerRace();
        $this->seed(LedgerAccountSeeder::class);
        $wallet = $this->makeFundedMember()['cash'];
        $wallet->forceFill(['locked_balance' => '200.00'])->save();
        $command = ['action' => 'wallet_transaction_boundary', 'wallet_id' => $wallet->id,
            'operation' => $operation, 'finish' => $finish];
        $committed = null;
        $rows = $this->serverRace([$command, $command], 'wallets', $wallet->id, [],
            function () use ($wallet, &$committed): void {
                DB::table('wallets')->where('id', $wallet->id)->update(['balance' => '950.00']);
                $committed = Operation::paper();
            });
        $this->assertSame(['1000.00', '1000.00'], array_column($rows, 'snapshot'), json_encode($rows));
        $this->assertSame([1, 1], array_column($rows, 'level_before'));
        $this->assertSame([1, 1], array_column($rows, 'level_after'));
        if ($code !== null) {
            $this->assertSame(['refused', 'refused'], array_column($rows, 'outcome'), json_encode($rows));
            $this->assertSame([$code, $code], array_column($rows, 'code'));
            $this->assertSame(['950.00', '950.00'], array_column($rows, 'current'));
            $this->assertSame([false, false], array_column($rows, 'physical_before'));
            $this->assertSame([false, false], array_column($rows, 'physical_after'));
            $this->assertSame([0, 0], array_column($rows, 'queries'));
            $this->assertSame($committed, Operation::paper());
        } else {
            $this->assertSame(['completed', 'completed'], array_column($rows, 'outcome'), json_encode($rows));
            $this->assertSame([true, true], array_column($rows, 'physical_before'));
            $this->assertSame([true, true], array_column($rows, 'physical_after'));
            if ($finish === 'managed-rollback') {
                $this->assertSame(['950.00', '950.00'], array_column($rows, 'current'));
                $this->assertSame($committed, Operation::paper());
            } else {
                $this->assertEqualsCanonicalizing(['950.00', '980.00'], array_column($rows, 'current'));
                $this->assertSame('1010.00', $wallet->fresh()->balance);
                $this->assertSame(2, DB::table('financial_transactions')->count());
                $this->assertSame(4, DB::table('ledger_entries')->count());
                $this->assertTrue($this->ledgerIsBalanced()['balanced']);
            }
        }
    }
}
