<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Enums\PrivacyRequestStatus;
use App\Enums\PrivacyRequestType;
use App\Models\DataSubjectRequest;
use App\Models\PersonalDataExport;
use App\Models\User;
use App\Models\DataSubjectRequestEvent;
use App\Services\Privacy\PrivacyVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The two pages a requester actually sees.
 *
 * A privacy workflow that cannot be read by the person it belongs to is not a
 * workflow, it is a mailbox. These render through the real routes - not through
 * view('...') - so a controller that passes the wrong variables, a missing
 * translation or a route that stopped being registered fails here rather than
 * in front of a regulator.
 */
final class PrivacyPagesRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('privacy_requests.enabled', true);
        config()->set('privacy_requests.compliance_email', 'compliance@example.test');
    }

    private function request(User $user, array $overrides = []): DataSubjectRequest
    {
        return DataSubjectRequest::factory()->create(array_merge([
            'user_id' => (int) $user->getKey(),
            'email_encrypted' => (string) $user->email,
            'email_hash' => DataSubjectRequest::hashEmail((string) $user->email),
            'type' => PrivacyRequestType::Access,
            'status' => PrivacyRequestStatus::InProgress,
            'verified_at' => now(),
            'received_at' => now(),
            'due_at' => now()->addDays(20),
        ], $overrides));
    }

    // =====================================================================
    // The account area
    // =====================================================================

    public function test_01_the_account_page_renders_for_a_signed_in_player(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account.privacy-requests.index'))
            ->assertOk()
            ->assertSee((string) trans('privacy_requests.account.heading'), false);
    }

    public function test_02_the_account_page_lists_only_the_players_own_requests(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $mine = $this->request($user);
        $theirs = $this->request($other, ['status' => PrivacyRequestStatus::Received]);

        $response = $this->actingAs($user)->get(route('account.privacy-requests.index'))->assertOk();

        $response->assertSee($mine->reference);
        $response->assertDontSee($theirs->reference);
    }

    public function test_03_the_page_never_echoes_the_encrypted_description_or_the_address(): void
    {
        $user = User::factory()->create();

        $this->request($user, [
            'details_encrypted' => 'Please erase the marketing consent for my address 12 Soi 4.',
        ]);

        $response = $this->actingAs($user)->get(route('account.privacy-requests.index'))->assertOk();

        $response->assertDontSee('12 Soi 4');
        $response->assertDontSee((string) $user->email);
    }

    public function test_04_the_page_states_that_erasure_is_reviewed_rather_than_instant(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account.privacy-requests.index'))
            ->assertOk()
            ->assertSee((string) trans('privacy_requests.account.retention_note'), false);
    }

    public function test_05_the_page_is_marked_noindex(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account.privacy-requests.index'))
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex,nofollow">', false);
    }

    public function test_06_a_ready_export_is_offered_as_a_signed_link_and_nothing_else_is(): void
    {
        $user = User::factory()->create();
        $request = $this->request($user);

        Storage::disk('local')->put('privacy-exports/2026/10/ready.zip', 'ZIP BYTES');

        PersonalDataExport::query()->create([
            'request_id' => (int) $request->getKey(),
            'user_id' => (int) $user->getKey(),
            'disk' => 'local',
            'path' => 'privacy-exports/2026/10/ready.zip',
            'sha256' => hash('sha256', 'ZIP BYTES'),
            'size_bytes' => 9,
            'status' => PersonalDataExport::STATUS_READY,
            'expires_at' => now()->addDays(2),
            'max_downloads' => 3,
        ]);

        $response = $this->actingAs($user)->get(route('account.privacy-requests.index'))->assertOk();

        $this->assertStringContainsString('signature=', (string) $response->getContent());
        $this->assertStringContainsString('expires=', (string) $response->getContent());
    }

    public function test_07_a_cancelled_request_is_shown_as_cancelled_and_offers_no_cancel_again(): void
    {
        $user = User::factory()->create();
        $request = $this->request($user, ['status' => PrivacyRequestStatus::Cancelled]);

        $response = $this->actingAs($user)->get(route('account.privacy-requests.index'))->assertOk();

        $response->assertSee($request->reference);
        $response->assertDontSee(route('account.privacy-requests.cancel', ['reference' => $request->reference]), false);
    }

    public function test_08_the_page_renders_with_the_lane_off_and_says_so(): void
    {
        config()->set('privacy_requests.enabled', false);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account.privacy-requests.index'))
            ->assertOk()
            ->assertSee((string) trans('privacy_requests.account.disabled'), false);
    }

    public function test_09_submitting_while_the_lane_is_off_creates_nothing(): void
    {
        config()->set('privacy_requests.enabled', false);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('account.privacy-requests.store'), [
                'type' => PrivacyRequestType::Access->value,
                'details' => 'Please send me a copy of my data.',
            ])
            ->assertRedirect(route('account.privacy-requests.index'));

        $this->assertSame(0, DataSubjectRequest::query()->count());
    }

    public function test_10_an_authenticated_submission_appears_immediately_with_a_started_clock(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('account.privacy-requests.store'), [
                'type' => PrivacyRequestType::Access->value,
                'details' => 'Please send me a copy of my data.',
            ])
            ->assertRedirect(route('account.privacy-requests.index'));

        $request = DataSubjectRequest::query()->firstOrFail();

        $this->assertSame(PrivacyRequestStatus::Received, $request->status);
        $this->assertNotNull($request->due_at);

        $this->actingAs($user)
            ->get(route('account.privacy-requests.index'))
            ->assertOk()
            ->assertSee($request->reference);
    }

    // =====================================================================
    // The verification landing page
    // =====================================================================

    private function plainTokenFor(DataSubjectRequest $request): string
    {
        return app(PrivacyVerificationService::class)->issue($request)['plain'];
    }

    public function test_11_a_valid_link_renders_the_verified_page(): void
    {
        $user = User::factory()->create();

        $request = $this->request($user, [
            'status' => PrivacyRequestStatus::PendingVerification,
            'verified_at' => null,
            'received_at' => null,
            'due_at' => null,
        ]);

        $plain = $this->plainTokenFor($request);

        $response = $this->get(URL::signedRoute('privacy.request.verify', [
            'reference' => $request->reference,
            'token' => $plain,
        ]))->assertOk();

        $response->assertSee((string) trans('privacy_requests.verify.success_heading'), false);
        $response->assertDontSee($plain);
        $response->assertDontSee((string) $user->email);

        $this->assertSame(PrivacyRequestStatus::Received, $request->refresh()->status);
    }

    public function test_12_a_used_link_renders_the_already_used_page_and_changes_nothing_again(): void
    {
        $user = User::factory()->create();

        $request = $this->request($user, [
            'status' => PrivacyRequestStatus::PendingVerification,
            'verified_at' => null,
            'received_at' => null,
            'due_at' => null,
        ]);

        $plain = $this->plainTokenFor($request);
        $url = URL::signedRoute('privacy.request.verify', [
            'reference' => $request->reference,
            'token' => $plain,
        ]);

        $this->get($url)->assertOk();

        $dueAt = $request->refresh()->due_at;

        $this->get($url)
            ->assertOk()
            ->assertSee((string) trans('privacy_requests.verify.used_heading'), false);

        // A second visit must not move the deadline or record a second event.
        $this->assertSame($dueAt?->toDateTimeString(), $request->refresh()->due_at?->toDateTimeString());
        $this->assertSame(1, DataSubjectRequestEvent::query()
            ->where('request_id', $request->getKey())
            ->where('event', DataSubjectRequestEvent::EVENT_VERIFIED)
            ->count());
    }

    public function test_13_the_page_carries_no_store_and_noindex(): void
    {
        $user = User::factory()->create();

        $request = $this->request($user, [
            'status' => PrivacyRequestStatus::PendingVerification,
            'verified_at' => null,
            'received_at' => null,
            'due_at' => null,
        ]);

        $response = $this->get(URL::signedRoute('privacy.request.verify', [
            'reference' => $request->reference,
            'token' => $this->plainTokenFor($request),
        ]))->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
    }

    public function test_14_an_expired_link_explains_itself_without_naming_anything(): void
    {
        $user = User::factory()->create();

        $request = $this->request($user, [
            'status' => PrivacyRequestStatus::PendingVerification,
            'verified_at' => null,
            'received_at' => null,
            'due_at' => null,
        ]);

        $issued = app(PrivacyVerificationService::class)->issue($request, now()->subMinute());

        $response = $this->get(URL::signedRoute('privacy.request.verify', [
            'reference' => $request->reference,
            'token' => $issued['plain'],
        ]))->assertOk();

        $response->assertSee((string) trans('privacy_requests.verify.expired_heading'), false);
        $response->assertDontSee((string) $user->email);

        $this->assertSame(PrivacyRequestStatus::Expired, $request->refresh()->status);
    }

    public function test_15_a_signed_in_requester_cannot_file_against_another_address(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test']);

        $this->actingAs($user)
            ->post(route('account.privacy-requests.store'), [
                'type' => PrivacyRequestType::Access->value,
                'details' => 'A copy of my data, please.',
                // The form does not ask for one, but a hand-rolled POST can
                // send anything. The account address is the identity; this
                // field is not an input at all.
                'email' => 'somebody.else@example.test',
            ])
            ->assertRedirect(route('account.privacy-requests.index'));

        $request = DataSubjectRequest::query()->firstOrFail();

        $this->assertSame((int) $user->getKey(), (int) $request->user_id);
        $this->assertSame(DataSubjectRequest::hashEmail('owner@example.test'), $request->email_hash);
        $this->assertSame('owner@example.test', $request->email_encrypted);
        $this->assertSame(0, DataSubjectRequest::query()
            ->where('email_hash', DataSubjectRequest::hashEmail('somebody.else@example.test'))
            ->count());
    }

    public function test_16_a_forged_token_renders_the_invalid_page_and_changes_nothing(): void
    {
        $user = User::factory()->create();

        $request = $this->request($user, [
            'status' => PrivacyRequestStatus::PendingVerification,
            'verified_at' => null,
            'received_at' => null,
            'due_at' => null,
        ]);

        $this->plainTokenFor($request);

        $response = $this->get(URL::signedRoute('privacy.request.verify', [
            'reference' => $request->reference,
            'token' => str_repeat('deadbeef', 8),
        ]))->assertOk();

        $response->assertSee((string) trans('privacy_requests.verify.invalid_heading'), false);
        $response->assertDontSee((string) $user->email);
        $response->assertDontSee($request->reference);

        $this->assertSame(PrivacyRequestStatus::PendingVerification, $request->refresh()->status);
        $this->assertSame(0, DataSubjectRequestEvent::query()
            ->where('request_id', $request->getKey())
            ->where('event', DataSubjectRequestEvent::EVENT_VERIFIED)
            ->count());
    }
}
