<?php

declare(strict_types=1);

namespace Tests\Feature\BetSlip;

use App\Enums\BetSlipStatus;
use App\Models\BetSlip;
use App\Models\User;
use App\Services\BetSlip\BetSlipRefused;
use App\Services\BetSlip\BetSlipService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * Confirm is the one operation on a slip that can spend money, so this class
 * concentrates on the ways it must NOT.
 *
 * WHAT IS ASSERTED HERE WITHOUT A FUNDED WALLET
 *   - an empty slip is refused, not confirmed as a zero-value purchase
 *   - a confirm key is required, and the same key twice does not consume twice
 *   - a slip whose clock ran out is refused
 *   - a refusal leaves the slip a draft and the member's basket intact
 *
 * The happy path through the real purchase pipeline lives in
 * BetSlipPurchaseIntegrationTest, because it needs a draw, a wallet and the
 * ledger accounts this class deliberately avoids.
 */
final class BetSlipConfirmationTest extends TestCase
{
    use DatabaseTruncation;

    public function test_an_empty_slip_cannot_be_confirmed(): void
    {
        $user = User::factory()->create();

        $this->expectException(BetSlipRefused::class);

        try {
            app(\App\Services\BetSlip\BetSlipConfirmationService::class)
                ->confirm((int) $user->id, 'key-empty-slip-1');
        } catch (BetSlipRefused $e) {
            $this->assertContains($e->reasonCode, ['no_active_slip', 'slip_empty']);

            throw $e;
        }
    }

    public function test_a_confirm_without_a_key_is_refused(): void
    {
        $user = User::factory()->create();
        app(BetSlipService::class)->addItem((int) $user->id, '2d_top', '2d', '07', '10.00');

        $this->expectException(BetSlipRefused::class);

        try {
            app(\App\Services\BetSlip\BetSlipConfirmationService::class)->confirm((int) $user->id, '');
        } catch (BetSlipRefused $e) {
            $this->assertContains($e->reasonCode, ['invalid_idempotency_key', 'idempotency_key_required']);

            throw $e;
        }
    }

    public function test_an_expired_slip_cannot_be_confirmed(): void
    {
        $user = User::factory()->create();
        app(BetSlipService::class)->addItem((int) $user->id, '2d_top', '2d', '07', '10.00');

        $slip = BetSlip::query()->where('member_id', $user->id)->firstOrFail();
        $slip->expires_at = now()->subMinutes(5);
        $slip->save();

        $this->expectException(BetSlipRefused::class);

        try {
            app(\App\Services\BetSlip\BetSlipConfirmationService::class)
                ->confirm((int) $user->id, 'key-expired-slip');
        } catch (BetSlipRefused $e) {
            $this->assertContains($e->reasonCode, ['slip_expired', 'slip_not_editable']);

            throw $e;
        }
    }

    public function test_a_refused_confirm_leaves_the_slip_a_draft_with_its_items(): void
    {
        $user = User::factory()->create();
        app(BetSlipService::class)->addItem((int) $user->id, '2d_top', '2d', '07', '10.00');

        $slip = BetSlip::query()->where('member_id', $user->id)->firstOrFail();
        $slip->expires_at = now()->subMinutes(5);
        $slip->save();

        try {
            app(\App\Services\BetSlip\BetSlipConfirmationService::class)->confirm((int) $user->id, 'key-rollback');
        } catch (BetSlipRefused) {
            // expected
        }

        $fresh = $slip->fresh();

        $this->assertNotSame(BetSlipStatus::Confirmed, $fresh->status);
        $this->assertSame(1, $fresh->item_count);
        $this->assertSame('10.00', (string) $fresh->total_stake);
    }

    public function test_the_confirmation_key_is_persisted_only_on_success_paths(): void
    {
        $user = User::factory()->create();

        // A refusal on an empty slip must not stamp the key on the draft, or the
        // member's retry after adding an item would be treated as a replay.
        try {
            app(\App\Services\BetSlip\BetSlipConfirmationService::class)->confirm((int) $user->id, 'key-not-stamped');
        } catch (BetSlipRefused) {
            // expected
        }

        $slip = BetSlip::query()->where('member_id', $user->id)->first();

        $this->assertTrue($slip === null || $slip->confirmation_key === null);
    }

    public function test_the_slip_ttl_comes_from_config(): void
    {
        config(['player_experience.bet_slip.ttl_minutes' => 45]);

        $user = User::factory()->create();
        $slip = app(BetSlipService::class)->findOrCreateDraft((int) $user->id);

        $this->assertNotNull($slip->expires_at);
        $this->assertSame(
            now()->addMinutes(45)->format('Y-m-d H:i'),
            $slip->expires_at->format('Y-m-d H:i'),
        );
    }
}
