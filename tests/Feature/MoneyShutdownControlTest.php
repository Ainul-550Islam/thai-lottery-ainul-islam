<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\MoneyOperationControl;
use App\Models\User;
use App\Services\Compliance\MoneyOperationControlService;
use App\Services\Compliance\MoneyShutdownControlService;
use App\Services\Compliance\RealMoneyTechnicalReadinessService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;
use Throwable;

/**
 * The runtime operational control: default, authority, evidence, concurrency,
 * storage failure, and the two things it must never become.
 *
 * WHY THIS SUITE EXISTS
 * ---------------------
 * A control that can be flipped at runtime is the most dangerous object in a
 * money system, because it is the only one that changes behaviour without a
 * deploy and without a code review. Everything that makes it safe is a
 * property that has to be asserted rather than assumed:
 *
 *   1. it starts closed, and stays closed when its own storage cannot be read;
 *   2. only an administrator can move it, and a player never can;
 *   3. every movement leaves actor, time, previous state, new state and reason;
 *   4. two administrators cannot interleave contradictory updates;
 *   5. closing it refuses admission while writing nothing at all;
 *   6. OPENING IT STILL OPENS NOTHING - live money operations remain closed,
 *      because technical readiness and operational control are evidence and
 *      authorisation is a separate concern this phase does not grant;
 *   7. no HTTP surface anywhere can reach it.
 *
 * §1 to §5 are the mechanism. §6 and §7 are the reason the mechanism is
 * acceptable to ship at all, and they are the assertions that would fail first
 * if somebody later wired this control to an activation.
 */
final class MoneyShutdownControlTest extends TestCase
{
    /**
     * Truncate rather than refresh: the control row, its revision counter and
     * the audit trail it produces are exactly the state these tests assert on, so
     * a leak from one test into the next reads as a corrupt control rather than
     * as a broken test. `Tests\TestCase` migrates once per process and never
     * truncates on its own.
     */
    use DatabaseTruncation;

    /**
     * Tables that must not move when a control refuses an operation. A refusal
     * that writes is worse than no refusal, because it leaves the estate in a
     * state nobody asked for and nobody can explain.
     *
     * @var list<string>
     */
    private const MONEY_TABLES = [
        'ledger_entries',
        'financial_transactions',
        'financial_holds',
        'wallets',
        'deposits',
        'withdrawals',
        'payments',
        'payment_intents',
    ];

    private const MIGRATION = '2026_11_17_000100_create_money_operation_controls_table';

    private function control(): MoneyShutdownControlService
    {
        return app(MoneyShutdownControlService::class);
    }

    private function admission(): MoneyOperationControlService
    {
        return app(MoneyOperationControlService::class);
    }

