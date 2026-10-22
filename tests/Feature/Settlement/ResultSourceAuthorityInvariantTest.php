<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Services\Draw\ResultSourceAuthorityService;
use App\Services\Draw\SettlementPayoutInvariantService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Part 62 — the result source and the payout totals are checked against each other.
 *
 * WHAT IS BEING PROVEN
 *  1. A normally published draw passes both guards: the `winning_numbers`
 *     table (the authority the schema itself names) and the `draw_results`
 *     summary agree, and nothing has been paid beyond what was published.
 *  2. When one side is changed, the guard names the side that disagrees — it
 *     does not silently accept it and it does not repair it.
 *  3. `log` mode records and never raises. `enforce` mode raises. `off` mode
 *     checks nothing, which is visible in the verdict rather than looking like
 *     agreement.
 *
 * WHAT IS NOT CLAIMED
 * These guards read. They cannot reverse a payout, correct a counter or
 * republish a result. A divergence found by a spot check that nobody scheduled
 * is still a late divergence.
 */
final class ResultSourceAuthorityInvariantTest extends SettlementTestCase
{
    private function authority(): ResultSourceAuthorityService
    {
        return app(ResultSourceAuthorityService::class);
    }

    private function payouts(): SettlementPayoutInvariantService
    {
        return app(SettlementPayoutInvariantService::class);
    }

    private function drawId(): int
    {
        $published = $this->publishResult($this->fixture()['draw'], '000007', '05');

        return (int) $published['draw']->getKey();
    }

    #[Test]
    public function a_normally_published_draw_passes_both_guards(): void
    {
        $drawId = $this->drawId();

        $authority = $this->authority()->verify($drawId);
        $payouts = $this->payouts()->verify($drawId);

        $this->assertSame('agree', $authority['verdict'], 'the summary row agrees with the authority: '.implode('; ', $authority['divergences']));
        $this->assertSame([], $authority['divergences']);
        $this->assertGreaterThan(0, $authority['authority']['winning_number_rows']);
        $this->assertSame('log', $authority['mode']);

        $this->assertSame('agree', $payouts['verdict'], 'nothing has been paid beyond the published entitlement');
        $this->assertSame($authority['authority']['winning_numbers_total_payout'], $payouts['figures']['published_entitlement']);
    }

    #[Test]
    public function changing_the_summary_row_is_named_not_repaired(): void
    {
        $drawId = $this->drawId();

        DB::table('draw_results')->where('draw_id', $drawId)->update(['total_payout' => 999999.00]);

        $result = $this->authority()->verify($drawId);

        $this->assertSame('divergent', $result['verdict']);
        $this->assertNotEmpty($result['divergences']);
        $this->assertStringContainsString('total_payout_disagrees', $result['divergences'][0]);

        // The guard reported; it did not fix. The row is still what the test set.
        $this->assertSame(
            '999999.00',
            number_format((float) DB::table('draw_results')->where('draw_id', $drawId)->value('total_payout'), 2, '.', ''),
        );
    }

    #[Test]
    public function log_mode_records_and_never_raises_even_on_divergence(): void
    {
        $drawId = $this->drawId();
        DB::table('draw_results')->where('draw_id', $drawId)->update(['total_payout' => 999999.00]);

        $result = $this->authority()->verify($drawId);

        $this->assertSame('log', $result['mode']);
        $this->assertSame('divergent', $result['verdict']);

        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('metadata->guard', ResultSourceAuthorityService::GUARD)
                ->where('metadata->verdict', 'divergent')
                ->count(),
            'the divergence is recorded in the audit trail',
        );
    }

    #[Test]
    public function enforce_mode_raises_on_the_same_divergence(): void
    {
        $drawId = $this->drawId();
        DB::table('draw_results')->where('draw_id', $drawId)->update(['total_payout' => 999999.00]);
        config(['settlement-guards.guards.result_authority' => 'enforce']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Result authority diverges');

        $this->authority()->verify($drawId);
    }

    #[Test]
    public function the_verify_command_is_registered_and_runs_over_a_settled_draw(): void
    {
        $drawId = $this->drawId();

        $this->artisan('settlement:verify-guards', ['--draw' => (string) $drawId])
            ->expectsOutputToContain('draw '.$drawId.' authority: agree')
            ->expectsOutputToContain('draw '.$drawId.' payouts: agree')
            ->assertExitCode(0);
    }

    #[Test]
    public function off_mode_checks_nothing_and_says_so(): void
    {
        $drawId = $this->drawId();
        config(['settlement-guards.mode' => 'off']);

        $result = $this->authority()->verify($drawId);

        $this->assertSame('off', $result['verdict']);
        $this->assertFalse($result['checked']);
    }

    #[Test]
    public function paying_more_than_the_published_entitlement_is_flagged(): void
    {
        $drawId = $this->drawId();
        // One user exists in this truncated test database: the fixture's player.
        $userId = (int) DB::table('users')->value('id');

        $published = (string) DB::table('winning_numbers')
            ->whereNull('deleted_at')
            ->where('draw_id', $drawId)
            ->sum('total_payout');

        DB::table('payouts')->insert([
            'reference_number' => 'PGUARD-'.$drawId.'-1',
            'draw_id' => $drawId,
            'user_id' => $userId,
            'status' => 'completed',
            'currency' => 'THB',
            'amount' => (float) $published + 5000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->payouts()->verify($drawId);

        $this->assertSame('divergent', $result['verdict']);
        $this->assertTrue(
            collect($result['divergences'])->contains(fn (string $d): bool => str_starts_with($d, 'over_published:')),
            'the paid total exceeded the published entitlement: '.implode('; ', $result['divergences']),
        );
        $this->assertTrue(
            collect($result['divergences'])->contains(fn (string $d): bool => str_starts_with($d, 'counter_mismatch:')),
            'the draw counter no longer matches what was paid',
        );
    }
}
