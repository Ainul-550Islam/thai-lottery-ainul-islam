<?php

declare(strict_types=1);

namespace Tests\Feature\BetSlip;

use App\Enums\BetSlipStatus;
use App\Models\Bet;
use App\Models\BetSlip;
use App\Models\User;
use App\Services\BetSlip\BetSlipConfirmationService;
use App\Services\BetSlip\BetSlipRefused;
use App\Services\BetSlip\BetSlipService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * The double-confirm race: two requests for the same slip must produce ONE set of bets.
 *
 * WHAT THIS FILE CAN AND CANNOT PROVE
 * A test process is one PHP process on one connection, so it cannot make two
 * transactions truly overlap. What it CAN do, deterministically, is assert each of the
 * three guarantees that make the real race safe, and replay the exact state the
 * database is in after the winner of a race has committed:
 *
 *   1. Storage: a member has ONE draft slip; the loser of a first-tap race re-reads it.
 *   2. Constraint: bet_slips.confirmation_key is UNIQUE, so even if application code
 *      were wrong the database refuses a second confirmation under the same key.
 *   3. Replay: the loser of a confirm race (same key) gets the winner's slip back with
 *      `replayed = true` and buys nothing; a loser with a DIFFERENT key finds no open
 *      slip and is refused, so it cannot buy the same selections again.
 *
 * A genuinely parallel run (two PHP-FPM workers against MySQL) is an operator gate in
 * docs/ops/GO_LIVE_CHECKLIST.md (load test); it is not claimed here.
 */
final class BetSlipConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private function userWithSlip(): User
    {
        $user = User::factory()->create();

        app(BetSlipService::class)->addItem((int) $user->id, '2d_top', '2d', '07', '10.00');

        return $user;
    }

    private function markConfirmed(int $memberId, string $key): BetSlip
    {
        /** @var BetSlip $slip */
        $slip = BetSlip::query()->where('member_id', $memberId)->firstOrFail();

        // The state the database is in once a winner's transaction has committed.
        $slip->status = BetSlipStatus::Confirmed;
        $slip->confirmation_key = $key;
        $slip->confirmed_at = now();
        $slip->save();

        return $slip;
    }

    public function test_two_first_taps_produce_exactly_one_draft_slip(): void
    {
        $user = User::factory()->create();
        $slips = app(BetSlipService::class);

        $first = $slips->findOrCreateDraft((int) $user->id);
        $second = $slips->findOrCreateDraft((int) $user->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            1,
            BetSlip::query()->where('member_id', $user->id)->where('status', BetSlipStatus::Draft->value)->count(),
            'One member must never hold two draft slips.',
        );
    }

    public function test_adding_items_in_sequence_never_creates_a_second_slip(): void
    {
        $user = User::factory()->create();
        $slips = app(BetSlipService::class);

        $slips->addItem((int) $user->id, '2d_top', '2d', '07', '10.00');
        $slips->addItem((int) $user->id, '2d_top', '2d', '08', '10.00');
        $slips->addItem((int) $user->id, '2d_top', '2d', '07', '5.00');

        $this->assertSame(1, BetSlip::query()->where('member_id', $user->id)->count());
    }

    public function test_the_database_refuses_a_second_slip_with_the_same_confirmation_key(): void
    {
        $first = $this->userWithSlip();
        $second = $this->userWithSlip();

        $this->markConfirmed((int) $first->id, 'key-unique-0001');

        $this->expectException(QueryException::class);

        // Application code is bypassed on purpose: this is the last line of defence.
        $this->markConfirmed((int) $second->id, 'key-unique-0001');
    }

    public function test_the_loser_of_a_confirm_race_with_the_same_key_gets_a_replay_and_buys_nothing(): void
    {
        $user = $this->userWithSlip();
        $this->markConfirmed((int) $user->id, 'key-race-same-01');

        $betsBefore = Bet::query()->count();

        $result = app(BetSlipConfirmationService::class)->confirm((int) $user->id, 'key-race-same-01');

        $this->assertTrue($result['replayed']);
        $this->assertSame([], $result['bets'], 'A replay must not return, or create, new bets.');
        $this->assertSame($betsBefore, Bet::query()->count(), 'A replay must not buy anything.');
    }

    public function test_the_loser_of_a_confirm_race_with_a_different_key_cannot_buy_again(): void
    {
        $user = $this->userWithSlip();
        $this->markConfirmed((int) $user->id, 'key-race-winner-1');

        $betsBefore = Bet::query()->count();

        try {
            app(BetSlipConfirmationService::class)->confirm((int) $user->id, 'key-race-loser-02');
            $this->fail('A second confirm with a different key must not succeed.');
        } catch (BetSlipRefused $e) {
            $this->assertSame('no_active_slip', $e->reasonCode);
            $this->assertSame(404, $e->status);
        }

        $this->assertSame($betsBefore, Bet::query()->count());
    }

    public function test_a_replay_is_repeatable_any_number_of_times(): void
    {
        $user = $this->userWithSlip();
        $this->markConfirmed((int) $user->id, 'key-race-repeat-1');

        $service = app(BetSlipConfirmationService::class);

        for ($i = 0; $i < 5; $i++) {
            $result = $service->confirm((int) $user->id, 'key-race-repeat-1');

            $this->assertTrue($result['replayed']);
        }

        $this->assertSame(1, BetSlip::query()->where('member_id', $user->id)->count());
    }

    public function test_one_members_key_does_not_replay_for_another_member(): void
    {
        $owner = $this->userWithSlip();
        $other = $this->userWithSlip();
        $this->markConfirmed((int) $owner->id, 'key-owner-only-01');

        try {
            // The other member sends the owner's key. Replay is scoped to member_id,
            // so it must NOT hand back the owner's slip; it confirms nothing.
            $result = app(BetSlipConfirmationService::class)->confirm((int) $other->id, 'key-owner-only-01');

            $this->assertFalse($result['replayed'], 'A key must never replay another member\'s slip.');
        } catch (BetSlipRefused $e) {
            // Refused for any reason (item has no draw attached, etc.) is acceptable;
            // what matters is that it is not the owner's confirmed slip.
            $this->assertNotSame('', $e->reasonCode);
        }

        $this->assertSame(
            BetSlipStatus::Confirmed,
            BetSlip::query()->where('member_id', $owner->id)->firstOrFail()->status,
        );
    }
}
