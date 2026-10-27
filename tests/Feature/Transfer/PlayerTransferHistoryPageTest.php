<?php

declare(strict_types=1);

namespace Tests\Feature\Transfer;

use App\Enums\Currency;
use App\Enums\TransferDirection;
use App\Enums\TransferStatus;
use App\Enums\UserStatus;
use App\Models\BalanceTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * The player's balance-transfer history page (/wallet/transfers).
 *
 * WHAT WAS BROKEN
 * BalanceTransferController::index() renders `player.transfers` for a browser
 * request. The Blade file did not exist, so the "View all" link inside the
 * transfer panel - the link a player follows right after moving money - reached
 * an unrendered view and 500'd. The JSON half of the same endpoint worked, which
 * is exactly why nothing caught it: no test ever asked the endpoint for HTML.
 *
 * WHAT THIS SUITE PINS DOWN
 *  1. the page exists and renders for the authenticated owner;
 *  2. it is scoped to the caller - another player's transfers never appear;
 *  3. the empty state and the "filters matched nothing" state are different
 *     sentences, because they are different situations;
 *  4. filters narrow the list, and pagination links carry them forward;
 *  5. the same URL still answers a JSON client with the documented envelope;
 *  6. the page is read-only: no transfer form, no success banner, no invented
 *     data. The transaction itself belongs to the store endpoint.
 */
final class PlayerTransferHistoryPageTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/wallet/transfers';

    private function player(): User
    {
        return User::factory()->create(['status' => UserStatus::Active]);
    }

    /**
     * @return User the player who owns the transfers
     */
    private function playerWithTransfers(int $completed = 0, int $failed = 0, ?TransferDirection $direction = null): User
    {
        $user = $this->player();
        $direction ??= TransferDirection::CashToWin;

        $make = function (TransferStatus $status) use ($user, $direction): void {
            BalanceTransfer::factory()
                ->for($user)
                ->direction($direction)
                ->create([
                    'status' => $status,
                    'currency' => Currency::THB,
                ]);
        };

        for ($i = 0; $i < $completed; $i++) {
            $make(TransferStatus::Completed);
        }

        for ($i = 0; $i < $failed; $i++) {
            $make(TransferStatus::Failed);
        }

        return $user;
    }

    public function test_the_history_view_exists(): void
    {
        $this->assertTrue(
            View::exists('player.transfers'),
            'BalanceTransferController::index() renders player.transfers; that view must exist.',
        );
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(self::URI)->assertRedirect(route('login'));
    }

    public function test_an_empty_history_renders_the_empty_state_rather_than_an_error(): void
    {
        $response = $this->actingAs($this->player())->get(self::URI);

        $response->assertOk();
        $response->assertViewIs('player.transfers');
        $response->assertSee(__('transfer.history_page.title'));
        $response->assertSee(__('transfer.history_empty'));
        $response->assertDontSee(__('transfer.history_page.empty_filtered'));
    }

    public function test_the_owners_transfers_are_rendered_with_amounts_and_status(): void
    {
        $user = $this->playerWithTransfers(completed: 2, failed: 1);

        $transfer = BalanceTransfer::query()->where('user_id', $user->id)->firstOrFail();

        $response = $this->actingAs($user)->get(self::URI);

        $response->assertOk();
        $response->assertSee((string) $transfer->reference_number);
        $response->assertSee('100.00');
        $response->assertSee('3.00');
        $response->assertSee(__('transfer.history_status_completed'));
        $response->assertSee(__('transfer.history_status_failed'));
        $response->assertSee(__('transfer.direction_cash_to_win'));
    }

    public function test_another_players_transfers_never_appear(): void
    {
        $mine = $this->player();
        BalanceTransfer::factory()->for($mine)->create(['status' => TransferStatus::Completed]);

        $theirs = $this->player();
        $foreign = BalanceTransfer::factory()->for($theirs)->create(['status' => TransferStatus::Completed]);

        $html = (string) $this->actingAs($mine)->get(self::URI)->assertOk()->getContent();

        $this->assertStringNotContainsString(
            (string) $foreign->reference_number,
            $html,
            'The history page leaked another player\'s transfer reference.',
        );
    }

    public function test_direction_and_status_filters_narrow_the_list(): void
    {
        $user = $this->player();
        $kept = BalanceTransfer::factory()->for($user)->direction(TransferDirection::CashToWin)->create([
            'status' => TransferStatus::Completed,
        ]);
        $wrongDirection = BalanceTransfer::factory()->for($user)->direction(TransferDirection::WinToCash)->create([
            'status' => TransferStatus::Completed,
        ]);
        $wrongStatus = BalanceTransfer::factory()->for($user)->direction(TransferDirection::CashToWin)->create([
            'status' => TransferStatus::Failed,
        ]);

        $response = $this->actingAs($user)->get(self::URI.'?direction=cash_to_win&status=completed');

        $response->assertOk();
        $response->assertSee((string) $kept->reference_number);
        $response->assertDontSee((string) $wrongDirection->reference_number);
        $response->assertDontSee((string) $wrongStatus->reference_number);
    }

    public function test_a_filter_that_matches_nothing_says_so_instead_of_claiming_an_empty_account(): void
    {
        $user = $this->playerWithTransfers(completed: 1);

        $response = $this->actingAs($user)->get(self::URI.'?status=failed');

        $response->assertOk();
        $response->assertSee(__('transfer.history_page.empty_filtered'));
        $response->assertDontSee(__('transfer.history_empty'));
    }

    public function test_an_unrecognised_filter_value_is_ignored_rather_than_emptying_the_list(): void
    {
        $user = $this->playerWithTransfers(completed: 1);
        $transfer = BalanceTransfer::query()->where('user_id', $user->id)->firstOrFail();

        $response = $this->actingAs($user)->get(self::URI.'?status=settled&direction=sideways');

        $response->assertOk();
        $response->assertSee((string) $transfer->reference_number);
    }

    public function test_pagination_links_keep_the_active_filters(): void
    {
        // One more than the default page size, so page 2 exists at all.
        $user = $this->playerWithTransfers(completed: 21);

        $response = $this->actingAs($user)->get(self::URI.'?status=completed&direction=cash_to_win');

        $response->assertOk();

        $html = (string) $response->getContent();

        $this->assertMatchesRegularExpression(
            '/href="[^"]*status=completed[^"]*"/',
            $html,
            'Pagination lost the status filter: page 2 would show transfers the player filtered out.',
        );
        $this->assertMatchesRegularExpression(
            '/href="[^"]*direction=cash_to_win[^"]*"/',
            $html,
            'Pagination lost the direction filter.',
        );

        $this->actingAs($user)
            ->get(self::URI.'?status=completed&direction=cash_to_win&page=2')
            ->assertOk()
            ->assertViewHas('meta', static fn (array $meta): bool => (int) $meta['page'] === 2);
    }

    public function test_the_page_is_read_only_and_shows_no_success_banner(): void
    {
        $user = $this->playerWithTransfers(completed: 1);

        $response = $this->actingAs($user)->get(self::URI);

        $response->assertOk();

        $html = (string) $response->getContent();

        // Nothing on a history page may post money anywhere.
        $this->assertStringNotContainsString('action="'.route('player.transfer.store').'"', $html);
        $this->assertStringNotContainsString(__('transfer.messages.completed'), $html);
        $this->assertStringNotContainsString(__('transfer.messages.already_applied'), $html);
        // It is a private account ledger and must say so to crawlers.
        $this->assertStringContainsString('<meta name="robots" content="noindex,nofollow">', $html);
    }

    public function test_a_json_client_still_receives_the_documented_envelope(): void
    {
        $user = $this->playerWithTransfers(completed: 1);

        $response = $this->actingAs($user)->getJson(self::URI);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'data' => ['transfers'],
            'meta' => ['page', 'per_page', 'total', 'last_page', 'filters'],
        ]);
    }

    public function test_the_transfer_panel_partial_renders_the_live_form_when_given_panel_data(): void
    {
        $user = $this->player();

        $panel = app(\App\Services\Transfer\TransferPanelService::class)->panelData($user);

        $html = view('player.partials.transfer-panel', ['transferPanel' => $panel])->render();

        $this->assertStringContainsString(route('player.transfer.store'), $html);
        $this->assertStringContainsString('id="balance-transfer-form"', $html);
        $this->assertStringContainsString(__('transfer.submit'), $html);
        // The panel links to the history page this suite covers.
        $this->assertStringContainsString(route('player.transfer.index'), $html);
    }

    public function test_the_wallet_page_does_not_claim_transfers_are_disabled_when_it_has_no_panel_data(): void
    {
        $response = $this->actingAs($this->player())->get(route('player.wallet'));

        $response->assertOk();

        // The panel is included only when the controller supplies its data; an
        // unconditional include would render its "currently disabled" notice on
        // the wallet page for every player.
        $response->assertDontSee(__('transfer.panel_disabled'));
    }
}
