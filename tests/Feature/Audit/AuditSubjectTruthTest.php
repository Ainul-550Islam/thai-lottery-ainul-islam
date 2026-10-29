<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Filament\Resources\AuditLogResource;
use App\Jobs\Notification\SendFinancialAlertJob;
use App\Listeners\RecordPrizeDisbursementAudit;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use Tests\TestCase;

/**
 * An audit row's subject is a real row, or it is nothing at all.
 *
 * THE SCHEMA ALREADY SAID SO.
 *
 * `audit_logs` declares its subject with `nullableMorphs('auditable')`, the model exposes
 * `scopeForAuditable(string $type, int $id)`, the duplicate guards in the audit listeners
 * filter on `auditable_type` + `auditable_id` + a `metadata->anchor` value, and the Filament
 * table prints `auditable_id` as `#123` beside the class basename and `—` when the type is
 * null. Four independent readers, one contract: the pair either resolves to a row or is empty.
 *
 * WHAT SIXTEEN WRITERS DID ANYWAY.
 *
 * Eleven wrote a lane label into the subject column instead of a class
 * (`'auditable_type' => 'prize_tax_sweep'`) with 0 as the id, three pointed a job's own
 * failure row at `AuditLog::class` + id 0, which reads back as "evidence about audit row
 * zero", and two wrote `Payout::class` + 0 when there was no payout to name. None of it
 * crashed, because nothing eager-loaded the morph: the damage was that those rows are
 * invisible to exactly the query an auditor uses to find them, and that the admin table
 * showed `#0` and a 14-digit run tag as if they were record ids. `AuditLog::creating` now
 * refuses all three shapes, and the labels moved to `metadata['lane']`, which is where two
 * of these writers already put them and where the house's own filters already look.
 *
 * ONE FIX THAT IS NOT ABOUT A COLUMN.
 *
 * RecordPrizeDisbursementAudit's aggregate dedupe filtered `->where('lane', ...)`: a
 * top-level column the table has never had. With no disbursement id the listener would have
 * died on an unknown-column error instead of deduplicating; the intended filter is
 * `metadata->lane`, which this file also asserts.
 *
 * WHAT IS DELIBERATELY NOT DONE.
 *
 * Rows written before this change are left alone. The table is append-only by policy (see
 * the retention decision in docs/report §10.15.5 and the absence of any prune command), so a
 * backfill that rewrote a hundred thousand evidence rows to satisfy a new guard would cost
 * more than the wrong subject it corrects. The guard applies to writes from now on.
 */