    private function readiness(): RealMoneyTechnicalReadinessService
    {
        return app(RealMoneyTechnicalReadinessService::class);
    }

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user->refresh();
    }

    private function player(): User
    {
        return User::factory()->create();
    }

    /**
     * @return array<string, int>
     */
    private function moneyRowCounts(): array
    {
        $counts = [];

        foreach (self::MONEY_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * Write a control row directly, bypassing the service.
     *
     * Used to construct states the service would never produce on its own - a
     * corrupted state value, a stale revision - because the point of those tests
     * is how the service READS a row it did not write.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function writeRow(array $attributes): void
    {
        DB::table('money_operation_controls')->updateOrInsert(
            ['control_key' => MoneyShutdownControlService::CONTROL_KEY],
            $attributes + [
                'state' => MoneyShutdownControlService::STATE_CLOSED,
                'previous_state' => null,
                'reason' => null,
                'changed_by_user_id' => null,
                'changed_at' => null,
                'revision' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    // =====================================================================
    // §1 The default is closed, and a corrupt row is also closed.
    // =====================================================================

    public function test_the_control_is_closed_by_default_when_no_row_exists(): void
    {
        self::assertSame(0, DB::table('money_operation_controls')->count(), 'This test asserts the shipped default, so the table must start empty.');

        $control = $this->control();

        self::assertSame(MoneyShutdownControlService::STATE_CLOSED, $control->state());
        self::assertTrue($control->isClosed());
        self::assertFalse($control->isOpen());

        $status = $control->status();

        // The storage is reachable; nobody has simply set the control yet. Those
        // are different facts and the snapshot keeps them apart, because "we
        // cannot read the control" is an incident and "nobody opened it" is the
        // shipped default.
        self::assertTrue($status['storage_available']);
        self::assertFalse($status['permits_money']);
        self::assertFalse($status['activates_live_operations']);
        self::assertNull($status['revision']);

        $threw = null;

        try {
            $control->assertOpen('deposit');
        } catch (Throwable $exception) {
            $threw = $exception;
        }

        self::assertNotNull($threw, 'A control that has never been opened must refuse money movement.');
        self::assertSame('real_money_control_closed', $threw->errorCode());
    }

    public function test_an_unrecognised_state_value_is_read_as_closed(): void
    {
        $this->writeRow(['state' => 'maybe', 'revision' => 7]);

        $control = $this->control();

        self::assertSame(MoneyShutdownControlService::STATE_CLOSED, $control->state(), 'A corrupted row was interpreted generously instead of halting money.');
        self::assertFalse($control->isOpen());
        self::assertSame(MoneyShutdownControlService::STATE_CLOSED, $control->status()['state']);

        // The revision is still reported: an operator investigating a corrupt
        // control needs to know how many times it was touched.
        self::assertSame(7, $control->status()['revision']);
    }

    // =====================================================================
    // §2 Authority: an administrator may, a player may not.
    // =====================================================================

    public function test_a_player_cannot_change_the_control(): void
    {
        $player = $this->player();

        foreach ([['close', 'player tried to halt money'], ['open', 'player tried to resume money']] as [$method, $reason]) {
            $threw = null;

            try {
                $this->control()->{$method}($player, $reason);
            } catch (Throwable $exception) {
                $threw = $exception;
            }

            self::assertNotNull($threw, 'A normal user changed a money operation control with '.$method.'().');
            self::assertSame('real_money_control_forbidden', $threw->errorCode());
        }

        // Refused AND unchanged: an authorization failure that still wrote
        // something would be a privilege escalation with extra steps.
        self::assertSame(0, DB::table('money_operation_controls')->count());
        self::assertSame(0, AuditLog::query()->where('auditable_type', MoneyOperationControl::class)->count());
    }

    public function test_an_administrator_can_close_and_open_the_control(): void
    {
        $admin = $this->admin();
        $control = $this->control();

        $opened = $control->open($admin, 'licensed lane verified in staging rehearsal');

        self::assertTrue($opened['changed']);
        self::assertSame(MoneyShutdownControlService::STATE_OPEN, $opened['state']);
        self::assertSame(MoneyShutdownControlService::STATE_CLOSED, $opened['previous_state']);
        self::assertSame(1, $opened['revision']);
        self::assertTrue($control->isOpen());

        $closed = $control->close($admin, 'provider outage: halting money movement');

        self::assertTrue($closed['changed']);
        self::assertSame(MoneyShutdownControlService::STATE_CLOSED, $closed['state']);
        self::assertSame(MoneyShutdownControlService::STATE_OPEN, $closed['previous_state']);
        self::assertSame(2, $closed['revision']);
        self::assertTrue($control->isClosed());
    }

    public function test_repeating_the_state_already_held_is_not_a_change(): void
    {
        $admin = $this->admin();
        $control = $this->control();

        // The control ships closed, so asking it to close is the first no-op:
        // there is no transition to record and no revision to spend.
        $noop = $control->close($admin, 'the control already ships closed');

        self::assertFalse($noop['changed'], 'Closing a control that was never open manufactured a transition.');
        self::assertSame(MoneyShutdownControlService::STATE_CLOSED, $noop['state']);
        self::assertSame(0, $noop['revision']);

        $first = $control->open($admin, 'initial opening after verification');

        self::assertTrue($first['changed']);
        self::assertSame(1, $first['revision']);

        $replay = $control->open($admin, 'initial opening after verification');

        self::assertFalse($replay['changed'], 'Replaying a state manufactured a transition that never happened.');
        self::assertSame($first['revision'], $replay['revision']);
        self::assertSame(MoneyShutdownControlService::STATE_OPEN, $replay['state']);

        // One change, one audit row. A replay that audited would let anybody
        // inflate the trail with transitions that never occurred - and an
        // incident review that cannot trust the count of its own audit log
        // cannot establish how many times money was stopped and started.
        self::assertSame(1, AuditLog::query()->where('auditable_type', MoneyOperationControl::class)->count());
    }

    // =====================================================================
    // §3 Evidence: actor, timestamp, previous state, new state, reason.
    // =====================================================================

    public function test_every_state_change_records_actor_timestamp_previous_and_new_state(): void
    {
        $admin = $this->admin();

        $before = now()->subSecond();
        $this->control()->open($admin, 'licence and lane verified');
        $this->control()->close($admin, 'suspected provider replay attack');

        $rows = AuditLog::query()
            ->where('auditable_type', MoneyOperationControl::class)
            ->orderBy('id')
            ->get();

        self::assertCount(2, $rows, 'A state change was not audited.');

        $opening = $rows[0];
        $closing = $rows[1];

        self::assertSame(AuditAction::ConfigChange, $opening->action);
        self::assertSame((int) $admin->id, (int) $opening->user_id, 'The audit row does not name the actor.');
        self::assertSame(['state' => 'closed', 'revision' => 0], $opening->old_values);
        self::assertSame(['state' => 'open', 'revision' => 1], $opening->new_values);
        self::assertSame('licence and lane verified', $opening->metadata['reason'] ?? null);
        self::assertSame(MoneyShutdownControlService::CONTROL_KEY, $opening->metadata['control_key'] ?? null);
        self::assertTrue($opening->created_at->greaterThanOrEqualTo($before), 'The audit row carries no usable timestamp.');

        self::assertSame((int) $admin->id, (int) $closing->user_id);
        self::assertSame(['state' => 'open', 'revision' => 1], $closing->old_values);
        self::assertSame(['state' => 'closed', 'revision' => 2], $closing->new_values);
        self::assertSame('suspected provider replay attack', $closing->metadata['reason'] ?? null);

        // Opening a money control is the consequential direction and must not be
        // lost in a low-risk stream.
        self::assertSame('critical', $opening->risk_level->value);
        self::assertSame('high', $closing->risk_level->value);
    }

    public function test_a_change_without_a_reason_is_refused_and_changes_nothing(): void
    {
        $admin = $this->admin();

        foreach (['', '   ', "\n\t "] as $reason) {
            $threw = null;

            try {
                $this->control()->open($admin, $reason);
            } catch (Throwable $exception) {
                $threw = $exception;
            }

            self::assertNotNull($threw, 'A control change was accepted without a reason.');
            self::assertSame('real_money_control_reason_required', $threw->errorCode());
        }

        self::assertSame(0, DB::table('money_operation_controls')->count());
        self::assertSame(0, AuditLog::query()->where('auditable_type', MoneyOperationControl::class)->count());
    }

    // =====================================================================
    // §4 Concurrency: a stale read cannot overwrite a change it never saw.
    // =====================================================================

    public function test_a_stale_revision_is_refused_instead_of_overwriting_a_change_it_never_saw(): void
    {
        $admin = $this->admin();
        $control = $this->control();

        // Two administrators read the same state; the first one acts.
        $seen = $control->status();
        self::assertNull($seen['revision'], 'This test needs the control to start unset.');

        $control->open($admin, 'first administrator opens the control');

        // The second still believes the revision it read. Its write is refused
        // rather than silently discarding the first administrator's change.
        $threw = null;

        try {
            $control->close($admin, 'second administrator acts on a stale read', 0);
        } catch (Throwable $exception) {
            $threw = $exception;
        }

        self::assertNotNull($threw, 'A stale revision overwrote a change it never saw.');
        self::assertSame('real_money_control_conflict', $threw->errorCode());

        self::assertSame(MoneyShutdownControlService::STATE_OPEN, $control->state(), 'The refused write changed the state anyway.');
        self::assertSame(1, $control->status()['revision']);

        // Only the successful transition was audited.
        self::assertSame(1, AuditLog::query()->where('auditable_type', MoneyOperationControl::class)->count());

        // Presenting the CURRENT revision is accepted, which is what makes the
        // guard a concurrency rule rather than a permanent refusal.
        $accepted = $control->close($admin, 'second administrator re-reads and closes', 1);

        self::assertTrue($accepted['changed']);
        self::assertSame(2, $accepted['revision']);
    }

    // =====================================================================
    // §5 Storage failure fails closed - and says it was a storage failure.
    // =====================================================================

    public function test_an_unreadable_control_store_refuses_money_and_says_why(): void
    {
        $admin = $this->admin();
        $control = $this->control();

        $control->open($admin, 'opened before the store went away');
        self::assertTrue($control->isOpen());

        // Remove the table and forget the migration, so the store is genuinely
        // unreadable rather than mocked into saying so. Restored in `finally`:
        // a test that leaves the schema broken turns every later test in the
        // process into a misleading failure.
        Schema::dropIfExists('money_operation_controls');
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();

        try {
            self::assertFalse(Schema::hasTable('money_operation_controls'));

            self::assertSame(MoneyShutdownControlService::STATE_CLOSED, $control->state(), 'An unreadable control store was read as open.');
            self::assertTrue($control->isClosed());

            $status = $control->status();
            self::assertFalse($status['storage_available'], 'The snapshot did not distinguish "cannot read" from "closed".');
            self::assertSame(MoneyShutdownControlService::STATE_CLOSED, $status['state']);
            self::assertFalse($status['permits_money']);

            $threw = null;

            try {
                $control->assertOpen('withdrawal');
            } catch (Throwable $exception) {
                $threw = $exception;
            }

            self::assertNotNull($threw);
            self::assertSame('real_money_control_unavailable', $threw->errorCode(), 'A storage failure was reported as an ordinary closed control.');

            // Writing must fail too, and fail as unavailable rather than
            // appearing to succeed.
            $writeThrew = null;

            try {
                $control->close($admin, 'attempting to write to a store that is gone');
            } catch (Throwable $exception) {
                $writeThrew = $exception;
            }

            self::assertNotNull($writeThrew);
            self::assertSame('real_money_control_unavailable', $writeThrew->errorCode());
        } finally {
            $this->artisan('migrate', ['--force' => true])->run();
        }

        self::assertTrue(Schema::hasTable('money_operation_controls'), 'The control table was not restored for the tests that follow.');
    }

    // =====================================================================
    // §6 Admission: closing halts money and writes nothing; opening opens
    //    the control and nothing else.
    // =====================================================================

    public function test_a_closed_control_refuses_admission_and_writes_nothing(): void
    {
        $before = $this->moneyRowCounts();

        $decision = $this->admission()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '100.00', 'THB');

        self::assertFalse($decision['allowed']);
        self::assertSame(MoneyOperationControlService::REFUSED_OPERATION_CONTROL, $decision['status']);
        self::assertSame('real_money_control_closed', $decision['reason']);
        self::assertFalse($decision['gates']['operation_control_open']);
        self::assertArrayHasKey('operation_control', $decision['blocking']);

        // The refusal names the control rather than the master switch, because
        // the two have the same effect and completely different remedies.
        self::assertTrue($decision['gates']['master_switch_open'], 'This test needed the master switch open so that the control is what refuses.');

        self::assertSame($before, $this->moneyRowCounts(), 'A refused operation changed the money estate.');
    }

    public function test_an_unreadable_control_refuses_admission_under_its_own_reason(): void
    {
        Schema::dropIfExists('money_operation_controls');
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();

        try {
            $decision = $this->admission()->evaluate(RealMoneyTechnicalReadinessService::OP_DEPOSIT, '100.00', 'THB');

            self::assertFalse($decision['allowed']);
            self::assertSame('real_money_control_unavailable', $decision['reason']);
            self::assertStringContainsString('could not be read', $decision['blocking']['operation_control']);
        } finally {
            $this->artisan('migrate', ['--force' => true])->run();
        }
    }

    public function test_opening_the_control_never_opens_live_operations(): void
    {
        $admin = $this->admin();
        $this->control()->open($admin, 'operator opens the runtime control');

        self::assertTrue($this->control()->isOpen(), 'The fixture did not take effect.');

        $readiness = $this->readiness();

        self::assertFalse($readiness->liveOperationsOpen(), 'Opening the runtime control activated live money operations.');

        $report = $readiness->report();

        self::assertFalse($report['live_operations_open']);

        // Published on the control's own snapshot as a constant false, so no
        // consumer of the status can mistake the latch for an activation.
        self::assertFalse($this->control()->status()['activates_live_operations']);

        // The blocker list must still name the reason that no code path in this
        // phase can clear, otherwise an operator reads "control open" and
        // concludes that money is live.
        $blockers = $report['live_operation_blockers'];
        self::assertNotEmpty($blockers);

        $joined = implode(' | ', $blockers);
        self::assertStringContainsString('live activation is separately controlled', $joined);
        self::assertStringNotContainsString('runtime money operation control is closed', $joined, 'The report still blames a control that was opened.');
    }

    public function test_a_closed_control_is_named_as_a_live_operation_blocker(): void
    {
        $admin = $this->admin();
        $this->control()->open($admin, 'open first so the close is a real transition');
        $this->control()->close($admin, 'incident: halting money');

        $blockers = $this->readiness()->liveOperationBlockers();

        self::assertContains('the runtime money operation control is closed', $blockers);
    }

    // =====================================================================
    // §7 No public surface: nothing reachable over HTTP can move this control.
    // =====================================================================

    public function test_no_controller_or_route_can_change_the_control(): void
    {
        $offenders = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path('app/Http'), RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (is_string($source) && str_contains($source, 'MoneyShutdownControlService')) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        foreach (glob(base_path('routes/*.php')) ?: [] as $routeFile) {
            $source = file_get_contents($routeFile);

            if (is_string($source) && str_contains($source, 'MoneyShutdownControlService')) {
                $offenders[] = str_replace(base_path().'/', '', $routeFile);
            }
        }

        self::assertSame(
            [],
            $offenders,
            'A money operation control became reachable over HTTP. Halting money from a request handler is survivable; resuming it from one is how an activation gets smuggled in.',
        );
    }

    public function test_the_control_surface_is_published_read_only_by_the_operation_controls_report(): void
    {
        $surface = $this->admission()->controls();

        self::assertSame(MoneyOperationControlService::MODE_ENFORCE, $surface['modes']['operation_control']);
        self::assertArrayHasKey('operation_control', $surface);
        self::assertSame(MoneyShutdownControlService::CONTROL_KEY, $surface['operation_control']['control_key']);
        self::assertSame(MoneyShutdownControlService::STATE_CLOSED, $surface['operation_control']['state']);
        self::assertFalse($surface['operation_control']['activates_live_operations']);
    }
}
