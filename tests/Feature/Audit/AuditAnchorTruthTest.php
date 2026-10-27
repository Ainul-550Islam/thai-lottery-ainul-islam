<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Enums\SecurityEventType;
use App\Enums\SecurityRiskLevel;
use App\Events\WithdrawalKycApproved;
use App\Exceptions\AdminOperationException;
use App\Listeners\RecordPrizeDisbursementAudit;
use App\Listeners\RecordSecurityEventAudit;
use App\Listeners\RecordWithdrawalKycAudit;
use App\Models\AuditLog;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Operations\AdminAuditQueryService;
use FilesystemIterator;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * One anchor, one name, and every spelling of it readable.
 *
 * WHAT THE ANCHOR IS FOR.
 *
 * An audit row is written from a retryable place: a queued job, a listener the dispatcher
 * may run twice (this app registers each discovered "@handle" listener twice, which
 * BroadcastChannelAuthorizationTest counts in the query log), a webhook redelivered an
 * hour later. So each writer asks first whether a row for this anchor already exists.
 * The anchor is the answer to "which event is this row the record of", and the desk has
 * exactly one way to ask that question back: AdminAuditQueryService::search with an
 * `audit_anchor` field, reached from the admin operations controller.
 *
 * WHAT WAS BROKEN.
 *
 * app/ spelled that key two ways. Sixteen writers called it `audit_anchor`, five called it
 * `anchor`, and every file was internally consistent, so nothing was red: the withdrawal
 * KYC, draw certification, prize disbursement and retail ticket lanes deduped against their
 * own spelling and wrote their rows happily. The reader knew only the other spelling.
 * Measured directly: a search for the anchor of a row the prize lane had just written
 * returned 0 hits, while the same search for a security-event row returned 1. Four lanes of
 * evidence, the money and draw ones among them, were invisible to the one filter whose job
 * is proving a row exists.
 *
 * WHAT THIS FILE PINS.
 *
 * The key is a constant on the model, so a writer cannot invent a third name; readers go
 * through AuditLog::scopeAnchoredBy, and that scope also accepts the old spelling, because
 * rows written before this change stay in an append-only table and stay evidence. So a
 * pre-change row is still findable by the desk and still dedupes its lane, and no backfill
 * is needed (or allowed, by the retention policy).
 */
