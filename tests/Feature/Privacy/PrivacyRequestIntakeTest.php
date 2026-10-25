<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Enums\PrivacyRequestType;
use App\Mail\PrivacyRequestReceivedMail;
use App\Mail\PrivacyRequestVerificationMail;
use App\Models\DataSubjectRequest;
use App\Models\User;
use App\Services\Privacy\PrivacyRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The public privacy-request intake, both ways round.
 *
 * OFF: the endpoint keeps the exact 501 body it answered before this lane
 * existed. That assertion is the reason the lane can ship at all - it proves
 * nothing changed for a client written against the old contract.
 *
 * ON: the interesting failures are the ones that would turn a public form into
 * an account-enumeration oracle. Every test below submits the same shape and
 * asserts that a known address, an unknown address, an address already at its
 * open-request ceiling and a honeypot submission are indistinguishable to the
 * caller.
 */
final class PrivacyRequestIntakeTest extends TestCase
{
    use RefreshDatabase;

    private const OFF_BODY = [
        'success' => false,
        'state' => 'NOT_CONFIGURED',
        'message' => 'A verified privacy-request workflow is not configured for this environment. Use the contact page without sending identity documents through this public endpoint.',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function enableLane(): void
    {
        config()->set('privacy_requests.enabled', true);
        config()->set('privacy_requests.compliance_email', 'compliance@example.test');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'requester.'.bin2hex(random_bytes(4)).'@example.test',
            'type' => PrivacyRequestType::Access->value,
            'details' => 'Please send me a copy of the data you hold about me.',
            'locale' => 'en',
        ], $overrides);
    }

    // =====================================================================
    // OFF: the old contract, byte for byte
    // =====================================================================

    public function test_01_intake_is_not_configured_while_the_lane_is_off(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/public/privacy/dsar-request', $this->payload())
            ->assertStatus(501)
            ->assertExactJson(self::OFF_BODY);

        $this->assertSame(0, DataSubjectRequest::query()->count());
        Mail::assertNothingQueued();
    }

    public function test_02_intake_stays_501_even_for_a_malformed_payload_while_off(): void
    {
        // No email, no type, and an attachment field: while the lane is off this
        // must still be the NOT_CONFIGURED answer, not a validation error. A 422
        // here would tell a client the endpoint exists and is merely picky.
        $this->postJson('/api/v1/public/privacy/dsar-request', ['file' => 'x'])
            ->assertStatus(501)
            ->assertExactJson(self::OFF_BODY);
    }

    // =====================================================================
    // ON: one neutral answer
    // =====================================================================

    public function test_03_a_valid_submission_is_accepted_neutrally_and_stores_a_pending_request(): void
    {
        $this->enableLane();
        Mail::fake();

        $payload = $this->payload();

        $response = $this->postJson('/api/v1/public/privacy/dsar-request', $payload)
            ->assertStatus(202);

        $body = $response->json();

        $this->assertTrue($body['success']);
        $this->assertSame(PrivacyRequestService::OUTCOME_ACCEPTED, $body['state']);
        $this->assertArrayNotHasKey('reference', $body, 'The neutral body must not quote a reference.');

        $request = DataSubjectRequest::query()->firstOrFail();

        $this->assertSame('pending_verification', $request->status->value);
        $this->assertNull($request->verified_at);
        $this->assertNull($request->due_at, 'The clock starts on verification, not on submission.');
        $this->assertNull($request->user_id, 'A public intake must not link an account.');
        $this->assertStringStartsWith('PRV-', $request->reference);

        Mail::assertQueued(PrivacyRequestVerificationMail::class);
    }

    public function test_04_a_known_and_an_unknown_address_produce_the_same_answer(): void
    {
        $this->enableLane();
        Mail::fake();

        // An account exists for the first address; nothing exists for the second.
        $known = User::factory()->create();

        $knownResponse = $this->postJson('/api/v1/public/privacy/dsar-request', $this->payload(['email' => $known->email]));
        Cache::flush();
        $unknownResponse = $this->postJson('/api/v1/public/privacy/dsar-request', $this->payload(['email' => 'nobody.'.bin2hex(random_bytes(4)).'@example.test']));

        $this->assertSame($knownResponse->status(), $unknownResponse->status());
        $this->assertSame($knownResponse->json(), $unknownResponse->json());
        $this->assertSame(202, $knownResponse->status());
    }

    public function test_05_the_honeypot_is_answered_like_a_success_and_writes_nothing(): void
    {
        $this->enableLane();
        Mail::fake();

        $this->postJson('/api/v1/public/privacy/dsar-request', $this->payload(['website' => 'https://spam.example.test']))
            ->assertStatus(202)
            ->assertJsonPath('state', PrivacyRequestService::OUTCOME_ACCEPTED);

        $this->assertSame(0, DataSubjectRequest::query()->count());
        Mail::assertNothingQueued();
    }

    public function test_06_the_open_request_ceiling_answers_neutrally_and_writes_nothing(): void
    {
        $this->enableLane();
        Mail::fake();

        config()->set('privacy_requests.max_open_per_email', 1);
        config()->set('privacy_requests.max_submissions_per_email_per_day', 50);
        config()->set('privacy_requests.max_submissions_per_ip_per_day', 50);

        $payload = $this->payload();

        $this->postJson('/api/v1/public/privacy/dsar-request', $payload)->assertStatus(202);

        Cache::flush();

        // The second submission is over the ceiling and must look identical to
        // the first: same status, same body, one row in the table.
        $second = $this->postJson('/api/v1/public/privacy/dsar-request', $payload);

        $second->assertStatus(202)->assertJsonPath('state', PrivacyRequestService::OUTCOME_ACCEPTED);
        $this->assertSame(1, DataSubjectRequest::query()->count());
    }

    public function test_07_an_attachment_field_is_refused_and_nothing_is_stored(): void
    {
        $this->enableLane();
        Mail::fake();

        $this->postJson('/api/v1/public/privacy/dsar-request', $this->payload(['passport' => 'scan.jpg']))
            ->assertStatus(422);

        $this->assertSame(0, DataSubjectRequest::query()->count(), 'An attachment must be refused, not silently dropped and stored.');
    }

    public function test_08_the_details_are_stored_encrypted_and_the_address_is_only_hashed_for_lookup(): void
    {
        $this->enableLane();
        Mail::fake();

        $payload = $this->payload(['details' => 'SENTINEL DETAIL TEXT']);

        $this->postJson('/api/v1/public/privacy/dsar-request', $payload)->assertStatus(202);

        $request = DataSubjectRequest::query()->firstOrFail();
        $raw = (array) \DB::table('data_subject_requests')->where('id', $request->getKey())->first();

        $this->assertStringNotContainsString('SENTINEL DETAIL TEXT', (string) $raw['details_encrypted']);
        $this->assertStringNotContainsString(strtolower((string) $payload['email']), (string) $raw['email_encrypted']);
        $this->assertSame(DataSubjectRequest::hashEmail((string) $payload['email']), $raw['email_hash']);
        $this->assertSame('SENTINEL DETAIL TEXT', $request->details_encrypted);
    }

    public function test_09_the_authenticated_submission_starts_the_clock_immediately(): void
    {
        $this->enableLane();
        Mail::fake();

        $user = User::factory()->create();

        $outcome = app(PrivacyRequestService::class)->submitAuthenticated(
            $user,
            PrivacyRequestType::Access,
            'Account copy please',
            '203.0.113.9',
        );

        $request = $outcome['request'];

        $this->assertSame('received', $request->status->value);
        $this->assertNotNull($request->verified_at);
        $this->assertSame((int) $user->getKey(), (int) $request->user_id);
        $this->assertSame(
            now()->addDays(app(PrivacyRequestService::class)->slaDays())->toDateString(),
            $request->due_at?->toDateString(),
        );

        Mail::assertQueued(PrivacyRequestReceivedMail::class);
    }

    public function test_10_a_disabled_lane_refuses_silently_at_the_service_boundary_too(): void
    {
        config()->set('privacy_requests.enabled', false);

        $this->expectException(\App\Exceptions\Privacy\PrivacyRequestException::class);

        app(PrivacyRequestService::class)->submitPublic(
            email: 'someone@example.test',
            type: PrivacyRequestType::Access,
            locale: 'en',
            details: null,
        );
    }
}
