<?php

declare(strict_types=1);

namespace Tests\Feature\Betting;

use App\Services\Betting\BetCancellationService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Betting\Concerns\BuildsOwnedBetPurchase;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Tests\Support\RunsServerFinancialRaces;

final class OwnedPurchaseReplayServerRaceTest extends FinancialMasterTestCase
{
    use BuildsOwnedBetPurchase;
    use RunsServerFinancialRaces;

    public static function modes(): array
    {
        return [['new'], ['replay'], ['cancelled']];
    }

    #[DataProvider('modes')]
    public function test_simultaneous_calls_lock_the_original_wallet_and_never_repeat_the_debit(string $mode): void
    {
        $this->requireServerRace();
        $f = $this->purchaseFixture();
        if ($mode === 'cancelled') {
            app(BetCancellationService::class)->cancel($this->cancelData($f));
        }
        $key = $mode === 'new' ? 'new-concurrent-paid-purchase' : 'owned-cancellation-fixture';
        $command = ['action' => 'purchase_replay', 'user_id' => $f['user']->id, 'draw_id' => $f['draw']->id, 'key' => $key];
        $beforeTransactions = DB::table('financial_transactions')->count();
        $results = $this->serverRace([$command, $command], 'wallets', $f['wallet']->id);
        $outcomes = array_column($results, 'outcome');
        sort($outcomes);
        $this->assertSame($mode === 'new' ? ['purchased', 'replayed'] : ['replayed', 'replayed'], $outcomes, json_encode($results));
        $this->assertSame($results[0]['transaction_id'], $results[1]['transaction_id']);
        $this->assertSame($mode === 'new' ? 2 : 1, DB::table('bet_purchase_receipts')->count());
        $this->assertSame($beforeTransactions + ($mode === 'new' ? 1 : 0), DB::table('financial_transactions')->count());
        $this->assertSame(match ($mode) {
            'new' => '80.00', 'cancelled' => '100.00', default => '90.00'
        }, $f['wallet']->fresh()->balance);
    }
}
