<?php

declare(strict_types=1);

namespace Tests\Feature\BetSlip;

use App\Enums\BetSlipStatus;
use App\Models\BetSlip;
use App\Models\BetSlipItem;
use App\Models\User;
use App\Services\BetSlip\BetSlipRefused;
use App\Services\BetSlip\BetSlipService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The draft slip's own rules: add, merge, remove, clear, caps, expiry, refusals.
 *
 * Nothing in this class moves money, which is the point being proved: a slip is a
 * basket, and everything here runs without a wallet, a ledger account or a draw.
 */
final class BetSlipServiceTest extends TestCase
{
    use DatabaseTruncation;

    private BetSlipService $slips;

    protected function setUp(): void
    {
        parent::setUp();

        $this->slips = app(BetSlipService::class);
    }

    private function member(): User
    {
        return User::factory()->create();
    }

    public function test_a_member_gets_exactly_one_draft_slip(): void
    {
        $user = $this->member();

        $first = $this->slips->findOrCreateDraft((int) $user->id);
        $second = $this->slips->findOrCreateDraft((int) $user->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BetSlip::query()->where('member_id', $user->id)->count());
        $this->assertSame(BetSlipStatus::Draft, $second->status);
    }

    public function test_current_draft_does_not_create_a_row(): void
    {
        $user = $this->member();

        $this->assertNull($this->slips->currentDraft((int) $user->id));
        $this->assertSame(0, BetSlip::query()->where('member_id', $user->id)->count());
    }

    public function test_adding_an_item_updates_the_denormalised_totals(): void
    {
        $user = $this->member();

        $result = $this->slips->addItem(
            memberId: (int) $user->id,
            gameCode: '2d_top',
            betType: '2d',
            number: '07',
            stake: '25.50',
        );

        $this->assertFalse($result['merged']);
        $this->assertSame('25.50', (string) $result['slip']->total_stake);
        $this->assertSame(1, $result['slip']->item_count);
        // Leading zeros survive the round trip: the stored value is '07', not 7.
        $this->assertSame('07', $result['item']->number);
    }

    public function test_adding_the_same_selection_twice_merges_the_stake_instead_of_duplicating(): void
    {
        $user = $this->member();

        $this->slips->addItem((int) $user->id, '2d_top', '2d', '07', '10.00');
        $second = $this->slips->addItem((int) $user->id, '2d_top', '2d', '07', '15.25');

        $this->assertTrue($second['merged']);
        $this->assertSame(1, BetSlipItem::query()->where('bet_slip_id', $second['slip']->id)->count());
        // Exact decimal addition, not float: 10.00 + 15.25 = 25.25.
        $this->assertSame('25.25', $second['item']->exactStake());
    }

    public function test_remove_and_clear_recompute_the_totals(): void
    {
        $user = $this->member();

        $a = $this->slips->addItem((int) $user->id, '2d_top', '2d', '07', '10.00');
        $this->slips->addItem((int) $user->id, '2d_top', '2d', '08', '5.00');

        $afterRemove = $this->slips->removeItem((int) $user->id, (int) $a['item']->id);

        $this->assertSame('5.00', (string) $afterRemove->total_stake);
        $this->assertSame(1, $afterRemove->item_count);

        $afterClear = $this->slips->clear((int) $user->id);

        $this->assertSame('0.00', (string) $afterClear->total_stake);
        $this->assertSame(0, $afterClear->item_count);
    }

    public function test_removing_an_item_that_is_not_there_is_not_an_error(): void
    {
        $user = $this->member();

        $slip = $this->slips->removeItem((int) $user->id, 999999);

        $this->assertSame(0, $slip->item_count);
    }

    public function test_the_item_cap_is_enforced_from_config(): void
    {
        config(['player_experience.bet_slip.max_items' => 2]);

        $user = $this->member();

        $this->slips->addItem((int) $user->id, '2d_top', '2d', '07', '1.00');
        $this->slips->addItem((int) $user->id, '2d_top', '2d', '08', '1.00');

        $this->expectException(BetSlipRefused::class);

        try {
            $this->slips->addItem((int) $user->id, '2d_top', '2d', '09', '1.00');
        } catch (BetSlipRefused $e) {
            $this->assertSame('slip_full', $e->reasonCode);

            throw $e;
        }
    }