final class AuditAnchorTruthTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * The fifteen writers that already used the canonical name.
     */
    private const CANONICAL_WRITERS = [
        'app/Listeners/RecordAdminOperationAudit.php',
        'app/Listeners/RecordComplianceActionAudit.php',
        'app/Listeners/RecordFinancialReconciliationAudit.php',
        'app/Listeners/RecordMfaVerificationAudit.php',
        'app/Listeners/RecordNotificationAudit.php',
        'app/Listeners/RecordNotificationReceiptAudit.php',
        'app/Listeners/RecordPaymentReconciliationAudit.php',
        'app/Listeners/RecordPaymentWebhookAudit.php',
        'app/Listeners/RecordPlayerProtectionActionAudit.php',
        'app/Listeners/RecordProviderOperationAudit.php',
        'app/Listeners/RecordSelfExclusionAudit.php',
        'app/Listeners/RecordSecurityEventAudit.php',
        'app/Services/Lottery/ProviderAttemptRecorder.php',
        'app/Services/Operations/AdminOperationService.php',
        'app/Services/ResponsibleGaming/ResponsibleGamingLimitService.php',
    ];

    /**
     * The four lanes that spelled it their own way, the ones the desk could not see.
     */
    private const DIVERGENT_WRITERS = [
        'app/Listeners/RecordDrawCertificationAudit.php',
        'app/Listeners/RecordPrizeDisbursementAudit.php',
        'app/Listeners/RecordRetailTicketAllocationAudit.php',
        'app/Listeners/RecordWithdrawalKycAudit.php',
    ];

    /**
     * Keys that look like an anchor but are not audit metadata: the payload a service hands
     * a listener, the projection an event exposes, and the field name of the admin filter.
     * A fifth file appearing here is a fork in disguise, so it is named rather than counted.
     */
    private const NOT_AUDIT_METADATA = [
        'app/Events/WithdrawalKycApproved.php',
        'app/Services/Operations/AdminAuditQueryService.php',
        'app/Services/Ticket/TicketQrVerificationService.php',
        'app/Services/Withdrawal/WithdrawalKycGateService.php',
    ];

    /** @return \Generator<string> */
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

    private function rel(string $path): string
    {
        return substr($path, strlen(base_path()) + 1);
    }

    private function withdrawal(): Withdrawal
    {
        return Withdrawal::factory()->create();
    }

    private function recordKycPass(string $anchor, ?Withdrawal $withdrawal = null): Withdrawal
    {
        $withdrawal ??= $this->withdrawal();

        app(RecordWithdrawalKycAudit::class)->handle(
            new WithdrawalKycApproved(
                $withdrawal,
                (int) $withdrawal->user_id,
                1,
                $anchor,
                false,
            )
        );

        return $withdrawal;
    }

    /** @param array<string, mixed> $metadata */
    private function stamp(array $metadata, ?string $subject = null, ?int $subjectId = null): AuditLog
    {
        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::Low,
            'auditable_type' => $subject,
            'auditable_id' => $subjectId,
            'description' => 'anchor probe',
            'metadata' => $metadata,
        ]);

        $log->save();

        return $log;
    }

    // --------------------------------------------------------------------------------------
    // one name
    // --------------------------------------------------------------------------------------

    #[Test]
    public function every_audit_writer_stamps_the_key_from_the_model(): void
    {
        $this->assertCount(15, self::CANONICAL_WRITERS, 'fifteen writers already had the right name');
        $this->assertCount(4, self::DIVERGENT_WRITERS, 'four writers had one of their own');

        foreach ([...self::CANONICAL_WRITERS, ...self::DIVERGENT_WRITERS] as $rel) {
            $src = (string) file_get_contents(base_path($rel));

            $this->assertSame(
                0,
                substr_count($src, "'audit_anchor' =>"),
                $rel.' spells the metadata key by hand instead of naming it once'
            );
            $this->assertStringNotContainsString(
                "'anchor' =>",
                $src,
                $rel.' still writes the anchor under a private name'
            );
            $this->assertStringContainsString(
                'AuditLog::ANCHOR_KEY =>',
                $src,
                $rel.' stamps no anchor, so a retry of its event mints a second row'
            );
        }
    }

    #[Test]
    public function no_reader_spells_an_anchor_json_path_by_hand(): void
    {
        $offenders = [];

        foreach ($this->appFiles() as $path) {
            $rel = $this->rel($path);

            if ($rel === 'app/Models/AuditLog.php') {
                continue;
            }

            $src = (string) file_get_contents($path);

            foreach ([
                'metadata->audit_anchor',
                'metadata->anchor',
                "json_extract(metadata, '\$.anchor')",
                "json_extract(metadata, '\$.audit_anchor')",
            ] as $needle) {
                if (str_contains($src, $needle)) {
                    $offenders[] = $rel.': '.$needle;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "the anchor's json address belongs to the model that owns the column, or a reader drifts "
            ."from a writer in silence:\n  - ".implode("\n  - ", $offenders)
        );
    }

    #[Test]
    public function the_two_spellings_are_named_once_and_the_old_one_is_never_written(): void
    {
        $this->assertSame('audit_anchor', AuditLog::ANCHOR_KEY);
        $this->assertSame('anchor', AuditLog::LEGACY_ANCHOR_KEY);

        $written = [];

        foreach ($this->appFiles() as $path) {
            if ($this->rel($path) === 'app/Models/AuditLog.php') {
                continue;
            }

            if (str_contains((string) file_get_contents($path), 'AuditLog::LEGACY_ANCHOR_KEY =>')) {
                $written[] = $this->rel($path);
            }
        }

        $this->assertSame(
            [],
            $written,
            'the legacy spelling exists to read history; writing it starts the fork again'
        );
    }

    #[Test]
    public function every_anchor_key_literal_outside_the_model_is_an_agreed_non_audit_key(): void
    {
        $hits = [];

        foreach ($this->appFiles() as $path) {
            $rel = $this->rel($path);

            if ($rel === 'app/Models/AuditLog.php') {
                continue;
            }

            if (preg_match("/'(audit_anchor|anchor)' *=>/", (string) file_get_contents($path), $m)) {
                $hits[] = $rel;
            }
        }

        sort($hits);

        $this->assertSame(
            self::NOT_AUDIT_METADATA,
            array_values(array_unique($hits)),
            'a fifth file spelling an `anchor` key like this is a second source of truth, not a rename'
        );
    }

    // --------------------------------------------------------------------------------------
    // the desk can see the lanes it could not see
    // --------------------------------------------------------------------------------------

    #[Test]
    public function the_desk_finds_a_kyc_pass_row_by_its_anchor(): void
    {
        $anchor = 'kyc-gate:'.hash('sha256', 'anchor-probe-kyc');

        $this->recordKycPass($anchor);

        $rows = AuditLog::query()->where('auditable_type', Withdrawal::class)->get();

        $this->assertCount(1, $rows, 'one row per gate pass');
        $this->assertSame(
            $anchor,
            $rows[0]->metadata[AuditLog::ANCHOR_KEY] ?? null,
            'the row carries its anchor under the shared name, which is what makes it findable'
        );

        $this->assertSame(
            1,
            app(AdminAuditQueryService::class)->search(['audit_anchor' => $anchor], 1, 10)->total(),
            'an operator searching the KYC lane by anchor used to be told the row did not exist'
        );
    }

    #[Test]
    public function the_desk_finds_a_prize_disbursement_row_by_its_anchor(): void
    {
        app(RecordPrizeDisbursementAudit::class)->from(
            payout: null,
            act: 'failed',
            amount: '0.00',
            fingerprint: 'a'.hash('sha256', 'anchor-probe-prize'),
            actorNote: 'settlement court probe',
            disbursementId: null,
        );

        $rows = AuditLog::query()->where('metadata->lane', 'prize-disbursement')->get();

        $this->assertCount(1, $rows, 'the run row is written once');
        $this->assertSame(
            1,
            app(AdminAuditQueryService::class)
                ->search(['audit_anchor' => (string) $rows[0]->metadata[AuditLog::ANCHOR_KEY]], 1, 10)
                ->total(),
            'the money lane is searchable by the anchor its own guard dedupes on'
        );
    }

    #[Test]
    public function a_lane_that_already_had_the_right_name_is_still_found_and_still_dedupes(): void
    {
        $user = User::factory()->create();

        $event = SecurityEvent::query()->create([
            'user_id' => $user->getKey(),
            'event_fingerprint' => 'anchor-probe-'.Str::random(8),
            'event_type' => SecurityEventType::LoginFailed,
            'risk_level' => SecurityRiskLevel::High,
            'occurred_at' => now(),
        ]);

        $scribe = app(RecordSecurityEventAudit::class);
        $scribe->from($event, 'duplicate login from three cities');
        $scribe->from($event, 'duplicate login from three cities');

        $rows = AuditLog::query()->where('auditable_type', SecurityEvent::class)->get();

        $this->assertCount(1, $rows, 'the anchor guard still collapses a replay to one row');

        $this->assertSame(
            1,
            app(AdminAuditQueryService::class)
                ->search(['audit_anchor' => (string) $rows[0]->metadata[AuditLog::ANCHOR_KEY]], 1, 10)
                ->total(),
            'a regression pin: the lane that was findable stays findable'
        );
    }

    // --------------------------------------------------------------------------------------
    // history stays readable
    // --------------------------------------------------------------------------------------

    #[Test]
    public function a_row_written_under_the_old_spelling_is_still_findable(): void
    {
        $anchor = 'draw-cert:'.hash('sha256', 'legacy-anchor-row');

        DB::table('audit_logs')->insert([
            'action' => AuditAction::Update->value,
            'risk_level' => RiskLevel::Low->value,
            'auditable_type' => null,
            'auditable_id' => null,
            'description' => 'written before the rename',
            'metadata' => json_encode([
                'anchor' => $anchor,
                'lane' => 'draw-certification',
            ]),
            'created_at' => now()->toDateTimeString(),
        ]);

        $this->assertSame(
            1,
            app(AdminAuditQueryService::class)->search(['audit_anchor' => $anchor], 1, 10)->total(),
            'the pre-cluster row is evidence too: a rename that hides it rewrites history'
        );
    }

    #[Test]
    public function a_row_written_under_the_old_spelling_still_dedupes_its_lane(): void
    {
        $anchor = 'kyc-gate:'.hash('sha256', 'legacy-anchor-dedupe');
        $withdrawal = $this->withdrawal();

        DB::table('audit_logs')->insert([
            'action' => AuditAction::Withdraw->value,
            'risk_level' => RiskLevel::Medium->value,
            'auditable_type' => Withdrawal::class,
            'auditable_id' => $withdrawal->getKey(),
            'description' => 'written before the rename',
            'metadata' => json_encode([
                'anchor' => $anchor,
                'action' => RecordWithdrawalKycAudit::ACTION_MARKER,
            ]),
            'created_at' => now()->toDateTimeString(),
        ]);

        $this->recordKycPass($anchor, $withdrawal);

        $this->assertSame(
            1,
            AuditLog::query()->where('auditable_type', Withdrawal::class)->count(),
            'a redelivery after the rename must land on the row that already exists'
        );
    }

    // --------------------------------------------------------------------------------------
    // the shape of the filter itself
    // --------------------------------------------------------------------------------------

    #[Test]
    public function the_scope_matches_an_anchor_and_nothing_else(): void
    {
        $this->stamp([AuditLog::ANCHOR_KEY => 'probe-1', 'lane' => 'probe']);
        $this->stamp(['anchor' => 'probe-2', 'lane' => 'probe']);

        $sql = AuditLog::query()->anchoredBy('probe-1')->toSql();

        // Text of the compiled filter on the driver the suite runs on: both json paths have
        // to be there, quoted as path segments, joined by an OR.
        $this->assertStringContainsString(
            '"'.AuditLog::ANCHOR_KEY.'"',
            $sql,
            'the shared name is in the filter on this driver'
        );
        $this->assertStringContainsString(
            '"'.AuditLog::LEGACY_ANCHOR_KEY.'"',
            $sql,
            'the old name is in the filter too, and not as a substring of the new one'
        );
        $this->assertMatchesRegularExpression('/\bor\b/i', $sql, 'either spelling satisfies the filter');

        $this->assertSame(1, AuditLog::query()->anchoredBy('probe-1')->count());
        $this->assertSame(1, AuditLog::query()->anchoredBy('probe-2')->count());
        $this->assertSame(0, AuditLog::query()->anchoredBy('probe-3')->count(), 'a different anchor is not a hit');

        $this->assertSame(
            2,
            AuditLog::query()->where('metadata->lane', 'probe')->count(),
            'two distinct rows, so the counts above are not one row matched twice'
        );
    }

    #[Test]
    public function the_desk_still_refuses_a_filter_name_it_does_not_know(): void
    {
        $this->expectException(AdminOperationException::class);

        app(AdminAuditQueryService::class)->search(['anchor' => 'anything'], 1, 10);
    }
}
