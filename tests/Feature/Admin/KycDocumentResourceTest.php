<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\KycDocumentType;
use App\Enums\KycDocumentVerificationStatus;
use App\Enums\KycStatus;
use App\Enums\UserStatus;
use App\Filament\Resources\KycDocumentResource;
use App\Filament\Resources\KycDocumentResource\KycDocumentActions;
use App\Filament\Resources\KycDocumentResource\Pages\ListKycDocuments;
use App\Filament\Resources\KycDocumentResource\Pages\ViewKycDocument;
use App\Models\AuditLog;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Compliance\KycDocumentService;
use App\Services\Security\KycVerificationService;
use Database\Seeders\AdminRbacSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * KYC evidence: who may decide, what the screen refuses to print, and the lane that was broken.
 *
 * WHY THIS SUITE EXISTS
 *
 * Two things are being proved here, and only one of them is about a screen.
 *
 * 1. THE SCREEN. An identity document is the most sensitive row in the database. The panel must
 *    decide it (verify with a reference, reject with a reason, pronounce expired) through the
 *    authoritative document service, never print the number, the storage path or the bytes, and
 *    never delete or edit the evidence.
 *
 * 2. THE LANE. `Security\KycVerificationService::reviewDocument()` wrote only the legacy `status`
 *    column, while `pronounce()` — the function that decides whether an account is verified —
 *    reads `verification_status`. A document approved through the old panel page or the account
 *    verification flow therefore never counted as evidence. The fix is proved by
 *    `test_the_legacy_review_lane_now_satisfies_the_authoritative_verdict`.
 */
