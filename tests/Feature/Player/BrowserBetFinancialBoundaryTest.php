<?php

declare(strict_types=1);

namespace Tests\Feature\Player;

use App\Enums\Currency;
use App\Enums\WalletType;
use App\Models\Bet;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Wallet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\V1\ApiPurchaseTestCase;

/** Actual canonical committed ledger/purchase tests in the guarded test DB. */
final class BrowserBetFinancialBoundaryTest extends ApiPurchaseTestCase
{
    public function test_page_uses_primary_available_balance_not_win_or_reserved_funds(): void
    {
        $user = User::factory()->create();
        Wallet::factory()->create(['user_id' => $user->id, 'type' => WalletType::Win, 'balance' => '9999.00']);
        $primary = Wallet::factory()->create(['user_id' => $user->id, 'balance' => '500.00', 'locked_balance' => '475.25', 'currency' => Currency::THB]);
        $draw = $this->openDraw();
        $response = $this->actingAs($user)->get(route('player.bet'))->assertOk();
        $response->assertViewHas('wallet', fn (Wallet $shown): bool => $shown->is($primary));
        $response->assertViewHas('openDraw', fn ($shown): bool => $shown->is($draw));
        $response->assertSee('24.75')->assertDontSee('9,999.00');
    }

    public function test_page_excludes_status_open_draws_outside_the_betting_window(): void
    {
        $fixture = $this->fixture();
        $fixture['draw']->betting_close_at = now()->subMinute();
        $fixture['draw']->save();
        $future = $this->openDraw();
        $future->betting_open_at = now()->addHour();
        $future->betting_close_at = now()->addHours(2);
        $future->save();
        $response = $this->actingAs($fixture['user'])->get(route('player.bet'))->assertOk();
        $response->assertViewHas('openDraw', null)->assertDontSee('data-bet-form', false);
        $eligible = $this->openDraw();
        $this->get(route('player.bet'))->assertOk()->assertViewHas('openDraw', fn ($shown): bool => $shown->is($eligible));
    }

    public function test_page_item_ceiling_cannot_exceed_the_http_cap_but_can_be_lower(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($fixture['user']);
        config(['lottery.bulk.max_items' => 500]);
        $this->get(route('player.bet'))->assertOk()->assertSee('data-max-items="50"', false);
        config(['lottery.bulk.max_items' => 3]);
        $this->get(route('player.bet'))->assertOk()->assertSee('data-max-items="3"', false);
    }

    public function test_partial_purchase_and_exact_retry_preserve_wallet_and_balanced_ledger(): void
    {
        $fixture = $this->fixture('50.00');
        foreach (['123', '456'] as $number) {
            $this->ensureLimit($fixture['draw'], '3d_direct', $number);
        }
        $payload = ['draw_id' => $fixture['draw']->id, 'client_key' => $this->key('browser-partial-ledger'), 'items' => [
            ['market' => '3d_direct', 'number' => '123', 'stake' => '20.00'],
            ['market' => '3d_direct', 'number' => '456', 'stake' => '100.00'],
        ]];
        $this->actingAs($fixture['user'])->postJson('/bet/purchase', $payload)->assertCreated()
            ->assertJsonPath('data.report.purchased', 1)->assertJsonPath('data.report.refused', 1)
            ->assertJsonPath('data.report.total_charged', '20.00');
        self::assertSame('30.00', (string) $fixture['wallet']->fresh()->balance);
        self::assertSame(1, Bet::query()->count());
        self::assertSame(1, Ticket::query()->count());
        self::assertSame(1, FinancialTransaction::query()->count());
        $rows = LedgerEntry::query()->get();
        self::assertGreaterThanOrEqual(2, $rows->count());
        $debits = '0.00';
        $credits = '0.00';
        foreach ($rows as $row) {
            $debits = bcadd($debits, $row->debit, 2);
            $credits = bcadd($credits, $row->credit, 2);
        }
        self::assertSame('20.00', $debits);
        self::assertSame($debits, $credits);
        $count = $rows->count();
        $this->postJson('/bet/purchase', $payload)->assertOk()
            ->assertJsonPath('data.report.purchased', 0)->assertJsonPath('data.report.replayed', 1)
            ->assertJsonPath('data.report.refused', 1)->assertJsonPath('data.report.total_charged', '0.00');
        self::assertSame('30.00', (string) $fixture['wallet']->fresh()->balance);
        self::assertSame(1, Bet::query()->count());
        self::assertSame(1, Ticket::query()->count());
        self::assertSame(1, FinancialTransaction::query()->count());
        self::assertSame($count, LedgerEntry::query()->count());
    }

    public static function maliciousFields(): array
    {
        $cases = [];
        foreach (['user_id', 'wallet_id', 'status', 'multiplier', 'payout', 'potential_payout', 'ticket_number'] as $field) {
            $cases['top '.$field] = [$field, false];
            $cases['item '.$field] = [$field, true];
        }

        return $cases;
    }

    #[DataProvider('maliciousFields')]
    public function test_browser_cannot_supply_monetary_authority_fields(string $field, bool $nested): void
    {
        $fixture = $this->fixture('500.00');
        $this->ensureLimit($fixture['draw'], '3d_direct', '123');
        $payload = ['draw_id' => $fixture['draw']->id, 'client_key' => $this->key('browser-authority-'.$field), 'items' => [
            ['market' => '3d_direct', 'number' => '123', 'stake' => '20.00'],
        ]];
        if ($nested) {
            $payload['items'][0][$field] = '999';
        } else {
            $payload[$field] = '999';
        }
        $response = $this->actingAs($fixture['user'])->postJson('/bet/purchase', $payload)->assertUnprocessable();
        self::assertArrayHasKey($nested ? 'items.0.'.$field : $field, $response->json('error.details.fields'));
        self::assertSame('500.00', (string) $fixture['wallet']->fresh()->balance);
        self::assertSame(0, Bet::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
    }

    public function test_conflicting_draw_identifiers_refuse_before_money_mutation(): void
    {
        $fixture = $this->fixture();
        $other = $this->openDraw();
        $response = $this->actingAs($fixture['user'])->postJson('/bet/purchase', [
            'draw_id' => $fixture['draw']->id, 'draw_reference' => $other->draw_number,
            'client_key' => $this->key('browser-conflicting-draw'),
            'items' => [['market' => '3d_direct', 'number' => '123', 'stake' => '20.00']],
        ])->assertUnprocessable();
        self::assertArrayHasKey('draw_reference', $response->json('error.details.fields'));
        self::assertSame('1000.00', (string) $fixture['wallet']->fresh()->balance);
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
    }

    public function test_real_csrf_middleware_refuses_a_session_purchase_without_token(): void
    {
        $fixture = $this->fixture();
        // Laravel skips CSRF when env=testing. Temporarily disable that skip,
        // while keeping the already guarded test database and all other gates.
        $this->app['env'] = 'local';
        try {
            $this->actingAs($fixture['user'])->postJson('/bet/purchase', [
                'draw_id' => $fixture['draw']->id, 'client_key' => $this->key('browser-missing-csrf'),
                'items' => [['market' => '3d_direct', 'number' => '123', 'stake' => '20.00']],
            ])->assertStatus(419);
            self::assertSame('1000.00', (string) $fixture['wallet']->fresh()->balance);
            self::assertSame(0, Bet::query()->count());
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
