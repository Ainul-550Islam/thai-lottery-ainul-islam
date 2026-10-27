<?php

declare(strict_types=1);

namespace Tests\Feature\Player;

use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\WalletType;
use App\Models\Draw;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * HTTP presentation contracts, not browser/provider or live-money certification.
 * All financial fixtures are confined to the guarded disposable test database.
 */
final class PlayerPresentationContractTest extends TestCase
{
    use DatabaseTruncation;

    public static function drawPresentations(): array
    {
        return [
            'English numeric id' => ['en', false],
            'English draw number' => ['en', true],
            'Thai numeric id' => ['th', false],
            'Thai draw number' => ['th', true],
        ];
    }

    #[DataProvider('drawPresentations')]
    public function test_draw_heading_and_localized_title_identify_the_requested_record(string $locale, bool $useNumber): void
    {
        $player = User::factory()->create();
        $other = Draw::factory()->create(['draw_number' => 'PRESENTATION-OTHER-14']);
        $draw = Draw::factory()->create(['draw_number' => 'PRESENTATION-REQUESTED-14', 'status' => DrawStatus::Open]);
        $before = $draw->refresh()->getRawOriginal();
        $response = $this->actingAs($player)->get(route('player.draws.detail', $useNumber ? $draw->draw_number : $draw->id).'?lang='.$locale);

        $response->assertOk()
            ->assertViewIs('player.draw-detail')
            ->assertViewHas('draw', fn (Draw $shown): bool => $shown->is($draw))
            ->assertDontSee($other->draw_number)
            ->assertSee(__('player.results_pending', [], $locale));

        // Check the actual semantic heading, not merely a string elsewhere in
        // the response or an English prefix that contradicts the Thai view.
        $dom = new DOMDocument;
        $oldErrors = libxml_use_internal_errors(true);
        try {
            self::assertTrue($dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent()));
            $xpath = new DOMXPath($dom);
            $headings = $xpath->query('//h1[@id="draw-detail-heading"]');
            self::assertSame(1, $headings->length);
            self::assertSame($draw->draw_number, trim($headings->item(0)->textContent));
            $titles = $xpath->query('//title');
            self::assertSame(1, $titles->length);
            self::assertStringContainsString(__('player.draw_detail_title', ['draw' => $draw->draw_number], $locale), $titles->item(0)->textContent);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($oldErrors);
        }

        self::assertSame($before, $draw->fresh()->getRawOriginal());
        self::assertSame(0, $draw->result()->count());
    }

    public function test_unknown_draw_identifiers_do_not_fall_back_to_another_record(): void
    {
        $player = User::factory()->create();
        Draw::factory()->create(['draw_number' => 'PRESENTATION-EXISTING-14']);
        $this->actingAs($player)->get(route('player.draws.detail', 99999999))->assertNotFound();
        $this->get(route('player.draws.detail', 'PRESENTATION-ABSENT-14'))->assertNotFound();
    }

    public function test_guest_draw_detail_requires_authentication(): void
    {
        $draw = Draw::factory()->create();
        $this->get(route('player.draws.detail', $draw->id))->assertRedirect(route('login'));
    }

    public static function balances(): array
    {
        return [
            'zero' => ['0.00', '0.00', '0.00', false],
            'fractional cents' => ['100.01', '99.99', '0.02', false],
            'large decimal string' => ['999999999.99', '999999998.98', '1.01', false],
            'all reserved' => ['21.07', '21.07', '0.00', false],
            'frozen with reservation' => ['150.25', '50.24', '100.01', true],
        ];
    }

    #[DataProvider('balances')]
    public function test_wallet_resource_preserves_decimal_strings_and_distinguishes_hold_from_admin_lock(string $balance, string $reserved, string $available, bool $frozen): void
    {
        $player = User::factory()->create();
        $factory = Wallet::factory()->state(['user_id' => $player->id, 'balance' => $balance, 'locked_balance' => $reserved]);
        $wallet = ($frozen ? $factory->frozen() : $factory)->create();
        $before = $wallet->refresh()->getRawOriginal();
        $transactions = FinancialTransaction::query()->count();
        $ledgerEntries = LedgerEntry::query()->count();
        $token = $player->createToken('presentation-read-only')->plainTextToken;
        $response = $this->withToken($token)->getJson('/api/v1/wallet')->assertOk();
        $payload = $response->json('data.wallet');

        self::assertSame($wallet->id, $payload['id']);
        self::assertSame('THB', $payload['currency']);
        self::assertSame('primary', $payload['type']);
        self::assertSame($balance, $payload['balance']);
        self::assertSame($reserved, $payload['reserved_balance']);
        self::assertSame($available, $payload['available_balance']);
        self::assertArrayNotHasKey('locked_balance', $payload);
        self::assertArrayNotHasKey('user_id', $payload);
        self::assertSame($frozen ? 'frozen' : 'active', $payload['status']);
        self::assertSame(! $frozen, $payload['can_transact']);
        self::assertSame($frozen, $payload['is_locked']);
        self::assertSame([$payload], $response->json('data.wallets'));
        self::assertSame($before, $wallet->fresh()->getRawOriginal());
        self::assertSame($transactions, FinancialTransaction::query()->count());
        self::assertSame($ledgerEntries, LedgerEntry::query()->count());
    }

    public function test_wallet_collection_is_owner_scoped_and_primary_selection_is_not_insertion_order(): void
    {
        $player = User::factory()->create();
        $other = User::factory()->create();
        $foreign = Wallet::factory()->create(['user_id' => $other->id, 'balance' => '987654.32']);
        // Create the secondary wallets first to catch selecting the first row
        // rather than the canonical primary wallet.
        $bonus = Wallet::factory()->create(['user_id' => $player->id, 'type' => WalletType::Bonus, 'currency' => Currency::THB, 'balance' => '27.13']);
        $win = Wallet::factory()->create(['user_id' => $player->id, 'type' => WalletType::Win, 'currency' => Currency::THB, 'balance' => '11.03']);
        $primary = Wallet::factory()->create(['user_id' => $player->id, 'balance' => '71.29']);
        $token = $player->createToken('presentation-owner-scope')->plainTextToken;
        $response = $this->withToken($token)->getJson('/api/v1/wallet?user_id='.$other->id.'&wallet_id='.$foreign->id)->assertOk();

        self::assertSame($primary->id, $response->json('data.wallet.id'));
        self::assertSame('71.29', $response->json('data.wallet.balance'));
        self::assertEqualsCanonicalizing([$bonus->id, $win->id, $primary->id], array_column($response->json('data.wallets'), 'id'));
        self::assertNotContains($foreign->id, array_column($response->json('data.wallets'), 'id'));
        $response->assertDontSee('987654.32');
    }

    public function test_wallet_endpoint_rejects_missing_authentication(): void
    {
        Wallet::factory()->create(['balance' => '987654.32']);
        $this->getJson('/api/v1/wallet')->assertUnauthorized()->assertDontSee('987654.32');
    }

    public function test_account_without_a_wallet_does_not_receive_another_users_wallet(): void
    {
        $player = User::factory()->create();
        Wallet::factory()->create(['balance' => '987654.32']);
        $token = $player->createToken('presentation-no-wallet')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/wallet')->assertNotFound()
            ->assertJsonPath('error.code', 'wallet_not_found')
            ->assertDontSee('987654.32');
    }
}