final class KycDocumentResourceTest extends TestCase
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

    private function document(
        ?User $player = null,
        KycDocumentVerificationStatus $status = KycDocumentVerificationStatus::Uploaded,
        ?string $expiresAt = null,
        string $number = 'TH-1234567890123',
    ): KycDocument {
        $player ??= User::factory()->create(['status' => UserStatus::Active]);

        $document = new KycDocument;
        $document->fill([
            'user_id' => $player->getKey(),
            'document_type' => KycDocumentType::NationalId,
            'document_number' => $number,
            'file_path' => 'kyc/private/'.hash('sha256', $number).'.jpg',
            'original_filename' => 'my-id.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 48_000,
            'issuer_country' => 'TH',
            'document_fingerprint' => hash('sha256', $number.'-'.$player->getKey()),
            'expires_at' => $expiresAt,
        ]);
        $document->verification_status = $status;
        $document->status = $status->legacyStatus() ?? KycStatus::Pending;
        $document->save();

        return $document->fresh();
    }

    // ------------------------------------------------------------- access

    public function test_a_kyc_officer_reads_the_evidence_and_a_player_cannot(): void
    {
        $this->operator('kyc-officer');
        $this->assertTrue(KycDocumentResource::canViewAny());
        $this->get('/admin/kyc-documents')->assertSuccessful();

        $this->operator('player');
        $this->assertFalse(KycDocumentResource::canViewAny());
        $this->get('/admin/kyc-documents')->assertForbidden();
    }

    public function test_the_resource_is_inventory_only(): void
    {
        $document = $this->document();

        $this->operator('kyc-officer');

        $this->assertFalse(KycDocumentResource::canCreate());
        $this->assertFalse(KycDocumentResource::canEdit($document));
        $this->assertFalse(KycDocumentResource::canDelete($document));
        $this->assertFalse(KycDocumentResource::canDeleteAny());

        // Evidence is never typed in, and never edited into something else.
        $this->get('/admin/kyc-documents/create')->assertNotFound();
        $this->get('/admin/kyc-documents/'.$document->getKey().'/edit')->assertNotFound();
    }

    // ---------------------------------------------------------- the screen

    public function test_the_queue_and_record_render_and_the_number_is_masked(): void
    {
        $document = $this->document();

        $this->operator('kyc-officer');

        Livewire::test(ListKycDocuments::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$document]);

        Livewire::test(ViewKycDocument::class, ['record' => $document->getKey()])
            ->assertOk()
            ->assertSee('•'.substr('TH-1234567890123', -4))
            ->assertDontSee('TH-1234567890123')
            ->assertDontSee('kyc/private/')
            ->assertDontSee('my-id.jpg');
    }

    public function test_opening_a_document_writes_an_audit_line_without_the_contents(): void
    {
        $document = $this->document();
        $officer = $this->operator('kyc-officer');

        Livewire::test(ViewKycDocument::class, ['record' => $document->getKey()])->assertOk();

        $audit = AuditLog::query()
            ->where('user_id', $officer->getKey())
            ->where('auditable_type', KycDocument::class)
            ->where('auditable_id', $document->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('TH-1234567890123', (string) $audit->description);
        $this->assertStringNotContainsString('1234567890123', json_encode($audit->metadata ?? []));
    }

    // -------------------------------------------------------- the decisions

    public function test_verifying_a_document_stamps_both_columns_and_the_reference(): void
    {
        $document = $this->document();
        $officer = $this->operator('kyc-officer');

        app(KycDocumentService::class)->verify($document, (int) $officer->getKey(), 'DESK-2026-11-17-0042');

        $fresh = $document->fresh();

        $this->assertSame(KycDocumentVerificationStatus::Verified, $fresh->verification_status);
        $this->assertSame(KycStatus::Verified, $fresh->status, 'the legacy column must stay in correspondence');
        $this->assertSame('DESK-2026-11-17-0042', $fresh->verification_reference);
        $this->assertSame($officer->getKey(), (int) $fresh->verified_by);
    }

    public function test_expired_evidence_cannot_be_verified_by_the_service(): void
    {
        $document = $this->document(expiresAt: now()->subDay()->toDateTimeString());
        $officer = $this->operator('kyc-officer');

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/expired/i');

        app(KycDocumentService::class)->verify($document, (int) $officer->getKey(), 'DESK-2026-11-17-0042');
    }

    public function test_the_verify_verb_is_not_offered_for_lapsed_or_terminal_evidence(): void
    {
        $this->operator('kyc-officer');

        $verify = collect(KycDocumentActions::forTable())
            ->first(fn ($action): bool => $action->getName() === 'verify');

        $this->assertNotNull($verify);

        $uploaded = $this->document();
        $this->assertTrue($verify->record($uploaded)->isVisible());

        $rejected = $this->document(status: KycDocumentVerificationStatus::Rejected);
        $this->assertFalse(
            $verify->record($rejected)->isVisible(),
            'a rejected document is terminal and must not be offered a verify verb',
        );

        $expired = $this->document(status: KycDocumentVerificationStatus::Expired);
        $this->assertFalse($verify->record($expired)->isVisible());
    }

    public function test_a_reviewer_without_the_approve_grant_is_offered_reject_but_not_verify(): void
    {
        // withdrawal-manager holds kyc.view only; kyc-officer holds view + review + approve.
        $document = $this->document();
        $this->operator('withdrawal-manager');

        $names = collect(KycDocumentActions::forTable())
            ->filter(fn ($action): bool => $action->record($document)->isVisible())
            ->map(fn ($action): string => $action->getName())
            ->values()
            ->all();

        $this->assertSame([], $names, 'a view-only operator must be offered no decision verb');
    }

    public function test_a_self_review_is_refused_by_the_screen_and_by_the_service(): void
    {
        // The operator owns the document.
        $officer = $this->operator('kyc-officer');
        $document = $this->document(player: $officer);

        $verify = collect(KycDocumentActions::forTable())
            ->first(fn ($action): bool => $action->getName() === 'verify');

        $this->assertNotNull($verify);
        $this->assertFalse($verify->record($document)->isVisible(), 'the screen must not offer a guaranteed refusal');

        // And the lane refuses it too — maker-checker is the rule, not a screen courtesy.
        try {
            app(KycDocumentService::class)->verify($document, (int) $officer->getKey(), 'SELF-2026-11-17-0001');
            $this->fail('the document lane must refuse a self-review');
        } catch (\App\Exceptions\KycVerificationException $e) {
            $this->assertSame(\App\Exceptions\KycVerificationException::CODE_SELF_REVIEW, $e->errorCode());
        }

        $this->assertSame(KycDocumentVerificationStatus::Uploaded, $document->fresh()->verification_status);
    }

    public function test_a_self_rejection_is_refused_by_the_lane_as_well(): void
    {
        $officer = $this->operator('kyc-officer');
        $document = $this->document(player: $officer);

        $this->expectException(\App\Exceptions\KycVerificationException::class);

        app(KycDocumentService::class)->reject($document, (int) $officer->getKey(), 'I refuse my own document.');
    }

    public function test_rejecting_keeps_the_reason_on_the_row(): void
    {
        $document = $this->document();
        $officer = $this->operator('kyc-officer');

        app(KycDocumentService::class)->reject($document, (int) $officer->getKey(), 'The photo is blurred beyond reading.');

        $fresh = $document->fresh();

        $this->assertSame(KycDocumentVerificationStatus::Rejected, $fresh->verification_status);
        $this->assertSame(KycStatus::Rejected, $fresh->status);
        $this->assertStringContainsString('blurred', (string) $fresh->rejection_reason);
    }

    // ------------------------------------------------------ the lane repair

    public function test_the_legacy_review_lane_now_satisfies_the_authoritative_verdict(): void
    {
        $player = User::factory()->create(['status' => UserStatus::Active]);
        $document = $this->document(player: $player);
        $reviewer = $this->operator('kyc-officer');

        // The older lane — used by KycReviewPage and by AccountVerificationService.
        app(KycVerificationService::class)->reviewDocument($document, $reviewer, true);

        $fresh = $document->fresh();

        $this->assertSame(KycStatus::Verified, $fresh->status);
        $this->assertSame(
            KycDocumentVerificationStatus::Verified,
            $fresh->verification_status,
            'an approval taken on the legacy lane must be visible to pronounce(), which reads verification_status',
        );
    }

    public function test_the_legacy_lane_refuses_to_approve_expired_evidence(): void
    {
        $document = $this->document(expiresAt: now()->subDay()->toDateTimeString());
        $reviewer = $this->operator('kyc-officer');

        $this->expectException(\InvalidArgumentException::class);

        app(KycVerificationService::class)->reviewDocument($document, $reviewer, true);
    }
}
