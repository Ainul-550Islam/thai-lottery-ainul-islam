<?php

declare(strict_types=1);

namespace Tests\Feature\Betting;

use App\DTOs\BetPurchaseData;
use App\Enums\Currency;
use App\Enums\WalletStatus;
use App\Enums\WalletType;
use App\Exceptions\BetPurchaseException;
use App\Models\Bet;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Services\Betting\BetPurchaseTransactionService;
use App\Services\Betting\BetPurchaseValidator;
use App\Services\Betting\BetPurchaseWalletService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\V1\ApiPurchaseTestCase;

/** Boundaries for NEW wagers; historical funding replay and payout resolution are separate. */
final class BettingWalletTypeBoundaryTest extends ApiPurchaseTestCase
{
    public static function forbiddenTypes(): array
    {
        return [[WalletType::Win], [WalletType::Commission]];
    }

    #[DataProvider('forbiddenTypes')]
    public function test_new_wager_refuses_a_non_betting_wallet_without_financial_writes(WalletType $type): void
    {
        $f = $this->fixture();
        $f['wallet']->forceFill(['type' => $type])->save();
        $this->ensureLimit($f['draw'], '3d_direct', '123');
        $limitBefore = DB::table('number_limits')->where('draw_id', $f['draw']->id)->get()->toArray();

        // The shared historical settlement resolver is intentionally unchanged.
        self::assertTrue(app(BetPurchaseWalletService::class)->resolveWallet($f['user'], Currency::THB)->is($f['wallet']));
        $this->actingAs($f['user'])->get(route('player.bet'))->assertOk()->assertViewHas('wallet', null);
        $this->postJson('/bet/purchase', $this->payloadFor($f, 'forbidden-wallet-type'))
            ->assertUnprocessable()->assertJsonPath('report.refused', 1);

        self::assertSame('1000.00', (string) $f['wallet']->fresh()->balance);
        self::assertSame(0, Bet::query()->count());
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
        self::assertEquals($limitBefore, DB::table('number_limits')->where('draw_id', $f['draw']->id)->get()->toArray());
    }

    public static function allowedTypes(): array
    {
        return [[WalletType::Primary], [WalletType::Main], [WalletType::Bonus]];
    }

    #[DataProvider('allowedTypes')]
    public function test_existing_bettable_wallet_types_still_fund_a_wager(WalletType $type): void
    {
        $f = $this->fixture();
        $f['wallet']->forceFill(['type' => $type])->save();
        $this->ensureLimit($f['draw'], '3d_direct', '123');
        $this->actingAs($f['user'])->get(route('player.bet'))->assertOk()
            ->assertViewHas('wallet', fn (Wallet $shown): bool => $shown->is($f['wallet']));
        $this->postJson('/bet/purchase', $this->payloadFor($f, 'allowed-wallet-type'))
            ->assertCreated()->assertJsonPath('data.report.purchased', 1);
        self::assertSame('980.00', (string) $f['wallet']->fresh()->balance);
        self::assertSame(1, Bet::query()->count());
    }

    #[DataProvider('forbiddenTypes')]
    public function test_locked_current_type_rejects_a_stale_prevalidated_context(WalletType $type): void
    {
        $f = $this->fixture();
        $this->ensureLimit($f['draw'], '3d_direct', '123');
        $context = app(BetPurchaseValidator::class)->validate(new BetPurchaseData(
            $f['user']->id, $f['draw']->id, '3d_direct', '123', '20.00', 'stale-wallet-type-context',
        ));
        $f['wallet']->forceFill(['type' => $type])->save();
        $before = DB::table('number_limits')->where('draw_id', $f['draw']->id)->get()->toArray();
        try {
            app(BetPurchaseTransactionService::class)->execute($context);
            self::fail('An ineligible locked wallet funded a new wager.');
        } catch (BetPurchaseException $exception) {
            self::assertSame('bet_purchase_wallet_unavailable', $exception->errorCode());
        }
        self::assertSame(0, DB::transactionLevel());
        self::assertSame('1000.00', (string) $f['wallet']->fresh()->balance);
        self::assertSame(0, Bet::query()->count());
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
        self::assertEquals($before, DB::table('number_limits')->where('draw_id', $f['draw']->id)->get()->toArray());
    }

    public function test_exact_existing_purchase_replay_does_not_depend_on_current_wallet_type(): void
    {
        $f = $this->fixture();
        $this->ensureLimit($f['draw'], '3d_direct', '123');
        $payload = $this->payloadFor($f, 'wallet-type-replay');
        $this->actingAs($f['user'])->postJson('/bet/purchase', $payload)->assertCreated();
        $f['wallet']->forceFill(['type' => WalletType::Win])->save();
        $transactionCount = FinancialTransaction::query()->count();
        $ledgerCount = LedgerEntry::query()->count();
        $this->postJson('/bet/purchase', $payload)->assertOk()
            ->assertJsonPath('data.report.replayed', 1)->assertJsonPath('data.report.total_charged', '0.00');
        self::assertSame('980.00', (string) $f['wallet']->fresh()->balance);
        self::assertSame(1, Bet::query()->count());
        self::assertSame($transactionCount, FinancialTransaction::query()->count());
        self::assertSame($ledgerCount, LedgerEntry::query()->count());
    }

    public function test_page_follows_the_purchase_currency_and_active_wallet_selection(): void
    {
        $f = $this->fixture();
        $f['wallet']->forceFill(['status' => WalletStatus::Suspended])->save();
        $bonus = Wallet::factory()->create(['user_id' => $f['user']->id, 'type' => WalletType::Bonus,
            'currency' => Currency::THB, 'balance' => '75.00', 'locked_balance' => '5.00']);
        Wallet::factory()->create(['user_id' => $f['user']->id, 'type' => WalletType::Primary,
            'currency' => Currency::USD, 'balance' => '999.00']);
        config(['payment.currency.default' => 'USD', 'lottery.betting.currency' => 'THB', 'lottery.betting.require_active_wallet' => true]);
        $this->actingAs($f['user'])->get(route('player.bet'))->assertOk()
            ->assertViewHas('wallet', fn (Wallet $shown): bool => $shown->is($bonus))
            ->assertSee('70.00')->assertDontSee('999.00');
    }

    private function payloadFor(array $fixture, string $key): array
    {
        return ['draw_id' => $fixture['draw']->id, 'client_key' => $this->key($key), 'items' => [
            ['market' => '3d_direct', 'number' => '123', 'stake' => '20.00'],
        ]];
    }
}