final class AuditSubjectTruthTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * Every writer that records a run rather than a row: the file, and the lane label its
     * metadata has to carry now that the subject columns do not.
     */
    private const LANE_WRITERS = [
        'app/Jobs/GeneratePayoutStatementJob.php' => 'payout_statement_generation',
        'app/Jobs/CalculatePrizeTaxJob.php' => 'prize_tax_sweep',
        'app/Jobs/ExecutePayoutTransferJob.php' => 'payout_transfer_run',
        'app/Jobs/CloseExpiredClaimWindowsJob.php' => 'claim_window_sweep',
        'app/Jobs/ExpireUnclaimedPrizesJob.php' => 'prize_claim_sweep',
        'app/Jobs/GeneratePayoutBatchJob.php' => 'payout_batch_generation',
        'app/Jobs/ProcessPayoutBatchJob.php' => 'payout_batch',
        'app/Jobs/SweepUnclaimedPrizesJob.php' => 'unclaimed_prize_disposal',
        'app/Jobs/Payment/ProcessPaymentWebhookJob.php' => 'payment_webhook',
        'app/Jobs/Finance/ProcessFinancialReconciliationJob.php' => 'ledger_reconciliation',
        'app/Jobs/Notification/SendFinancialAlertJob.php' => 'financial_alert',
        'app/Services/Finance/FinancialReconciliationService.php' => 'reconciliation',
    ];

    /** @return \Generator<string> every php file under app/ */
    private function appFiles(): \Generator
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    // -----------------------------------------------------------------------------------------
    // the writers
    // -----------------------------------------------------------------------------------------

    #[Test]
    public function every_lane_writer_records_a_run_with_a_null_subject(): void
    {
        $this->assertCount(12, self::LANE_WRITERS, 'the cluster fixed twelve files');

        foreach (self::LANE_WRITERS as $rel => $label) {
            $src = (string) file_get_contents(base_path($rel));

            $nulls = substr_count($src, "'auditable_type' => null,\n            'auditable_id' => null,");
            $lanes = substr_count($src, "'lane' => '".$label."'");

            $this->assertGreaterThan(0, $nulls, $rel.' writes no null-subject row at all');
            $this->assertSame(
                $nulls,
                $lanes,
                $rel.' has '.$nulls.' null-subject row(s) but '.$lanes.' lane label(s): '
                .'a run row without a name is unfindable, and a label without a null subject is half a fix'
            );
        }
    }

    #[Test]
    public function no_writer_invents_a_subject_label(): void
    {
        $offenders = [];

        foreach ($this->appFiles() as $path) {
            $src = (string) file_get_contents($path);

            if (preg_match_all("/'auditable_type' *=> *'/", $src, $m) > 0) {
                $offenders[] = substr($path, strlen(base_path()) + 1);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "auditable_type is a morph column; a quoted label in it cannot be resolved:\n  - "
            .implode("\n  - ", $offenders)
        );
    }

    #[Test]
    public function no_writer_uses_zero_as_a_subject_id(): void
    {
        $offenders = [];

        foreach ($this->appFiles() as $path) {
            $src = (string) file_get_contents($path);

            if (! preg_match_all("/'auditable_id' *=> *([^\n\r]*?),\n/", $src, $m)) {
                continue;
            }

            foreach ($m[1] as $expression) {
                $expression = trim($expression);

                if ($expression === '0' || str_contains($expression, '?? 0')) {
                    $offenders[] = substr($path, strlen(base_path()) + 1).' => '.$expression;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "0 in auditable_id reads as \"subject number zero\", which no row owns; the table says null:\n  - "
            .implode("\n  - ", $offenders)
        );
    }

    #[Test]
    public function no_writer_names_a_subject_it_cannot_resolve(): void
    {
        // The pair rule, checked where the label rule cannot reach: a class with a
        // null id and a null class with an id both resolve to nothing. This is the
        // shape AccountGradeService wrote for a snapshot it had just inserted and
        // then discarded, so it is the one the whole-tree label scan would miss.
        $offenders = [];

        foreach ($this->appFiles() as $path) {
            $src = (string) file_get_contents($path);

            if (! preg_match_all(
                "/'auditable_type' *=> *([^,\n]+),\n\s*'auditable_id' *=> *([^,\n]+),/",
                $src,
                $m,
                PREG_SET_ORDER
            )) {
                continue;
            }

            foreach ($m as $hit) {
                $type = trim($hit[1]);
                $id = trim($hit[2]);
                $rel = substr($path, strlen(base_path()) + 1);

                if (str_ends_with($type, '::class') && $id === 'null') {
                    $offenders[] = $rel.': '.$type.' with no id';
                }

                if ($type === 'null' && $id !== 'null') {
                    $offenders[] = $rel.': an id ('.$id.') with no subject';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'auditable_type and auditable_id are one nullable morphTo; half a subject is a row '
            .'an auditor cannot open:\n  - '.implode("\n  - ", $offenders)
        );
    }

    #[Test]
    public function no_audit_row_is_about_the_audit_table_itself(): void
    {
        $offenders = [];

        foreach ($this->appFiles() as $path) {
            $src = (string) file_get_contents($path);

            if (str_contains($src, "'auditable_type' => AuditLog::class")) {
                $offenders[] = substr($path, strlen(base_path()) + 1);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "a job's own failure row is evidence about the run, not about audit row 0:\n  - "
            .implode("\n  - ", $offenders)
        );
    }

    // -----------------------------------------------------------------------------------------
    // the door
    // -----------------------------------------------------------------------------------------

    #[Test]
    public function the_guard_refuses_a_label_as_a_subject(): void
    {
        try {
            $this->writeSubject('prize_tax_sweep', 7);
        } catch (\LogicException $e) {
            $this->assertStringContainsString('neither a model class nor a morph alias', $e->getMessage());

            return;
        }

        $this->fail('a label in auditable_type used to be the normal shape of a sweep row');
    }

    #[Test]
    public function the_guard_refuses_zero_as_no_subject(): void
    {
        try {
            $this->writeSubject(User::class, 0);
        } catch (\LogicException $e) {
            $this->assertStringContainsString('is not a primary key', $e->getMessage());

            return;
        }

        $this->fail('0 was the sentinel thirteen writers relied on');
    }

    #[Test]
    public function the_guard_refuses_an_id_that_names_no_subject(): void
    {
        try {
            $this->writeSubject(null, 5);
        } catch (\LogicException $e) {
            $this->assertStringContainsString('names no subject', $e->getMessage());

            return;
        }

        $this->fail('an id without a type is as unresolvable as a type without an id');
    }

    #[Test]
    public function the_guard_refuses_a_subject_with_no_id(): void
    {
        try {
            $this->writeSubject(User::class, null);
        } catch (\LogicException $e) {
            $this->assertStringContainsString('has no id', $e->getMessage());

            return;
        }

        $this->fail('a subject class with no key cannot be resolved back to a row');
    }

    #[Test]
    public function a_null_subject_row_is_accepted_and_shows_no_subject_to_the_admin_table(): void
    {
        $row = $this->writeSubject(null, null, 'payout_statement_generation');

        $this->assertNull($row->auditable_type);
        $this->assertNull($row->auditable_id);
        $this->assertSame('payout_statement_generation', $row->metadata['lane']);

        $shortType = new ReflectionMethod(AuditLogResource::class, 'shortType');
        $shortType->setAccessible(true);

        $this->assertSame('—', $shortType->invoke(null, $row->auditable_type), 'the Subject column must fall back to its placeholder');
        $this->assertSame('User', $shortType->invoke(null, User::class), 'a real subject keeps its readable name');
    }

    #[Test]
    public function a_real_subject_still_resolves_and_is_findable_by_subject(): void
    {
        $user = User::factory()->create();
        $row = $this->writeSubject(User::class, (int) $user->getKey());

        $this->assertTrue(
            AuditLog::query()->forAuditable(User::class, (int) $user->getKey())->whereKey($row->getKey())->exists(),
            'scopeForAuditable is the question the subject columns exist for'
        );
        $this->assertInstanceOf(User::class, $row->auditable);
        $this->assertSame((int) $user->getKey(), (int) $row->auditable->getKey());
    }

    #[Test]
    public function the_alert_lane_row_is_found_by_its_lane_label(): void
    {
        (new SendFinancialAlertJob(
            title: 'Reconciliation overdue',
            message: 'finance:reconcile has not run inside its window.',
            severity: RiskLevel::High,
            metadata: ['draw_id' => 42],
        ))->handle();

        $rows = AuditLog::query()->where('metadata->lane', 'financial_alert')->get();

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->auditable_type);
        $this->assertNull($rows[0]->auditable_id);
        $this->assertSame(AuditAction::SecurityAlert, $rows[0]->action);
        $this->assertSame(42, $rows[0]->metadata['draw_id'], 'a caller-supplied key survives the lane default');
    }

    #[Test]
    public function the_disbursement_dedupe_filters_metadata_because_the_table_has_no_lane_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('audit_logs', 'lane'),
            'the lane name lives in the metadata json; a column of that name has never existed'
        );

        $scribe = app(RecordPrizeDisbursementAudit::class);

        $scribe->from(
            payout: null,
            act: 'failed',
            amount: '0.00',
            fingerprint: 'a'.hash('sha256', 'audit-probe'),
            actorNote: 'settlement court probe',
            disbursementId: null,
        );

        $rows = AuditLog::query()->where('metadata->lane', 'prize-disbursement')->get();

        $this->assertCount(1, $rows, 'the run row is written once and is findable by its lane');
        $this->assertNull($rows[0]->auditable_type);
        $this->assertNull($rows[0]->auditable_id);

        $scribe->from(
            payout: null,
            act: 'failed',
            amount: '0.00',
            fingerprint: 'a'.hash('sha256', 'audit-probe'),
            actorNote: 'settlement court probe',
            disbursementId: null,
        );

        $this->assertSame(
            1,
            AuditLog::query()->where('metadata->lane', 'prize-disbursement')->count(),
            'the dedupe branch has to run to work: before, it raised an unknown-column error instead'
        );
    }

    #[Test]
    public function a_disbursement_without_its_own_id_names_the_real_payout(): void
    {
        $payout = Payout::factory()->create();
        $scribe = app(RecordPrizeDisbursementAudit::class);
        $scribe->from($payout, 'reserved', '12.00', hash('sha256', 'payout-proof'), 'review');
        $scribe->from($payout, 'reserved', '12.00', hash('sha256', 'payout-proof'), 'review');

        $rows = AuditLog::query()->where('metadata->lane', 'prize-disbursement')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(Payout::class, $rows[0]->auditable_type);
        $this->assertSame((int) $payout->getKey(), $rows[0]->auditable_id);
        $this->assertInstanceOf(Payout::class, $rows[0]->auditable);
        $this->assertNotEmpty($rows[0]->metadata[AuditLog::ANCHOR_KEY] ?? null);
    }

    #[Test]
    public function a_label_in_the_subject_column_breaks_the_reader_the_columns_exist_for(): void
    {
        // Written with the query builder, past the model hook, to show what the
        // hook is for: a subject that is not a class is not merely ugly in the
        // admin table, it is fatal as soon as anything resolves the morph.
        DB::table('audit_logs')->insert([
            'action' => 'update',
            'risk_level' => 'low',
            'auditable_type' => 'prize_tax_sweep',
            'auditable_id' => 0,
            'description' => 'a subject that was always a label',
            'metadata' => json_encode(['lane' => 'prize_tax_sweep']),
            'created_at' => now()->toDateTimeString(),
        ]);

        $crashed = null;

        try {
            AuditLog::query()->with('auditable')->get();
        } catch (\Throwable $e) {
            $crashed = $e;
        }

        $this->assertNotNull(
            $crashed,
            'if a label subject were harmless, the guard would be style policing instead of a fix'
        );
        $this->assertStringContainsString('prize_tax_sweep', $crashed->getMessage());
    }

    #[Test]
    public function the_shared_audit_door_takes_a_model_or_nothing(): void
    {
        $param = (new ReflectionMethod(AuditLogService::class, 'log'))->getParameters()[3];
        $type = $param->getType();

        $this->assertSame('auditable', $param->getName());
        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertSame(Model::class, $type->getName(), 'a string here used to be written straight into auditable_type');
        $this->assertTrue($type->allowsNull());

        $user = User::factory()->create();
        $service = app(AuditLogService::class);

        $withSubject = $service->log(
            userId: null,
            action: AuditAction::Update,
            riskLevel: RiskLevel::Low,
            auditable: $user,
            description: 'subject probe',
        );
        $withoutSubject = $service->log(
            userId: null,
            action: AuditAction::Update,
            riskLevel: RiskLevel::Low,
            auditable: null,
            description: 'lane probe',
            metadata: ['lane' => 'probe'],
        );

        $this->assertSame(User::class, $withSubject->auditable_type);
        $this->assertSame((int) $user->getKey(), $withSubject->auditable_id);
        $this->assertNull($withoutSubject->auditable_type);
        $this->assertNull($withoutSubject->auditable_id);
    }

    #[Test]
    public function an_unsaved_model_becomes_a_run_row_instead_of_subject_zero(): void
    {
        $row = app(AuditLogService::class)->log(
            userId: null,
            action: AuditAction::Update,
            riskLevel: RiskLevel::Low,
            auditable: new User(['name' => 'not persisted']),
            description: 'preview row',
            metadata: ['lane' => 'probe'],
        );

        $this->assertNull($row->auditable_type);
        $this->assertNull($row->auditable_id);
    }

    #[Test]
    public function every_row_written_through_the_model_either_resolves_or_names_nothing(): void
    {
        $user = User::factory()->create();

        $this->writeSubject(null, null, 'payout_statement_generation');
        $this->writeSubject(User::class, (int) $user->getKey(), 'probe');

        // Eager, not lazy: this application calls Model::preventLazyLoading(), so
        // with('auditable') is how the relation is actually read in a table, and
        // it is the reader that a subject which is not a class breaks (see the
        // next test).
        $rows = AuditLog::query()->with('auditable')->get();
        $this->assertCount(2, $rows, 'one run row and one row about a record');

        foreach ($rows as $row) {
            if ($row->auditable_type === null) {
                $this->assertNull($row->auditable_id, 'audit row '.$row->getKey().' has an id with no subject');

                continue;
            }

            $this->assertTrue(
                is_subclass_of($row->auditable_type, Model::class),
                'audit row '.$row->getKey().' names '.$row->auditable_type.', which is not a model'
            );
            $this->assertGreaterThan(0, (int) $row->auditable_id);
            $this->assertNullOrModel($row->auditable);
        }
    }

    // -----------------------------------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------------------------------

    private function writeSubject(?string $type, ?int $id, string $lane = 'probe'): AuditLog
    {
        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::Low,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'description' => 'subject probe',
            'metadata' => ['lane' => $lane],
        ]);

        $log->save();

        return $log;
    }

    private function assertNullOrModel(mixed $value): void
    {
        if ($value === null) {
            $this->assertTrue(true);

            return;
        }

        $this->assertInstanceOf(Model::class, $value);
    }
}
