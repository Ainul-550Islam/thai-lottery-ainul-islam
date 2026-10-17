<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Enums\Currency;
use App\Enums\SelfExclusionStatus;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;
use App\Services\ResponsibleGaming\SelfExclusionReleaseGate;
use App\Services\ResponsibleGaming\SelfExclusionService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Part 62 — when does a self-excluded player get let back in, and why?
 *
 * WHAT IS BEING PROVEN
 *  1. An active exclusion holds the player.
 *  2. An active exclusion whose period has ended releases the player, and the
 *     stale row is named so the missing sweep is visible rather than silent.
 *  3. THE MEASURED GAP: a request the player has already made, whose
 *     `effective_at` has passed but which the activation sweep has not moved to
 *     `active` yet, is HELD by this gate — while the existing
 *     `SelfExclusionService::hasActiveExclusion()` says the player is NOT
 *     excluded. Both answers are asserted in the same test, because the point
 *     is the disagreement, not either side alone.
 *  4. `log` mode records and never raises; `enforce` mode raises.
 *
 * WHAT IS NOT CLAIMED
 * This gate does not write to the exclusion and does not replace the sweep.
 * It is advisory in the shipped configuration: nothing in the bet or deposit
 * path calls `assertMayPlay()` yet, so the activation gap still exists in
 * production until a caller is added.
 */
final class SelfExclusionReleaseGateTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settlement-guards.enabled' => true]);
    }

    private function player(): User
    {
        return User::factory()->create([
            'password' => Hash::make('ReleaseGatePass123!'),
        ])->fresh();
    }

    private function exclusion(
        int $userId,
        SelfExclusionStatus $status,
        string $effectiveAt,
        string $endsAt,
        string $fingerprint,
    ): int {
        return (int) DB::table('self_exclusions')->insertGetId([
            'user_id' => $userId,
            'status' => $status->value,
            'scope' => 'account',
            'reason_code' => 'player_request',
            'request_fingerprint' => $fingerprint,
            'effective_at' => $effectiveAt,
            'ends_at' => $endsAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function gate(): SelfExclusionReleaseGate
    {
        return app(SelfExclusionReleaseGate::class);
    }

    #[Test]
    public function an_active_exclusion_holds_the_player(): void
    {
        $user = $this->player();
        $id = $this->exclusion(
            (int) $user->getKey(),
            SelfExclusionStatus::Active,
            now()->subDay()->toDateTimeString(),
            now()->addDays(30)->toDateTimeString(),
            'fp-active-holds',
        );

        $decision = $this->gate()->decision((int) $user->getKey());

        $this->assertFalse($decision['released']);
        $this->assertSame('active_exclusion', $decision['reason']);
        $this->assertSame($id, $decision['exclusion_id']);
        $this->assertFalse($decision['sweep_overdue'], 'nothing is stale: the period has not ended');
    }

    #[Test]
    public function an_active_row_whose_period_ended_releases_the_player_and_names_the_stale_row(): void
    {
        $user = $this->player();
        $this->exclusion(
            (int) $user->getKey(),
            SelfExclusionStatus::Active,
            now()->subDays(31)->toDateTimeString(),
            now()->subHour()->toDateTimeString(),
            'fp-ended-releases',
        );

        $decision = $this->gate()->decision((int) $user->getKey());

        $this->assertTrue($decision['released'], 'the clock, not the sweep, releases the player');
        $this->assertSame('period_ended', $decision['reason']);
        $this->assertTrue($decision['sweep_overdue'], 'the row still says active; the expiry sweep has not run');
    }

    #[Test]
    public function a_due_but_unactivated_request_holds_this_gate_while_the_existing_gate_says_no_exclusion(): void
    {
        $user = $this->player();
        $userId = (int) $user->getKey();

        $this->exclusion(
            $userId,
            SelfExclusionStatus::Requested,
            now()->subMinutes(10)->toDateTimeString(),
            now()->addDays(30)->toDateTimeString(),
            'fp-due-not-active',
        );

        // The existing gate, measured as it stands today.
        $this->assertFalse(
            app(SelfExclusionService::class)->hasActiveExclusion($userId),
            'the shipped gate only looks at rows already labelled active',
        );

        $decision = $this->gate()->decision($userId);

        $this->assertFalse($decision['released'], 'this gate reads the request and the clock, not the label');
        $this->assertSame('activation_pending', $decision['reason']);
        $this->assertTrue($decision['activation_pending']);

        $this->assertSame([$userId], $this->gate()->accountsInActivationGap());
    }

    #[Test]
    public function a_request_that_is_not_yet_effective_does_not_hold_the_player(): void
    {
        $user = $this->player();
        $this->exclusion(
            (int) $user->getKey(),
            SelfExclusionStatus::Requested,
            now()->addDay()->toDateTimeString(),
            now()->addDays(31)->toDateTimeString(),
            'fp-future-request',
        );

        $decision = $this->gate()->decision((int) $user->getKey());

        $this->assertTrue($decision['released']);
        $this->assertSame('request_not_yet_effective', $decision['reason']);
    }

    #[Test]
    public function a_player_with_no_exclusion_is_released(): void
    {
        $user = $this->player();

        $decision = $this->gate()->decision((int) $user->getKey());

        $this->assertTrue($decision['released']);
        $this->assertSame('no_exclusion', $decision['reason']);
        $this->assertNull($decision['exclusion_id']);
    }

    #[Test]
    public function log_mode_records_and_never_raises(): void
    {
        $user = $this->player();
        $userId = (int) $user->getKey();
        $this->exclusion(
            $userId,
            SelfExclusionStatus::Active,
            now()->subDay()->toDateTimeString(),
            now()->addDays(30)->toDateTimeString(),
            'fp-log-mode',
        );

        $result = $this->gate()->assertMayPlay($user);

        $this->assertSame('log', $result['mode']);
        $this->assertFalse($result['released']);

        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('metadata->guard', SelfExclusionReleaseGate::GUARD)
                ->where('metadata->verdict', 'held')
                ->count(),
        );
    }

    #[Test]
    public function enforce_mode_raises_for_a_player_the_gate_holds(): void
    {
        $user = $this->player();
        $this->exclusion(
            (int) $user->getKey(),
            SelfExclusionStatus::Active,
            now()->subDay()->toDateTimeString(),
            now()->addDays(30)->toDateTimeString(),
            'fp-enforce-mode',
        );
        config(['settlement-guards.guards.release_gate' => 'enforce']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('release gate holds');

        $this->gate()->assertMayPlay($user);
    }

    #[Test]
    public function the_deposit_path_really_asks_the_gate_and_records_the_answer(): void
    {
        // Wiring proof, not a double: the real DepositService runs, and the
        // evidence is the audit row the real gate writes. The player has no
        // exclusion, so the request proceeds and the gate records a release.
        $user = $this->player();
        $wallet = Wallet::factory()->for($user)->withBalance('1000.00')->create(['currency' => Currency::THB]);

        $deposit = app(DepositService::class)->request($wallet, Money::of('100.00', Currency::THB));

        $this->assertTrue($deposit->exists);

        $rows = DB::table('audit_logs')
            ->where('metadata->guard', SelfExclusionReleaseGate::GUARD)
            ->get();

        $this->assertCount(1, $rows, 'exactly one release-gate observation for this deposit');
        $this->assertSame('released', json_decode((string) $rows[0]->metadata, true)['verdict']);
        $this->assertSame('no_exclusion', json_decode((string) $rows[0]->metadata, true)['context']['reason']);
    }

    #[Test]
    public function off_mode_returns_a_decision_without_recording_anything(): void
    {
        $user = $this->player();
        $this->exclusion(
            (int) $user->getKey(),
            SelfExclusionStatus::Active,
            now()->subDay()->toDateTimeString(),
            now()->addDays(30)->toDateTimeString(),
            'fp-off-mode',
        );
        config(['settlement-guards.enabled' => false]);

        $result = $this->gate()->assertMayPlay($user);

        $this->assertSame('off', $result['mode']);
        $this->assertFalse($result['released'], 'the decision is still correct when nothing is recorded');
        $this->assertSame(0, DB::table('audit_logs')->where('metadata->guard', SelfExclusionReleaseGate::GUARD)->count());
    }
}
