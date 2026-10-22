<?php

declare(strict_types=1);

namespace Tests\Feature\Betting\Concerns;

use App\DTOs\BetPurchaseData;
use App\DTOs\Betting\BetCancellationData;
use App\DTOs\Betting\BulkBetSelectionData;
use App\Enums\BetCancellationReason;
use App\Enums\Currency;
use App\Enums\WalletType;
use App\Models\User;
use App\Services\Betting\BetPurchaseService;
use App\Services\Finance\WalletService;
use Illuminate\Support\Facades\DB;

trait BuildsOwnedBetPurchase
{
    protected function purchaseFixture(string $market = '3d_direct', string $number = '007', Currency $currency = Currency::THB, bool $bonus = false): array
    {
        config(['lottery.betting.currency' => $currency->value]);
        $user = User::factory()->create();
        $wallet = app(WalletService::class)->getOrCreateWallet($user, $currency);
        if ($bonus) {
            $wallet->forceFill(['type' => WalletType::Bonus])->save();
        }
        $this->creditFixture($wallet, '100.00', 'Owned cancellation fixture');
        $selection = new BulkBetSelectionData(marketKey: $market, number: $number, stake: '10.00');
        $draw = $this->openBulkDrawForTest([$selection]);
        $result = app(BetPurchaseService::class)->purchase(new BetPurchaseData(userId: $user->id, drawId: $draw->id,
            marketKey: $market, rawNumber: $number, rawStake: '10.00', idempotencyKey: 'owned-cancellation-fixture'));
        $bet = $result->bet;
        $claim = DB::table('bet_purchase_ownerships')->where('bet_id', $bet->id)->sole();

        return compact('user', 'wallet', 'draw', 'result', 'bet', 'claim');
    }

    protected function cancelData(array $f, ?int $userId = null): BetCancellationData
    {
        return new BetCancellationData($userId ?? $f['user']->id, (string) $f['bet']->id, BetCancellationReason::PlayerRequest);
    }
}
