<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Enums\PrivacyRequestStatus;
use App\Enums\PrivacyRequestType;
use App\Exceptions\Privacy\PrivacyRequestException;
use App\Mail\PrivacyRequestReceivedMail;
use App\Mail\PrivacyRequestUpdateMail;
use App\Models\DataSubjectRequest;
use App\Models\DataSubjectRequestEvent;
use App\Models\PrivacyVerificationToken;
use App\Models\User;
use App\Services\Privacy\PrivacyRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The state machine and the verification link.
 *
 * WHAT THESE TESTS ARE REALLY ABOUT
 * A privacy request is a legal promise with a clock, so the two things that must
 * never be true are (a) a request whose state was changed without a record of
 * who changed it and why, and (b) a state the workflow never allowed. Both are
 * asserted here directly against the service, because the panel is only one
 * caller and the guarantee has to live below it.
 */
final class PrivacyRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private PrivacyRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('privacy_requests.enabled', true);
        config()->set('privacy_requests.compliance_email', 'compliance@example.test');

        Mail::fake();

        $this->service = app(PrivacyRequestService::class);
    }

    private function pendingRequest(string $email = 'requester@example.test'): array
    {
        $outcome = $this->service->submitPublic(
            email: $email,
            type: PrivacyRequestType::Access,
            locale: 'en',
            details: 'A copy of my data, please.',
            ip: '203.0.113.20',
        );

        /** @var DataSubjectRequest $request */
        $request = DataSubjectRequest::query()->where('reference', $outcome['reference'])->firstOrFail();
        $token = PrivacyVerificationToken::issue((int) $request->getKey());

        return [$request, $token['plain']];
    }

    // =====================================================================
    // Verification
    // =====================================================================

    public function test_01_a_fresh_token_moves_the_request_to_received_and_starts_the_clock(): void
    {
        [$request, $plain] = $this->pendingRequest();

        $this->assertSame('pending_verification', $request->status->value);
        $this->assertNull($request->due_at);

        $outcome = $this->service->verify($plain);

        $this->assertSame(PrivacyRequestService::VERIFIED, $outcome['state']);
        $this->assertSame($request->reference, $outcome['reference']);

        $request->refresh();

        $this->assertSame('received', $request->status->value);
        $this->assertNotNull($request->verified_at);
        $this->assertNotNull($request->received_at);
        $this->assertSame(
            $request->received_at?->copy()->addDays(30)->toDateString(),
            $request->due_at?->toDateString(),
        );

        Mail::assertQueued(PrivacyRequestReceivedMail::class);

        $this->assertDatabaseHas('data_subject_request_events', [
            'request_id' => $request->getKey(),
            'event' => DataSubjectRequestEvent::EVENT_VERIFIED,
        ]);
    }

    public function test_02_a_token_cannot_be_used_twice(): void
    {
        [, $plain] = $this->pendingRequest();

        $this->assertSame(PrivacyRequestService::VERIFIED, $this->service->verify($plain)['state']);
        $this->assertSame(PrivacyRequestService::VERIFY_USED, $this->service->verify($plain)['state']);
    }

    public function test_03_an_expired_token_expires_the_request_and_reveals_only_the_reference(): void
    {
        [$request, $plain] = $this->pendingRequest();

        Carbon::setTestNow(Carbon::now()->addDays(2));

        $outcome = $this->service->verify($plain);

        $this->assertSame(PrivacyRequestService::VERIFY_EXPIRED, $outcome['state']);
        $this->assertSame('expired', $request->refresh()->status->value);
    }

    public function test_04_a_forged_token_is_invalid_and_changes_nothing(): void
    {
        [$request] = $this->pendingRequest();

        $outcome = $this->service->verify(bin2hex(random_bytes(32)));

        $this->assertSame(PrivacyRequestService::VERIFY_INVALID, $outcome['state']);
        $this->assertNull($outcome['reference']);
        $this->assertSame('pending_verification', $request->refresh()->status->value);
    }

    public function test_05_the_verification_page_is_signed_and_renders_the_outcome(): void
    {
        [$request, $plain] = $this->pendingRequest();

        $url = URL::temporarySignedRoute('privacy.request.verify', now()->addMinutes(30), ['token' => $plain]);

        $response = $this->get($url)
            ->assertOk()
            ->assertSee((string) trans('privacy_requests.verify.success_heading'))
            ->assertSee($request->reference);

        // The page is not cached (a shared browser must not keep a verified
        // request around) and the token is never printed back into it.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString($plain, (string) $response->getContent());
        $this->assertSame('noindex, nofollow', (string) $response->headers->get('X-Robots-Tag'));
    }

    public function test_06_an_unsigned_verification_url_is_refused(): void
    {
        [, $plain] = $this->pendingRequest();

        $this->get(route('privacy.request.verify', ['token' => $plain]))->assertForbidden();
    }

    // =====================================================================
    // Transitions
    // =====================================================================

    public function test_07_a_legal_transition_is_recorded_with_its_actor_and_note(): void
    {
        [$request, $plain] = $this->pendingRequest();
        $this->service->verify($plain);

        $staff = User::factory()->create();

        $updated = $this->service->transition(
            $request->refresh(),
            PrivacyRequestStatus::InReview,
            DataSubjectRequestEvent::ACTOR_STAFF,
            (int) $staff->getKey(),
            'Picked up by the compliance desk',
        );

        $this->assertSame('in_review', $updated->status->value);

        $event = DataSubjectRequestEvent::query()
            ->where('request_id', $request->getKey())
            ->where('event', DataSubjectRequestEvent::EVENT_STATUS_CHANGE)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(DataSubjectRequestEvent::ACTOR_STAFF, $event->actor_type);
        $this->assertSame((int) $staff->getKey(), (int) $event->actor_id);
        $this->assertSame('received', $event->from_status);
        $this->assertSame('in_review', $event->to_status);

        Mail::assertQueued(PrivacyRequestUpdateMail::class);
    }

    public function test_08_an_illegal_transition_is_refused_and_writes_nothing(): void
    {
        [$request, $plain] = $this->pendingRequest();
        $this->service->verify($plain);

        $before = $request->refresh()->status;

        try {
            $this->service->transition($request, PrivacyRequestStatus::Completed, DataSubjectRequestEvent::ACTOR_STAFF);
            $this->fail('A request in Received must not jump straight to Completed.');
        } catch (PrivacyRequestException $exception) {
            $this->assertSame(PrivacyRequestException::CODE_INVALID_TRANSITION, $exception->errorCode());
        }

        $this->assertSame($before, $request->refresh()->status);
        $this->assertSame(0, DataSubjectRequestEvent::query()
            ->where('request_id', $request->getKey())
            ->where('to_status', 'completed')
            ->count());
    }

    public function test_09_a_pending_request_cannot_be_completed_before_verification(): void
    {
        [$request] = $this->pendingRequest();

        $this->expectException(PrivacyRequestException::class);

        $this->service->transition($request, PrivacyRequestStatus::InReview, DataSubjectRequestEvent::ACTOR_STAFF);
    }

    public function test_10_cancelling_is_allowed_once_and_then_refused(): void
    {
        [$request, $plain] = $this->pendingRequest();
        $this->service->verify($plain);

        $cancelled = $this->service->cancel($request->refresh());

        $this->assertSame('cancelled', $cancelled->status->value);
        $this->assertTrue($cancelled->status->isTerminal());

        $this->expectException(PrivacyRequestException::class);
        $this->service->cancel($cancelled->refresh());
    }

    public function test_11_the_event_trail_cannot_be_rewritten(): void
    {
        [$request, $plain] = $this->pendingRequest();
        $this->service->verify($plain);

        $event = DataSubjectRequestEvent::query()->where('request_id', $request->getKey())->firstOrFail();

        $this->expectException(\LogicException::class);
        $event->note_encrypted = 'rewritten';
        $event->save();
    }

    public function test_12_the_overdue_scope_and_helper_agree(): void
    {
        [$request, $plain] = $this->pendingRequest();
        $this->service->verify($plain);

        $request = $request->refresh();
        $this->assertFalse($request->isOverdue());

        $request->due_at = Carbon::now()->subDay();
        $request->saveQuietly();

        $fresh = $request->refresh();

        $this->assertTrue($fresh->isOverdue());
        $this->assertTrue(DataSubjectRequest::query()->overdue()->whereKey($fresh->getKey())->exists());
        $this->assertFalse(DataSubjectRequest::query()->dueSoon()->whereKey($fresh->getKey())->exists());
    }

    public function test_13_only_verified_requests_occupy_an_open_slot_of_the_ceiling(): void
    {
        // Pending verification counts as open (it is a live claim), and a
        // cancelled one does not - otherwise a person who cancels could never
        // submit again.
        [$request] = $this->pendingRequest('slot@example.test');

        $this->assertTrue($request->refresh()->occupiesOpenSlot());

        $this->service->cancel($request->refresh());

        $this->assertFalse($request->refresh()->occupiesOpenSlot());
    }

    // =====================================================================
    // The retention gate on erasure and restriction
    // =====================================================================

    /**
     * A user whose record carries an obligation: a settled financial
     * transaction inside the retention window. This is the ordinary reason an
     * erasure cannot be granted today.
     */
    private function userWithRetainedRecords(): User
    {
        $user = User::factory()->create();

        $walletId = \Illuminate\Support\Facades\DB::table('wallets')->insertGetId([
            'user_id' => (int) $user->getKey(),
            'type' => 'cash',
            'status' => 'active',
            'currency' => 'THB',
            'balance' => '0.00',
            'locked_balance' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('financial_transactions')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'reference_number' => 'TX-'.strtoupper(bin2hex(random_bytes(5))),
            'user_id' => (int) $user->getKey(),
            'wallet_id' => $walletId,
            'type' => 'deposit',
            'status' => 'completed',
            'currency' => 'THB',
            'amount' => '100.00',
            'fee' => '0.00',
            'description' => 'A retained deposit',
            'processed_at' => now()->subMonths(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function erasureFor(User $user, PrivacyRequestStatus $status = PrivacyRequestStatus::InProgress): DataSubjectRequest
    {
        return DataSubjectRequest::factory()->create([
            'user_id' => (int) $user->getKey(),
            'email_encrypted' => (string) $user->email,
            'email_hash' => DataSubjectRequest::hashEmail((string) $user->email),
            'type' => PrivacyRequestType::Erasure,
            'status' => $status->value,
            'verified_at' => now(),
            'received_at' => now(),
            'due_at' => now()->addDays(20),
        ]);
    }

    public function test_14_an_erasure_blocked_by_retention_cannot_be_completed_without_a_justification(): void
    {
        $user = $this->userWithRetainedRecords();
        $request = $this->erasureFor($user);

        try {
            $this->service->transition($request, PrivacyRequestStatus::Completed, DataSubjectRequestEvent::ACTOR_STAFF, null, 'done');
            $this->fail('An erasure over retained financial records must not be completed silently.');
        } catch (PrivacyRequestException $exception) {
            $this->assertSame(PrivacyRequestException::CODE_RETENTION_BLOCKED, $exception->errorCode());
            $this->assertContains('financial_records', (array) ($exception->context()['reasons'] ?? []));
        }

        $this->assertSame(PrivacyRequestStatus::InProgress, $request->refresh()->status);
        $this->assertNull($request->completed_at);
    }

    public function test_15_a_two_word_justification_is_not_a_justification(): void
    {
        $user = $this->userWithRetainedRecords();
        $request = $this->erasureFor($user);

        try {
            $this->service->transition(
                $request,
                PrivacyRequestStatus::Completed,
                DataSubjectRequestEvent::ACTOR_STAFF,
                null,
                'done',
                true,
                null,
                'ok fine',
            );
            $this->fail('A nine-character justification must not pass the gate.');
        } catch (PrivacyRequestException $exception) {
            $this->assertSame(PrivacyRequestException::CODE_RETENTION_BLOCKED, $exception->errorCode());
        }

        $this->assertSame(PrivacyRequestStatus::InProgress, $request->refresh()->status);
    }

    public function test_16_a_written_override_completes_the_request_and_is_recorded(): void
    {
        $user = $this->userWithRetainedRecords();
        $request = $this->erasureFor($user);

        $justification = 'The financial window was reviewed; the retained lines carry no identifiers after pseudonymisation and the ledger copy is held separately.';

        $completed = $this->service->transition(
            $request,
            PrivacyRequestStatus::Completed,
            DataSubjectRequestEvent::ACTOR_STAFF,
            1,
            'Erased the profile and anonymised the retained lines.',
            true,
            null,
            $justification,
        );

        $this->assertSame(PrivacyRequestStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);

        $this->assertDatabaseHas('data_subject_request_events', [
            'request_id' => $request->getKey(),
            'event' => DataSubjectRequestEvent::EVENT_RETENTION_CHECK,
            'to_status' => PrivacyRequestStatus::Completed->value,
        ]);

        $override = DataSubjectRequestEvent::query()
            ->where('request_id', $request->getKey())
            ->where('event', DataSubjectRequestEvent::EVENT_RETENTION_CHECK)
            ->firstOrFail();

        $this->assertStringContainsString('override:', (string) $override->note_encrypted);
        $this->assertStringContainsString('retained lines', (string) $override->note_encrypted);

        // The audit row records THAT an override happened, never its wording.
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => DataSubjectRequest::class,
            'auditable_id' => $request->getKey(),
        ]);
    }

    public function test_17_an_unblocked_erasure_completes_without_any_override(): void
    {
        $user = User::factory()->create();
        $request = $this->erasureFor($user);

        $completed = $this->service->transition(
            $request,
            PrivacyRequestStatus::Completed,
            DataSubjectRequestEvent::ACTOR_STAFF,
            null,
            'No retained records exist for this account.',
        );

        $this->assertSame(PrivacyRequestStatus::Completed, $completed->status);
        $this->assertSame(0, DataSubjectRequestEvent::query()
            ->where('request_id', $request->getKey())
            ->where('event', DataSubjectRequestEvent::EVENT_RETENTION_CHECK)
            ->count());
    }

    public function test_18_an_erasure_may_only_be_rejected_for_the_retention_obligation(): void
    {
        $user = $this->userWithRetainedRecords();
        $request = $this->erasureFor($user, PrivacyRequestStatus::InReview);

        try {
            $this->service->transition(
                $request,
                PrivacyRequestStatus::Rejected,
                DataSubjectRequestEvent::ACTOR_STAFF,
                null,
                'We do not think this is a real request.',
                true,
                'manifestly_unfounded',
            );
            $this->fail('An erasure may not be refused for a reason other than the retention obligation.');
        } catch (PrivacyRequestException $exception) {
            $this->assertSame(PrivacyRequestException::CODE_RETENTION_REASON_REQUIRED, $exception->errorCode());
        }

        $this->assertSame(PrivacyRequestStatus::InReview, $request->refresh()->status);

        $rejected = $this->service->transition(
            $request->refresh(),
            PrivacyRequestStatus::Rejected,
            DataSubjectRequestEvent::ACTOR_STAFF,
            null,
            'Retained under the anti-money-laundering record-keeping rules for the statutory period.',
            true,
            PrivacyRequestService::RETENTION_REASON_CODE,
        );

        $this->assertSame(PrivacyRequestStatus::Rejected, $rejected->status);
        $this->assertSame(PrivacyRequestService::RETENTION_REASON_CODE, $rejected->rejected_reason_code);
    }

    public function test_19_an_access_request_never_passes_through_the_retention_gate(): void
    {
        $user = $this->userWithRetainedRecords();

        $request = DataSubjectRequest::factory()->create([
            'user_id' => (int) $user->getKey(),
            'email_encrypted' => (string) $user->email,
            'email_hash' => DataSubjectRequest::hashEmail((string) $user->email),
            'type' => PrivacyRequestType::Access,
            'status' => PrivacyRequestStatus::InProgress->value,
            'verified_at' => now(),
            'received_at' => now(),
            'due_at' => now()->addDays(20),
        ]);

        $completed = $this->service->transition(
            $request,
            PrivacyRequestStatus::Completed,
            DataSubjectRequestEvent::ACTOR_STAFF,
            null,
            'The archive was released and downloaded.',
        );

        $this->assertSame(PrivacyRequestStatus::Completed, $completed->status);
    }

    public function test_20_completing_an_erasure_deletes_nothing_by_itself(): void
    {
        $user = $this->userWithRetainedRecords();
        $request = $this->erasureFor($user);

        $this->service->transition(
            $request,
            PrivacyRequestStatus::Completed,
            DataSubjectRequestEvent::ACTOR_STAFF,
            null,
            'Erased under the reviewed procedure.',
            true,
            null,
            'The retained lines were reviewed and carry no direct identifiers after pseudonymisation.',
        );

        // A state change is a RECORD, not an erasure. Nothing may vanish because
        // a status moved - that is the whole reason erasure is a staff task.
        $this->assertDatabaseHas('users', ['id' => $user->getKey()]);
        $this->assertDatabaseHas('financial_transactions', ['user_id' => (int) $user->getKey()]);
        $this->assertDatabaseHas('data_subject_requests', ['id' => $request->getKey()]);
    }

    public function test_21_an_unverified_request_cannot_be_moved_forward_by_staff(): void
    {
        $request = DataSubjectRequest::factory()->create([
            'status' => PrivacyRequestStatus::PendingVerification->value,
            'type' => PrivacyRequestType::Access,
            'verified_at' => null,
            'received_at' => null,
            'due_at' => null,
        ]);

        try {
            $this->service->transition($request, PrivacyRequestStatus::Received, DataSubjectRequestEvent::ACTOR_STAFF, 1);
            $this->fail('An unverified request must not be acknowledged by staff.');
        } catch (PrivacyRequestException $exception) {
            $this->assertSame(PrivacyRequestException::CODE_NOT_VERIFIED, $exception->errorCode());
        }

        $this->assertSame(PrivacyRequestStatus::PendingVerification, $request->refresh()->status);
        $this->assertSame(0, DataSubjectRequestEvent::query()->where('request_id', $request->getKey())->count());
    }

    public function test_22_an_unverified_request_may_still_be_cancelled_or_expire(): void
    {
        $cancelled = DataSubjectRequest::factory()->create([
            'status' => PrivacyRequestStatus::PendingVerification->value,
            'type' => PrivacyRequestType::Access,
            'verified_at' => null,
            'received_at' => null,
            'due_at' => null,
        ]);

        $this->assertSame(
            PrivacyRequestStatus::Cancelled,
            $this->service->transition($cancelled, PrivacyRequestStatus::Cancelled, DataSubjectRequestEvent::ACTOR_REQUESTER)->status,
        );

        $expired = DataSubjectRequest::factory()->create([
            'status' => PrivacyRequestStatus::PendingVerification->value,
            'type' => PrivacyRequestType::Access,
            'verified_at' => null,
            'received_at' => null,
            'due_at' => null,
        ]);

        $this->assertSame(
            PrivacyRequestStatus::Expired,
            $this->service->transition($expired, PrivacyRequestStatus::Expired, DataSubjectRequestEvent::ACTOR_SYSTEM, null, 'verification window closed', false)->status,
        );
    }
}
