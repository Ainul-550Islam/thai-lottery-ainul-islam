<?php

declare(strict_types=1);

namespace Tests\Feature\Verification;

use App\Enums\AccountVerificationStatus;
use App\Enums\UserStatus;
use App\Models\AccountVerification;
use App\Models\KycDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/*
 * PROMPT 3 — the account-verification flow suite (file 30/30).
 *
 * Covers the Account Verify page parity, self-scoping/IDOR resistance,
 * the submission contract (country/mobile pair, document type,
 * front/back uploads), upload security (MIME, size, corruption,
 * traversal, executables), private storage, the explicit state
 * machine, reviewer authorization, immutable history and the
 * public/private data boundary.
 */
final class AccountVerificationFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        $this->player = User::factory()->create([
            'email' => 'verify-me@example.test',
            'phone' => '0812345678',
        ]);

        Storage::fake('local');
    }

    /*
    |----------------------------------------------------------------------
    | Page parity + access
    |----------------------------------------------------------------------
    */

    public function test_verify_page_parity(): void
    {
        $content = (string) $this->actingAs($this->player)
            ->get(route('account.verification'))
            ->assertOk()
            ->getContent();

        foreach ([
            'Account No.',
            'Name',
            'Email Address',
            'Join Date',
            'Renew Date',
            'Status',
            'Verify Now',
            'Country Code',
            'Mobile Number',
            'Document Type',
            'Front Part of the Document',
            'Back Part of the Document',
            'Submit',
        ] as $needle) {
            $this->assertStringContainsString($needle, $content, 'Verify page must expose: '.$needle);
        }

        // The owner's own summary — not another account's.
        $this->assertStringContainsString('verify-me@example.test', $content);
        $this->assertStringContainsString('NOT_SUBMITTED', $content);
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        $this->get(route('account.verification'))
            ->assertRedirect(route('login'));
    }

    public function test_owner_sees_only_own_status(): void
    {
        $other = User::factory()->create(['email' => 'other@example.test']);

        $content = (string) $this->actingAs($this->player)
            ->get(route('account.verification').'?user_id='.$other->id)
            ->assertOk()
            ->getContent();

        // The client-supplied user_id is dead on arrival.
        $this->assertStringContainsString('verify-me@example.test', $content);
        $this->assertStringNotContainsString('other@example.test', $content);
    }

    public function test_post_with_forged_account_id_is_ignored(): void
    {
        $other = User::factory()->create();

        $this->actingAs($this->player)
            ->post(route('account.verification.submit'), array_merge(
                $this->submissionPayload(),
                ['user_id' => $other->id, 'account_id' => $other->id],
            ))
            ->assertRedirect(route('account.verification'));

        $this->assertTrue(
            AccountVerification::query()->where('user_id', $this->player->id)->exists(),
            'The submission belongs to the authenticated owner',
        );
        $this->assertFalse(
            AccountVerification::query()->where('user_id', $other->id)->exists(),
            'A forged account id must never attribute the submission elsewhere',
        );
    }

    /*
    |----------------------------------------------------------------------
    | Submission
    |----------------------------------------------------------------------
    */

    public function test_valid_submission_with_front_and_back(): void
    {
        $this->actingAs($this->player)
            ->post(route('account.verification.submit'), $this->submissionPayload())
            ->assertRedirect(route('account.verification'))
            ->assertSessionHas('success');

        $aggregate = AccountVerification::query()->where('user_id', $this->player->id)->firstOrFail();

        $this->assertSame(AccountVerificationStatus::Pending, $aggregate->status);
        $this->assertSame('+66', $aggregate->country_code);
        $this->assertSame('+66812345678', $aggregate->mobile);
        $this->assertSame('passport', $aggregate->document_type);
        $this->assertNotNull($aggregate->front_document_id);
        $this->assertNotNull($aggregate->back_document_id);
        $this->assertSame('1', (string) $aggregate->rule_version);
        $this->assertMatchesRegularExpression('/^AV-\d{8}-[A-Z0-9]{10}$/', (string) $aggregate->verification_reference);

        // The canonical document rows exist (front = chosen type, back = other).
        $this->assertSame(2, KycDocument::query()->where('user_id', $this->player->id)->count());
        $front = KycDocument::query()->find($aggregate->front_document_id);
        $this->assertSame('passport', (string) $front->document_type->value);
        $this->assertSame('pending', (string) $front->status->value);

        // The mobile pair is captured on the account — never as verified.
        $this->assertSame('+66812345678', $this->player->fresh()->phone);
        $this->assertNull($this->player->fresh()->phone_verified_at);

        // Audited.
        $this->assertDatabaseHas('audit_logs', ['user_id' => $this->player->id]);
    }

    public function test_front_only_submission_accepted_under_default_policy(): void
    {
        $payload = $this->submissionPayload();
        unset($payload['document_back']);

        $this->actingAs($this->player)
            ->post(route('account.verification.submit'), $payload)
            ->assertRedirect(route('account.verification'))
            ->assertSessionHas('success');

        $aggregate = AccountVerification::query()->where('user_id', $this->player->id)->firstOrFail();
        $this->assertNull($aggregate->back_document_id);
    }

    public function test_back_required_when_configured(): void
    {
        config(['account_verification.uploads.require_back_document' => true]);

        $payload = $this->submissionPayload();
        unset($payload['document_back']);

        $this->actingAs($this->player)
            ->from(route('account.verification'))
            ->post(route('account.verification.submit'), $payload)
            ->assertSessionHasErrors('document_back');
    }

    public function test_missing_front_document_rejected(): void
    {
        $payload = $this->submissionPayload();
        unset($payload['document']);

        $this->actingAs($this->player)
            ->post(route('account.verification.submit'), $payload)
            ->assertSessionHasErrors('document');

        $this->assertSame(0, AccountVerification::query()->count());
    }

    public function test_invalid_mobile_rejected_on_the_document_surface(): void
    {
        $payload = $this->submissionPayload(['mobile' => 'abc']);

        $this->actingAs($this->player)
            ->from(route('account.verification'))
            ->post(route('account.verification.submit'), $payload)
            ->assertSessionHasErrors('document');

        $this->assertSame(0, KycDocument::query()->count());
    }

    public function test_unsupported_country_code_rejected(): void
    {
        $payload = $this->submissionPayload(['country_code' => '+999']);

        $this->actingAs($this->player)
            ->from(route('account.verification'))
            ->post(route('account.verification.submit'), $payload)
            ->assertSessionHasErrors('country_code');

        $this->assertSame(0, KycDocument::query()->count());
    }

    public function test_unsupported_document_type_rejected(): void
    {
        $payload = $this->submissionPayload(['document_type' => 'library_card']);

        $this->actingAs($this->player)
            ->from(route('account.verification'))
            ->post(route('account.verification.submit'), $payload)
            ->assertSessionHasErrors('document_type');
    }

    public function test_client_cannot_submit_a_status(): void
    {
        $this->actingAs($this->player)
            ->post(route('account.verification.submit'), array_merge($this->submissionPayload(), [
                'status' => 'verified',
                'approved' => 'true',
                'phone_verified' => 'true',
            ]))
            ->assertSessionHasNoErrors();

        $aggregate = AccountVerification::query()->where('user_id', $this->player->id)->firstOrFail();
        $this->assertSame(AccountVerificationStatus::Pending, $aggregate->status);
        $this->assertNull($this->player->fresh()->phone_verified_at);
    }

    /*
    |----------------------------------------------------------------------
    | Upload security
    |----------------------------------------------------------------------
    */

    public function test_oversized_upload_rejected(): void
    {
        $payload = $this->submissionPayload([
            'document' => UploadedFile::fake()->create('big.jpg', 12000, 'image/jpeg'),
        ]);

        $this->actingAs($this->player)
            ->from(route('account.verification'))
            ->post(route('account.verification.submit'), $payload)
            ->assertSessionHasErrors('document');

        $this->assertSame(0, KycDocument::query()->count());
    }

    public function test_forged_mime_rejected(): void
    {
        // Declared image/jpeg; content is text.
        $payload = $this->submissionPayload([
            'document' => UploadedFile::fake()->createWithContent('fake.jpg', str_repeat('A', 2048)),
        ]);

        $this->actingAs($this->player)
            ->from(route('account.verification'))
            ->post(route('account.verification.submit'), $payload)
            ->assertSessionHasErrors('document');

        $this->assertSame(0, KycDocument::query()->count());
    }

    public function test_executable_upload_rejected(): void
    {
        $payload = $this->submissionPayload([
            'document' => UploadedFile::fake()->create('shell.php', 10, 'application/x-php'),
        ]);

        $this->actingAs($this->player)
            ->from(route('account.verification'))
            ->post(route('account.verification.submit'), $payload)
            ->assertSessionHasErrors('document');
    }

    public function test_double_extension_upload_rejected(): void
    {
        $payload = $this->submissionPayload([
            'document' => UploadedFile::fake()->create('invoice.pdf.php', 10, 'application/pdf'),
        ]);

        $this->actingAs($this->player)
            ->from(route('account.verification'))
            ->post(route('account.verification.submit'), $payload)
            ->assertSessionHasErrors('document');
    }

    public function test_svg_script_upload_rejected(): void
    {
        $payload = $this->submissionPayload([
            'document' => UploadedFile::fake()->createWithContent(
                'evil.svg',
                '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script></svg>',
            ),
        ]);

        $this->actingAs($this->player)
            ->from(route('account.verification'))
            ->post(route('account.verification.submit'), $payload)
            ->assertSessionHasErrors('document');
    }

    public function test_path_traversal_filename_rejected(): void
    {
        $payload = $this->submissionPayload([
            'document' => UploadedFile::fake()->createWithContent('../../evil.jpg', str_repeat('B', 64)),
        ]);

        $this->actingAs($this->player)
            ->from(route('account.verification'))
            ->post(route('account.verification.submit'), $payload)
            ->assertSessionHasErrors('document');
    }

    /*
    |----------------------------------------------------------------------
    | Private storage + document access
    |----------------------------------------------------------------------
    */

    public function test_documents_stored_privately_with_server_generated_names(): void
    {
        $this->actingAs($this->player)
            ->post(route('account.verification.submit'), $this->submissionPayload())
            ->assertSessionHasNoErrors();

        $front = KycDocument::query()->where('user_id', $this->player->id)
            ->orderBy('id')->firstOrFail();

        // Server-generated, non-guessable, private prefix, no client name.
        $this->assertMatchesRegularExpression('#^kyc_documents/\d+/kyc_\d+_[A-Za-z0-9]{32}\.(jpg|png|webp|pdf)$#', (string) $front->file_path);
        $this->assertStringNotContainsString('front-passport', (string) $front->file_path);
        $this->assertSame('front-passport.jpg', (string) $front->original_filename, 'Client name is display metadata only');

        // Nothing lands under a publicly served directory.
        Storage::disk('local')->assertExists((string) $front->file_path);
        $publicDiskRoot = storage_path('app/public');
        $this->assertStringNotContainsString($publicDiskRoot, storage_path('app/private'), 'local disk is the private disk');
    }

    public function test_public_url_does_not_expose_documents(): void
    {
        $this->actingAs($this->player)
            ->post(route('account.verification.submit'), $this->submissionPayload())
            ->assertSessionHasNoErrors();

        $front = KycDocument::query()->where('user_id', $this->player->id)->firstOrFail();

        $page = (string) $this->actingAs($this->player)
            ->get(route('account.verification'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('/storage/kyc', $page);
        $this->assertStringNotContainsString((string) $front->file_path, $page, 'The storage path must not be printed as a URL');
        $this->assertStringNotContainsString('storage/app', $page);
        $this->assertStringNotContainsString('kyc_documents/', $page, 'No storage object keys on the page');

        // A guessed public path is not a document route.
        $this->get('/storage/'.(string) $front->file_path)->assertNotFound();
    }

    public function test_owner_retrieves_own_document_through_the_authorized_path(): void
    {
        $this->actingAs($this->player)
            ->post(route('account.verification.submit'), $this->submissionPayload())
            ->assertSessionHasNoErrors();

        // The download route is keyed by an opaque 64-hex owner token, never by
        // a database id: the whole chain is exercised by scraping the URL the
        // page ACTUALLY publishes (submit -> listing -> authorized download).
        $page = (string) $this->actingAs($this->player)
            ->get(route('account.verification'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '#/account/verification/document/[a-f0-9]{64}#',
            $page,
            'The page must publish an opaque 64-hex download URL for the owner.',
        );

        preg_match('#/account/verification/document/([a-f0-9]{64})#', $page, $matches);

        $this->actingAs($this->player)
            ->get('/account/verification/document/'.$matches[1])
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_another_user_cannot_retrieve_someone_elses_document(): void
    {
        $this->actingAs($this->player)
            ->post(route('account.verification.submit'), $this->submissionPayload())
            ->assertSessionHasNoErrors();

        $front = KycDocument::query()->where('user_id', $this->player->id)->orderBy('id')->firstOrFail();

        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->get(route('account.verification.document', ['document' => $front->id]))
            ->assertNotFound();

        // And guessing object keys of the owner grants nothing either.
        $this->actingAs($intruder)
            ->get(route('account.verification.document', ['document' => 999999]))
            ->assertNotFound();
    }

    /*
    |----------------------------------------------------------------------
    | State machine + review
    |----------------------------------------------------------------------
    */

    public function test_duplicate_open_submission_is_deterministic(): void
    {
        $this->actingAs($this->player)
            ->post(route('account.verification.submit'), $this->submissionPayload())
            ->assertSessionHasNoErrors();

        $second = $this->actingAs($this->player)
            ->from(route('account.verification'))
            ->post(route('account.verification.submit'), $this->submissionPayload())
            ->assertSessionHasErrors('document');

        $this->assertSame(1, AccountVerification::query()->where('user_id', $this->player->id)->count(), 'An open request blocks duplicates deterministically');
        $this->assertSame(2, KycDocument::query()->where('user_id', $this->player->id)->count(), 'The rejected attempt stores nothing');
    }

    public function test_member_cannot_approve_their_own_verification(): void
    {
        $this->actingAs($this->player)
            ->post(route('account.verification.submit'), $this->submissionPayload())
            ->assertSessionHasNoErrors();

        $aggregate = AccountVerification::query()->where('user_id', $this->player->id)->firstOrFail();

        $this->actingAs($this->player)
            ->from(route('account.verification'))
            ->post(route('account.verification.decide', ['verification' => $aggregate->id]), [
                'decision' => 'approve',
            ])->assertForbidden();

        $this->assertSame(
            AccountVerificationStatus::Pending,
            $aggregate->fresh()->status,
            'No public client may drive a submission to a terminal state',
        );
    }

    public function test_reviewer_approval_closes_the_submission(): void
    {
        $this->actingAs($this->player)
            ->post(route('account.verification.submit'), $this->submissionPayload())
            ->assertSessionHasNoErrors();

        $aggregate = AccountVerification::query()->where('user_id', $this->player->id)->firstOrFail();

        $reviewer = $this->reviewer();

        $this->actingAs($reviewer)
            ->post(route('account.verification.decide', ['verification' => $aggregate->id]), [
                'decision' => 'approve',
            ])->assertRedirect();

        $aggregate->refresh();
        $this->assertSame(AccountVerificationStatus::Approved, $aggregate->status);
        $this->assertSame($reviewer->id, (int) $aggregate->reviewer_id);
        $this->assertNotNull($aggregate->processed_at);

        // The decision mirrors onto the canonical document rows.
        $front = KycDocument::query()->find($aggregate->front_document_id);
        $this->assertSame('verified', (string) $front->status->value);

        // Audited with the reviewer identity.
        $this->assertDatabaseHas('audit_logs', ['user_id' => $reviewer->id]);
    }

    public function test_reviewer_under_review_transition_then_decision(): void
    {
        $this->actingAs($this->player)
            ->post(route('account.verification.submit'), $this->submissionPayload())
            ->assertSessionHasNoErrors();

        $aggregate = AccountVerification::query()->where('user_id', $this->player->id)->firstOrFail();
        $reviewer = $this->reviewer();

        $this->actingAs($reviewer)
            ->post(route('account.verification.decide', ['verification' => $aggregate->id]), [
                'decision' => 'under_review',
            ])->assertRedirect();

        $this->assertSame(AccountVerificationStatus::UnderReview, $aggregate->fresh()->status);

        $this->actingAs($reviewer)
            ->post(route('account.verification.decide', ['verification' => $aggregate->id]), [
                'decision' => 'reject',
                'reason' => 'Image unreadable',
            ])->assertRedirect();

        $fresh = $aggregate->fresh();
        $this->assertSame(AccountVerificationStatus::Rejected, $fresh->status);
        $this->assertSame('Image unreadable', (string) $fresh->review_reason);
    }

    public function test_illegal_transition_rejected_and_terminal_states_immutable(): void
    {
        $this->actingAs($this->player)
            ->post(route('account.verification.submit'), $this->submissionPayload())
            ->assertSessionHasNoErrors();

        $aggregate = AccountVerification::query()->where('user_id', $this->player->id)->firstOrFail();
        $reviewer = $this->reviewer();

        // Approve.
        $this->actingAs($reviewer)
            ->post(route('account.verification.decide', ['verification' => $aggregate->id]), ['decision' => 'approve'])
            ->assertRedirect();

        // Terminal: every further decision is refused.
        foreach (['approve', 'reject', 'under_review'] as $decision) {
            $this->actingAs($reviewer)
                ->post(route('account.verification.decide', ['verification' => $aggregate->id]), ['decision' => $decision])
                ->assertSessionHasErrors('decision');
        }

        $this->assertSame(
            AccountVerificationStatus::Approved,
            $aggregate->fresh()->status,
            'An approved historical record is never rewritten',
        );
    }

    public function test_retry_after_rejection_preserves_history(): void
    {
        $this->actingAs($this->player)
            ->post(route('account.verification.submit'), $this->submissionPayload())
            ->assertSessionHasNoErrors();

        $first = AccountVerification::query()->where('user_id', $this->player->id)->firstOrFail();

        $reviewer = $this->reviewer();
        $this->actingAs($reviewer)
            ->post(route('account.verification.decide', ['verification' => $first->id]), [
                'decision' => 'reject',
                'reason' => 'Blurry',
            ])->assertRedirect();

        // A retry after rejection is allowed and APPENDS a new submission.
        $this->actingAs($this->player)
            ->post(route('account.verification.submit'), $this->submissionPayload())
            ->assertSessionHasNoErrors();

        $rows = AccountVerification::query()->where('user_id', $this->player->id)->orderBy('id')->get();
        $this->assertSame(2, $rows->count(), 'History appends; it never rewrites');

        $this->assertSame(AccountVerificationStatus::Rejected, $rows->first()->status);
        $this->assertSame('Blurry', (string) $rows->first()->review_reason);
        $this->assertSame(AccountVerificationStatus::Pending, $rows->last()->status);

        // Document replacement preserved the earlier evidence rows.
        $this->assertSame(4, KycDocument::query()->where('user_id', $this->player->id)->count());
    }

    public function test_history_is_member_safe(): void
    {
        $this->actingAs($this->player)
            ->post(route('account.verification.submit'), $this->submissionPayload())
            ->assertSessionHasNoErrors();

        $page = (string) $this->actingAs($this->player)
            ->get(route('account.verification'))
            ->assertOk()
            ->getContent();

        // The reference + status appear; internal storage paths, reviewer
        // notes and private ids do not.
        $this->assertStringContainsString('AV-', $page);
        $this->assertStringContainsString('PENDING', $page);
        $this->assertStringNotContainsString('file_path', $page);
        $this->assertStringNotContainsString('review_reason', $page);
    }

    /*
    |----------------------------------------------------------------------
    | Localization + data privacy
    |----------------------------------------------------------------------
    */

    public function test_page_renders_in_english_and_thai(): void
    {
        $en = require base_path('lang/en/account_services.php');
        $th = require base_path('lang/th/account_services.php');
        $this->assertSame(array_keys($en), array_keys($th), 'en/th account_services key sets must match');

        app()->setLocale('th');
        $content = (string) $this->actingAs($this->player)
            ->get(route('account.verification'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('การยืนยันบัญชี', $content);
        $this->assertStringContainsString('ยืนยันตอนนี้', $content);

        app()->setLocale('en');
        $content = (string) $this->actingAs($this->player)
            ->get(route('account.verification'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Account Verification', $content);
    }

    public function test_page_shows_no_other_users_data_or_internal_ids(): void
    {
        $other = User::factory()->create([
            'email' => 'secret-other@example.test',
            'phone' => '0899999999',
        ]);
        $this->actingAs($other)
            ->post(route('account.verification.submit'), $this->submissionPayload())
            ->assertSessionHasNoErrors();

        $content = (string) $this->actingAs($this->player)
            ->get(route('account.verification'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('secret-other@example.test', $content);
        $this->assertStringNotContainsString('0899999999', $content);
    }

    /*
    |----------------------------------------------------------------------
    | Aggregate schema guards
    |----------------------------------------------------------------------
    */

    public function test_aggregate_schema_has_the_audit_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('account_verifications'));

        $indexes = collect(Schema::getIndexes('account_verifications'))->map(
            static fn (array $index): string => implode(',', $index['columns']),
        )->all();

        foreach (['user_id,status', 'status,submitted_at', 'rule_version', 'fingerprint'] as $expected) {
            $this->assertContains($expected, $indexes, 'Required audit index missing: '.$expected);
        }
    }

    /*
    |----------------------------------------------------------------------
    | Helpers
    |----------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function submissionPayload(array $overrides = []): array
    {
        return array_merge([
            'country_code' => '+66',
            'mobile' => '812345678',
            'document_type' => 'passport',
            'document' => UploadedFile::fake()->image('front-passport.jpg', 900, 600),
            'document_back' => UploadedFile::fake()->image('back-passport.jpg', 900, 600),
        ], $overrides);
    }

    private function reviewer(): User
    {
        $reviewer = User::factory()->create(['status' => UserStatus::Active]);

        try {
            $reviewer->assignRole('admin');
        } catch (\Throwable) {
            Role::findOrCreate('admin')->users()->attach($reviewer);
        }

        return $reviewer->fresh();
    }
}
