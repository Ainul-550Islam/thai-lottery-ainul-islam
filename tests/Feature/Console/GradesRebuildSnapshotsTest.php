<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\RebuildAccountGradeSnapshots;
use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AccountGradeSnapshot;
use App\Models\FinancialTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionClass;
use Tests\TestCase;

/**
 * PROMPT 2 — grades:rebuild-snapshots (section L, the history builder).
 *
 * The command is the ONLY bulk writer of grade history, so its contract
 * is tested end to end against the real sqlite journal:
 *
 *   - DRY RUN writes nothing, ever.
 *   - FINGERPRINT IDEMPOTENCY: a re-run of the same evaluation writes
 *     nothing, because the fingerprint is UNIQUE by constraint.
 *   - APPEND-ONLY: a changed evaluation becomes a NEW row; existing
 *     rows are never rewritten.
 *   - --account scopes to one user; an unknown account FAILS.
 *   - --as-of pins the exact window; an unparsable --as-of FAILS the
 *     command instead of writing a wrong window.
 *   - --chunk is clamped to 1..1000 (default 200).
 *   - There is NO --force: append-only history cannot be forced.
 *
 * Every money value is a decimal string; every window assertion is an
 * exact datetime string comparison.
 */
final class GradesRebuildSnapshotsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin time so the default (no --as-of) window is deterministic
        // for the fingerprint assertions.
        Carbon::setTestNow(Carbon::parse('2026-09-28 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dry_run_evaluates_but_writes_nothing(): void
    {
        $user = User::factory()->create();
        $this->seedSpend($user, '250.00');

        $this->artisan('grades:rebuild-snapshots', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run complete: 1 would be created, 0 already current, 0 failed.')
            ->assertSuccessful();

        $this->assertSame(0, AccountGradeSnapshot::query()->count(), 'A dry run must not write a single row');
    }

    public function test_real_run_creates_snapshots_from_the_canonical_evaluator(): void
    {
        $goldPlus = User::factory()->create();
        $bronze = User::factory()->create();
        $this->seedSpend($goldPlus, '250.00');
        $this->seedSpend($bronze, '10.00');

        $this->artisan('grades:rebuild-snapshots')
            ->expectsOutputToContain('Done: 2 created, 0 unchanged, 0 failed.')
            ->assertSuccessful();

        $snapshots = AccountGradeSnapshot::query()->orderBy('user_id')->get();
        $this->assertSame(2, $snapshots->count());

        $goldPlusRow = $snapshots->firstWhere('user_id', $goldPlus->id);
        $this->assertNotNull($goldPlusRow);
        $this->assertSame('gold_plus', $goldPlusRow->grade_key);
        $this->assertSame('250.00', (string) $goldPlusRow->qualifying_spend);
        $this->assertSame('0.0200', (string) $goldPlusRow->grade_discount_rate);
        $this->assertSame('2', (string) $goldPlusRow->rule_version);

        $metadata = (array) $goldPlusRow->metadata;
        $this->assertSame('grades:rebuild-snapshots', $metadata['rebuilt_by'] ?? null);
    }

    public function test_rerun_is_idempotent_by_fingerprint(): void
    {
        $user = User::factory()->create();
        $this->seedSpend($user, '250.00');

        $this->artisan('grades:rebuild-snapshots')->assertSuccessful();
        $this->assertSame(1, AccountGradeSnapshot::query()->count());

        // Same evaluation (pinned now) -> identical fingerprint -> nothing new.
        $this->artisan('grades:rebuild-snapshots')
            ->expectsOutputToContain('Done: 0 created, 1 unchanged, 0 failed.')
            ->assertSuccessful();

        $this->assertSame(1, AccountGradeSnapshot::query()->count(), 'A re-run must not duplicate history');
    }

    public function test_a_changed_evaluation_appends_a_new_row_and_never_rewrites(): void
    {
        $user = User::factory()->create();
        $this->seedSpend($user, '250.00');

        $this->artisan('grades:rebuild-snapshots')->assertSuccessful();
        $first = AccountGradeSnapshot::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('gold_plus', $first->grade_key);

        // Cross the next boundary inside the same pinned window.
        $this->seedSpend($user, '100.00');
        $this->artisan('grades:rebuild-snapshots')->assertSuccessful();

        $rows = AccountGradeSnapshot::query()->where('user_id', $user->id)->orderBy('id')->get();
        $this->assertSame(2, $rows->count(), 'A changed evaluation appends, it never updates');

        $still = $rows->first();
        $this->assertSame($first->id, $still->id);
        $this->assertSame('gold_plus', $still->grade_key, 'The first row is untouched (append-only)');
        $this->assertSame('platinum', $rows->last()->grade_key);
        $this->assertSame('350.00', (string) $rows->last()->qualifying_spend);
    }

    public function test_account_option_scopes_the_rebuild_to_one_user(): void
    {
        $target = User::factory()->create();
        $other = User::factory()->create();
        $this->seedSpend($target, '250.00');
        $this->seedSpend($other, '600.00');

        $this->artisan('grades:rebuild-snapshots', ['--account' => (string) $target->id])
            ->expectsOutputToContain(sprintf('for account %d', $target->id))
            ->expectsOutputToContain('Done: 1 created, 0 unchanged, 0 failed.')
            ->assertSuccessful();

        $this->assertSame(1, AccountGradeSnapshot::query()->count());
        $this->assertNotNull(AccountGradeSnapshot::query()->where('user_id', $target->id)->first());
        $this->assertNull(AccountGradeSnapshot::query()->where('user_id', $other->id)->first());
    }

    public function test_unknown_account_fails_and_writes_nothing(): void
    {
        $user = User::factory()->create();
        $this->seedSpend($user, '250.00');

        $this->artisan('grades:rebuild-snapshots', ['--account' => '999999'])
            ->expectsOutputToContain('Account 999999 does not exist.')
            ->assertFailed();

        $this->assertSame(0, AccountGradeSnapshot::query()->count());
    }

    public function test_as_of_pins_the_exact_window(): void
    {
        $user = User::factory()->create();

        // 250.00 inside the pinned window, 400.00 well before it: only the
        // 250.00 may count, proving the window is pinned, not "now".
        $pinned = Carbon::parse('2026-09-28 12:00:00');
        $this->seedSpend($user, '250.00', $pinned->copy()->subDays(5));
        $this->seedSpend($user, '400.00', $pinned->copy()->subDays(120));

        $this->artisan('grades:rebuild-snapshots', ['--as-of' => '2026-09-28 12:00:00'])
            ->expectsOutputToContain('as of 2026-09-28 12:00:00')
            ->assertSuccessful();

        $row = AccountGradeSnapshot::query()->where('user_id', $user->id)->firstOrFail();

        // The EXACT window: same instant minus exactly grade_period_days.
        $days = max(1, (int) config('account_grades.grade_period_days', 30));
        $this->assertSame('2026-09-28 12:00:00', (string) $row->period_end);
        $this->assertSame(
            $pinned->copy()->subDays($days)->toDateTimeString(),
            (string) $row->period_start
        );
        $this->assertSame('250.00', (string) $row->qualifying_spend, 'Spend outside the pinned window must not count');
        $this->assertSame('gold_plus', $row->grade_key);
    }

    public function test_invalid_as_of_fails_the_command_and_writes_nothing(): void
    {
        $user = User::factory()->create();
        $this->seedSpend($user, '250.00');

        $this->artisan('grades:rebuild-snapshots', ['--as-of' => 'not-a-date'])
            ->expectsOutputToContain('Invalid --as-of value: not-a-date')
            ->assertFailed();

        $this->assertSame(0, AccountGradeSnapshot::query()->count(), 'A bad window must never reach history');
    }

    public function test_chunk_is_clamped_to_the_allowed_range(): void
    {
        $users = [];
        for ($i = 0; $i < 3; $i++) {
            $users[] = User::factory()->create();
        }

        // Below the floor -> clamped up to 1; the run still processes everyone.
        $this->artisan('grades:rebuild-snapshots', ['--chunk' => '-5'])
            ->expectsOutputToContain('chunk 1')
            ->expectsOutputToContain('Done: 3 created, 0 unchanged, 0 failed.')
            ->assertSuccessful();

        // Above the ceiling -> clamped down to 1000.
        $this->artisan('grades:rebuild-snapshots', ['--chunk' => '99999'])
            ->expectsOutputToContain('chunk 1000')
            ->assertSuccessful();

        $this->assertSame(3, AccountGradeSnapshot::query()->count());
    }

    public function test_there_is_no_force_option(): void
    {
        // Append-only history cannot be forced. The signature exposes
        // exactly the four safe options and no --force.
        $reflection = new ReflectionClass(RebuildAccountGradeSnapshots::class);
        $defaults = (array) $reflection->getDefaultProperties();
        $signature = (string) ($defaults['signature'] ?? '');
        $this->assertNotSame('', $signature, 'The command signature must be readable for the option audit');

        $this->assertStringNotContainsString('--force', $signature);
        $this->assertStringContainsString('--account=', $signature);
        $this->assertStringContainsString('--as-of=', $signature);
        $this->assertStringContainsString('--dry-run', $signature);
        $this->assertStringContainsString('--chunk=', $signature);
    }

    /**
     * Seed qualifying spend exactly the way the evaluator counts it:
     * a Completed financial transaction with processed_at inside the
     * window. Decimal strings end to end.
     */
    private function seedSpend(User $user, string $amount, ?Carbon $at = null): void
    {
        $at = $at ?? Carbon::now();

        $tx = FinancialTransaction::query()->create([
            'reference_number' => 'TX-'.uniqid(),
            'user_id' => $user->id,
            'wallet_id' => null,
            'type' => TransactionType::BetPlacement,
            'currency' => Currency::THB,
            'amount' => $amount,
            'fee' => '0.00',
            'description' => 'qualifying spend seed',
            'metadata' => ['test' => true],
            'idempotency_key' => 'seed-'.uniqid(),
        ]);

        // Lifecycle fields are not mass-assignable — set explicitly.
        $tx->status = TransactionStatus::Completed;
        $tx->processed_at = $at;
        $tx->save();
    }
}
