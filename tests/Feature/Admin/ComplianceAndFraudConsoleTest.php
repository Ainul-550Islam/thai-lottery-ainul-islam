<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserStatus;
use App\Filament\Resources\ComplianceCaseResource;
use App\Filament\Resources\ComplianceCaseResource\ComplianceCaseActions;
use App\Filament\Resources\ComplianceCaseResource\Pages\ListComplianceCases;
use App\Filament\Resources\ComplianceCaseResource\Pages\ViewComplianceCase;
use App\Filament\Resources\FraudAlertResource;
use App\Filament\Resources\FraudAlertResource\Pages\ListFraudAlerts;
use App\Filament\Resources\FraudAlertResource\Pages\ViewFraudAlert;
use App\Models\ComplianceCase;
use App\Models\FraudAlert;
use App\Models\User;
use App\Services\Compliance\ComplianceCaseService;
use Database\Seeders\AdminRbacSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The AML / fraud console: who may see it, who may move it, and what it refuses.
 *
 * WHY THIS SUITE EXISTS
 *
 * Two read-only queues were added over tables that already had working domain services. The
 * risk with a screen like this is never "does it render" — it is whether the screen has become
 * a second implementation of the rules, one that skips the transition map, the maker-checker
 * check or the audit line. Every test below therefore asserts against the SERVICE's answer, not
 * against the screen's intentions:
 *
 *   1. The permission split is real. An AML officer holding `aml.view` only may read, and is
 *      refused the move verbs; a reviewer holding `aml.review` gets them.
 *   2. An illegal transition is not offered. A closed file has no verbs at all, because
 *      ComplianceCaseStatus::canTransitionTo() says Closed has no successors.
 *   3. Adjudicating your own fraud alert is refused by the service and never hidden behind a
 *      generic "saved" toast — the refusal text is what the operator sees.
 *   4. A terminal alert cannot be re-decided. The second reviewer's click must not overwrite
 *      the first decision.
 *
 * These tables have no factories (the rows are written by the assessment and detection lanes),
 * so the fixtures are built by hand — deliberately, because that is also the shape a real
 * production row has.
 */
final class ComplianceAndFraudConsoleTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    private const ALLOWED_DATABASES = ['thai_lottery_test', ':memory:'];

    protected function setUp(): void
    {
        parent::setUp();

        $database = \DB::connection()->getDatabaseName();

        $this->assertContains(
            basename((string) $database),
            self::ALLOWED_DATABASES,
            'Refusing to run destructive tests against database: '.$database,
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(AdminRbacSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function operator(string $role): User
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $user->assignRole($role);

        $this->actingAs($user->fresh());

        return $user->fresh();
    }

    private function case(
        string $status = 'open',
        string $risk = 'high',
        ?User $subject = null,
    ): ComplianceCase {
        $subject ??= User::factory()->create(['status' => UserStatus::Active]);

        $case = new ComplianceCase;
        $case->fill([
            'case_key' => 'CMP-'.Str::upper(Str::random(10)),
            'subject_user_id' => $subject->getKey(),
            'case_type' => 'structuring',
            'risk_level' => $risk,
            'trigger_reference' => 'AML-TRIGGER-'.Str::upper(Str::random(6)),
            'evidence_fingerprint' => hash('sha256', Str::random(16)),
            'assigned_desk' => 'compliance',
        ]);
        $case->status = $status;
        $case->save();

        return $case->fresh();
    }

    private function alert(
        string $status = 'OPEN',
        int $riskScore = 85,
        ?string $subjectUuid = null,
        ?int $playerUserId = null,
    ): FraudAlert {
        $alert = new FraudAlert;
        $alert->fill([
            'idempotency_event_key' => 'evt-'.Str::uuid()->toString(),
            'event_type' => 'WITHDRAWAL',
            'user_id' => $subjectUuid ?? Str::uuid()->toString(),
            'player_user_id' => $playerUserId,
            'amount' => '25000.0000',
            'currency' => 'THB',
            'risk_score' => $riskScore,
            'decision' => 'hold_for_review',
            'reason_codes' => ['velocity', 'new_device'],
            'status' => $status,
        ]);
        $alert->save();

        return $alert->fresh();
    }

    // ---------------------------------------------------------------- fraud queue

    public function test_the_fraud_queue_is_open_to_the_risk_roles_and_closed_to_a_player(): void
    {
        $this->operator('fraud-analyst');
        $this->assertTrue(FraudAlertResource::canViewAny());

        $this->operator('aml-officer');
        $this->assertTrue(FraudAlertResource::canViewAny(), 'an AML officer may read the fraud queue');

        $this->operator('player');
        $this->assertFalse(FraudAlertResource::canViewAny());
    }

    public function test_an_operator_reads_the_queue_and_a_player_is_refused_the_route(): void
    {
        $this->alert();

        $this->operator('fraud-analyst');
        $this->get('/admin/fraud-alerts')->assertSuccessful()->assertSee('WITHDRAWAL');

        $this->operator('player');
        $this->get('/admin/fraud-alerts')->assertForbidden();
    }

    public function test_adjudicating_an_alert_writes_the_service_decision_and_an_audit_line(): void
    {
        $alert = $this->alert();
        $reviewer = $this->operator('fraud-analyst');

        FraudAlertResource::review($alert, 'REJECT_DENY', 'Velocity and a new device on the same withdrawal.');

        $fresh = $alert->fresh();

        $this->assertSame('RESOLVED_DENY', $fresh->status);
        $this->assertSame($reviewer->getKey(), (int) $fresh->resolved_by_user_id);
        $this->assertNotNull($fresh->resolved_at);
        // The audit line is written by AuditLogService. Its `action` column is cast to
        // App\Enums\AuditAction, which has no 'fraud_alert_reviewed' case, so the stored
        // action is the enum's own 'update'; the description is what carries the meaning,
        // and the reviewer is recorded by id. Asserted as it behaves, not as it was hoped.
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $reviewer->getKey(),
            'description' => 'Fraud alert adjudicated by a separate reviewer.',
        ]);

        // The adjudication is evidenced as a fraud case, not only as a status change.
        $this->assertDatabaseHas('fraud_cases', ['fraud_alert_id' => $alert->id]);
    }

    public function test_a_terminal_alert_offers_no_adjudication_verb(): void
    {
        $resolved = $this->alert('RESOLVED_ALLOW');

        $this->operator('fraud-analyst');

        $this->assertTrue(FraudAlertResource::isTerminal($resolved));
        $this->assertFalse(FraudAlertResource::mayAdjudicate($resolved));

        $this->actingAs($this->operator('fraud-analyst'));
        Livewire::test(ViewFraudAlert::class, ['record' => $resolved->getKey()])
            ->assertOk();
    }

    public function test_the_service_refuses_an_operator_adjudicating_their_own_alert(): void
    {
        $operator = $this->operator('fraud-analyst');
        $alert = $this->alert('OPEN', 90, (string) $operator->getKey());

        // The service is the rule; the screen's hidden verb is only a courtesy.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Maker-checker separation of duties');

        app(\App\Services\Security\FraudAlertReviewService::class)
            ->review($alert->id, (string) $operator->getKey(), 'RELEASE_ALLOW', 'Nothing wrong with it.');
    }

    public function test_the_screen_refuses_self_adjudication_without_a_fatal(): void
    {
        $operator = $this->operator('fraud-analyst');
        $alert = $this->alert('OPEN', 90, (string) $operator->getKey());

        $this->assertFalse(FraudAlertResource::mayAdjudicate($alert));

        // Even if the call is made anyway, the refusal surfaces as a notification rather
        // than a 500 — and the row stays untouched.
        FraudAlertResource::review($alert, 'RELEASE_ALLOW', 'Nothing wrong with it.');

        $this->assertSame('OPEN', $alert->fresh()->status);
    }

    public function test_the_fraud_decision_is_recorded_under_its_own_audit_action(): void
    {
        // The lane used to pass the bare string 'fraud_alert_reviewed' to AuditLogService, whose
        // column is cast to App\Enums\AuditAction. An unknown string collapsed to `update`, so a
        // fraud adjudication was indistinguishable from an ordinary row edit in the audit screen —
        // including in its filters. This asserts the stored action, not the intention.
        $subject = User::factory()->create(['status' => UserStatus::Active]);
        $reviewer = $this->operator('fraud-analyst');
        $alert = $this->alert('OPEN', 90, (string) $subject->getKey());

        app(\App\Services\Security\FraudAlertReviewService::class)
            ->review($alert->id, (string) $reviewer->getKey(), 'RELEASE_ALLOW', 'Evidence checked, release.');

        $audit = \App\Models\AuditLog::query()->where('user_id', $reviewer->getKey())->latest('id')->firstOrFail();

        $this->assertSame('fraud_alert_reviewed', $audit->action->value);
        $this->assertSame('Fraud Alert Reviewed', $audit->action->label());
        $this->assertSame('security', $audit->action->category());
        $this->assertSame('high', (string) $audit->risk_level->value);
        $this->assertSame('RESOLVED_ALLOW', $alert->fresh()->status);
    }

    public function test_the_legacy_compliance_cases_route_hands_over_to_the_real_console(): void
    {
        // /admin/compliance-cases used to render a "not configured" projection, which stopped
        // being true once this console existed. It now redirects; the route stays so a bookmark
        // does not 404, and the placeholder is not shown for something that is built.
        // The legacy console authorises on the coarse catalogue ('view risk alerts' / 'manage
        // users'), so the operator used here is the admin role — the one that still carries those
        // phrases. An aml-officer holds only the granular grants and is refused by that console,
        // which is exactly why the route should not be the place operators are sent.
        $this->operator('admin');

        $response = $this->get('/admin/compliance-cases');

        $response->assertRedirect('/admin/aml-cases');
        $this->assertSame(url('/admin/aml-cases'), $response->headers->get('Location'));

        // …and the destination really is the case queue, not another placeholder.
        $this->get('/admin/aml-cases')->assertSuccessful();
    }

    // ------------------------------------------------- the resolved account link

    public function test_a_resolved_subject_gets_an_account_link_and_an_unresolved_one_does_not(): void
    {
        // The audit recorded that `fraud_alerts.user_id` is a uuid while `users.id` is a bigint,
        // so the queue could only print an identifier. The recorder now also resolves a pointer
        // when the subject really is a numeric account id, and the screen uses it ONLY then.
        $player = User::factory()->create([
            'status' => UserStatus::Active,
            'username' => 'suspicious-player',
        ]);

        $linked = $this->alert(playerUserId: (int) $player->getKey());
        $unlinked = $this->alert();

        $this->operator('fraud-analyst');

        $this->assertSame('suspicious-player', FraudAlertResource::subjectLabel($linked));
        $this->assertNotNull(FraudAlertResource::subjectUrl($linked));
        $this->assertStringContainsString('/admin/users/', (string) FraudAlertResource::subjectUrl($linked));

        // No link is invented for a subject reference that is not an account id.
        $this->assertSame((string) $unlinked->user_id, FraudAlertResource::subjectLabel($unlinked));
        $this->assertNull(FraudAlertResource::subjectUrl($unlinked));

        Livewire::test(ViewFraudAlert::class, ['record' => $linked->getKey()])
            ->assertOk()
            ->assertSee('suspicious-player');
    }

    public function test_the_recorder_resolves_a_pointer_only_for_a_real_numeric_account(): void
    {
        $player = User::factory()->create(['status' => UserStatus::Active]);
        $recorder = app(\App\Services\Fraud\FraudEventRecorder::class);
        $decision = new \App\DTOs\Security\FraudDecisionData(
            riskScore: 90,
            decision: \App\Enums\FraudDecision::HOLD_FOR_REVIEW,
            reasonCodes: ['velocity'],
            triggeredRules: ['velocity_rule'],
        );

        $linked = $recorder->recordAlert('evt-link-1', 'WITHDRAWAL', (string) $player->getKey(), '100.00', 'THB', $decision);
        $this->assertSame((int) $player->getKey(), $linked->fresh()->player_user_id);
        $this->assertInstanceOf(User::class, $linked->fresh()->player);

        // A uuid, an empty string and a non-existent numeric id all stay unlinked instead of
        // being coerced to a wrong account.
        foreach ([Str::uuid()->toString(), '', '99999999'] as $index => $subject) {
            $alert = $recorder->recordAlert('evt-link-'.($index + 2), 'WITHDRAWAL', $subject, '100.00', 'THB', $decision);

            $this->assertNull($alert->fresh()->player_user_id, 'subject ['.$subject.'] must not be coerced into a link');
        }
    }

    public function test_the_queue_badge_counts_only_open_work(): void
    {
        $this->alert('OPEN');
        $this->alert('RESOLVED_DENY');

        $this->operator('fraud-analyst');

        $this->assertSame('1', FraudAlertResource::getNavigationBadge());
    }

    public function test_the_queue_renders_its_tabs_without_a_second_write_path(): void
    {
        $this->alert('OPEN');

        $this->operator('fraud-analyst');

        Livewire::test(ListFraudAlerts::class)
            ->assertOk()
            ->assertCanSeeTableRecords(FraudAlert::all())
            ->assertTableActionDoesNotExist('create')
            ->assertTableBulkActionDoesNotExist('delete');
    }

    // ---------------------------------------------------------- compliance cases

    public function test_an_aml_officer_reads_the_case_queue_but_a_player_cannot(): void
    {
        $case = $this->case();

        $this->operator('aml-officer');
        $this->get('/admin/aml-cases')->assertSuccessful();
        Livewire::test(ListComplianceCases::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$case]);

        $this->operator('player');
        $this->get('/admin/aml-cases')->assertForbidden();
    }

    public function test_a_reader_without_aml_review_is_offered_no_lifecycle_verb(): void
    {
        // fraud-analyst holds aml.view (it may read the file) but not aml.review.
        $case = $this->case('open');
        $this->operator('fraud-analyst');

        $this->assertTrue(ComplianceCaseResource::canViewAny());

        foreach (ComplianceCaseActions::forTable() as $action) {
            $this->assertFalse(
                $action->record($case)->isVisible(),
                'A reader without aml.review must not be offered: '.$action->getName(),
            );
        }
    }

    public function test_the_case_screen_has_no_create_or_delete_surface(): void
    {
        $this->operator('aml-officer');

        $this->assertFalse(ComplianceCaseResource::canCreate());
        $this->assertFalse(ComplianceCaseResource::canDeleteAny());
    }

    public function test_starting_an_investigation_goes_through_the_service_and_stamps_the_time(): void
    {
        $case = $this->case('open');
        $this->operator('aml-officer');

        app(ComplianceCaseService::class)->investigate($case);

        $fresh = $case->fresh();
        $this->assertSame('investigating', $fresh->status->value);
        $this->assertNotNull($fresh->investigating_since);
    }

    public function test_the_investigate_verb_is_offered_and_runs_the_service_from_the_screen(): void
    {
        $case = $this->case('open');
        $this->operator('aml-officer');

        Livewire::test(ViewComplianceCase::class, ['record' => $case->getKey()])
            ->assertActionVisible('investigate')
            ->callAction('investigate')
            ->assertHasNoActionErrors();

        $this->assertSame('investigating', $case->fresh()->status->value);
    }

    public function test_a_closed_file_is_offered_no_verbs_because_the_enum_says_it_has_none(): void
    {
        $case = $this->case('closed');
        $this->operator('super-admin');

        foreach (ComplianceCaseActions::forTable() as $action) {
            $this->assertFalse(
                $action->record($case)->isVisible(),
                'A closed file must expose no lifecycle verb, found: '.$action->getName(),
            );
        }
    }

    public function test_an_illegal_transition_is_refused_by_the_service_and_surfaced(): void
    {
        $case = $this->case('escalated');
        $this->operator('aml-officer');

        // Escalated may only reach Resolved; Investigate is refused by the transition map.
        $this->assertFalse(
            $case->status->canTransitionTo(\App\Enums\ComplianceCaseStatus::Investigating),
        );

        $verb = collect(ComplianceCaseActions::forTable())
            ->first(fn ($action): bool => $action->getName() === 'investigate');

        $this->assertNotNull($verb);
        $this->assertFalse($verb->record($case)->isVisible());
    }

    public function test_closing_requires_admin_authority_and_the_service_refuses_otherwise(): void
    {
        $case = $this->case('resolved');
        $officer = $this->operator('aml-officer');

        $close = collect(ComplianceCaseActions::forTable())
            ->first(fn ($action): bool => $action->getName() === 'close');

        $this->assertNotNull($close);
        $this->assertFalse(
            $close->record($case)->isVisible(),
            'An AML officer must not be offered the closure verb.',
        );

        $this->expectException(\Throwable::class);
        app(ComplianceCaseService::class)->close($case, (int) $officer->getKey());
    }

    public function test_a_one_word_resolution_is_refused_by_the_service(): void
    {
        $case = $this->case('investigating');
        $this->operator('aml-officer');

        $this->expectException(\Throwable::class);
        app(ComplianceCaseService::class)->resolve($case, 'ok', (int) $this->operator('aml-officer')->getKey());
    }

    public function test_a_full_sentence_resolution_is_accepted_and_recorded(): void
    {
        $case = $this->case('investigating');
        $officer = $this->operator('aml-officer');

        app(ComplianceCaseService::class)->resolve(
            $case,
            'Source of funds verified against two bank statements; no further action required.',
            (int) $officer->getKey(),
        );

        $fresh = $case->fresh();
        $this->assertSame('resolved', $fresh->status->value);
        $this->assertNotNull($fresh->resolution_note);
        $this->assertNotNull($fresh->resolved_at);
    }

    public function test_the_case_record_page_renders_for_a_reviewer(): void
    {
        $case = $this->case('investigating', 'critical');
        $this->operator('aml-officer');

        Livewire::test(ViewComplianceCase::class, ['record' => $case->getKey()])
            ->assertOk()
            ->assertSee($case->case_key)
            ->assertSee('Critical');
    }

    public function test_the_case_badge_counts_live_files_only(): void
    {
        $this->case('open');
        $this->case('resolved');

        $this->operator('aml-officer');

        $this->assertSame('1', ComplianceCaseResource::getNavigationBadge());
    }

    // ------------------------------------------------------------- risk store

    public function test_the_risk_assessment_store_is_read_only(): void
    {
        $this->operator('aml-officer');

        $resource = \App\Filament\Resources\AmlRiskAssessmentResource::class;

        $this->assertTrue($resource::canViewAny());
        $this->assertFalse($resource::canCreate());
        $this->assertFalse($resource::canDeleteAny());

        $this->get('/admin/aml-risk-assessments')->assertSuccessful();
    }

    public function test_the_risk_store_is_closed_to_a_player(): void
    {
        $this->operator('player');

        $this->assertFalse(\App\Filament\Resources\AmlRiskAssessmentResource::canViewAny());
        $this->get('/admin/aml-risk-assessments')->assertForbidden();
    }
}