    public function test_an_unavailable_game_is_refused(): void
    {
        $user = $this->member();

        $this->expectException(BetSlipRefused::class);

        try {
            $this->slips->addItem((int) $user->id, 'not_a_market', '2d', '07', '1.00');
        } catch (BetSlipRefused $e) {
            $this->assertSame('game_not_available', $e->reasonCode);

            throw $e;
        }
    }

    /**
     * The mockup's "GLO L6 2D/3D direct bet" does not exist in this codebase and
     * must be REFUSED rather than invented. This test is the contract for the gap
     * reported in the final table.
     */
    public function test_the_mockups_glo_l6_per_number_product_is_refused_as_not_implemented(): void
    {
        $user = $this->member();

        $this->expectException(BetSlipRefused::class);

        try {
            $this->slips->addItem((int) $user->id, 'glo_l6_2d', '2d', '07', '1.00');
        } catch (BetSlipRefused $e) {
            $this->assertSame('game_not_implemented', $e->reasonCode);
            $this->assertStringContainsString('not implemented', $e->getMessage());

            throw $e;
        }
    }

    public function test_a_wrong_width_number_is_refused(): void
    {
        $user = $this->member();

        $this->expectException(BetSlipRefused::class);

        try {
            // 2d_top needs exactly two digits.
            $this->slips->addItem((int) $user->id, '2d_top', '2d', '007', '1.00');
        } catch (BetSlipRefused $e) {
            $this->assertSame('invalid_number', $e->reasonCode);

            throw $e;
        }
    }

    public function test_an_invalid_stake_is_refused(): void
    {
        $user = $this->member();

        foreach (['0', '-5', 'abc', ''] as $stake) {
            try {
                $this->slips->addItem((int) $user->id, '2d_top', '2d', '07', $stake);
                $this->fail('Stake "'.$stake.'" should have been refused.');
            } catch (BetSlipRefused $e) {
                $this->assertContains($e->reasonCode, ['invalid_stake', 'stake_too_large']);
            }
        }
    }

    public function test_a_non_wallet_funding_source_is_refused(): void
    {
        $user = $this->member();

        $this->expectException(BetSlipRefused::class);

        try {
            $this->slips->addItem((int) $user->id, '2d_top', '2d', '07', '1.00', 'free_bet');
        } catch (BetSlipRefused $e) {
            $this->assertContains($e->reasonCode, ['funding_source_unsupported', 'funding_source_mismatch']);

            throw $e;
        }
    }

    public function test_a_slip_past_its_ttl_is_reported_as_expired_and_replaced(): void
    {
        $user = $this->member();

        $slip = $this->slips->findOrCreateDraft((int) $user->id);
        $slip->expires_at = Carbon::now()->subMinute();
        $slip->save();

        $this->assertTrue($slip->fresh()->hasExpired());
        $this->assertFalse($slip->fresh()->isEditable());

        $replacement = $this->slips->findOrCreateDraft((int) $user->id);

        $this->assertNotSame($slip->id, $replacement->id);
        $this->assertSame(BetSlipStatus::Expired, $slip->fresh()->status);
        $this->assertSame(BetSlipStatus::Draft, $replacement->status);
    }

    public function test_expire_marks_a_draft_and_is_idempotent_on_a_final_row(): void
    {
        $user = $this->member();
        $slip = $this->slips->findOrCreateDraft((int) $user->id);

        $expired = $this->slips->expire($slip);
        $again = $this->slips->expire($expired);

        $this->assertSame(BetSlipStatus::Expired, $again->status);
    }

    public function test_the_total_is_always_the_sum_of_the_items(): void
    {
        $user = $this->member();

        $this->slips->addItem((int) $user->id, '2d_top', '2d', '07', '10.10');
        $this->slips->addItem((int) $user->id, '2d_top', '2d', '08', '20.20');
        $slip = $this->slips->addItem((int) $user->id, '2d_top', '2d', '09', '30.30')['slip'];

        $sum = '0.00';

        foreach (BetSlipItem::query()->where('bet_slip_id', $slip->id)->get() as $item) {
            $sum = bcadd($sum, $item->exactStake(), 2);
        }

        $this->assertSame($sum, (string) $slip->total_stake);
        $this->assertSame('60.60', $sum);
    }

    public function test_draws_that_cannot_take_bets_are_reported_as_closed(): void
    {
        // A draw id that does not exist cannot accept a selection either.
        $this->assertFalse($this->slips->drawAcceptsSelections(999999));
    }
}
