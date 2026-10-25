<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Jobs\SendPrivacySlaRemindersJob;
use App\Mail\PrivacySlaDigestMail;
use App\Models\DataSubjectRequest;
use App\Models\DataSubjectRequestEvent;
use App\Models\PersonalDataExport;
use App\Models\PrivacyVerificationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The two clocks nobody can see: retention and the SLA.
 *
 * Both commands are scheduled unconditionally in routes/console.php, so their
 * "lane is off" behaviour matters as much as their working behaviour: a nightly
 * purge that swept tables belonging to a disabled lane would be a defect that
 * only appears in production three weeks after a deploy.
 */
final class PrivacySlaCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');
    }

    private function expiredExport(): PersonalDataExport
    {
        $user = User::factory()->create();
        $request = DataSubjectRequest::factory()->create([
            'user_id' => (int) $user->getKey(),
            'email_encrypted' => (string) $user->email,
            'email_hash' => DataSubjectRequest::hashEmail((string) $user->email),
        ]);

        Storage::disk('local')->put('privacy-exports/2026/10/expiring.zip', 'ARCHIVE BYTES');

        return PersonalDataExport::query()->create([
            'request_id' => (int) $request->getKey(),
            'user_id' => (int) $user->getKey(),
            'disk' => 'local',
            'path' => 'privacy-exports/2026/10/expiring.zip',
            'sha256' => hash('sha256', 'ARCHIVE BYTES'),
            'size_bytes' => 13,
            'status' => PersonalDataExport::STATUS_READY,
            'expires_at' => Carbon::now()->subDay(),
            'max_downloads' => 3,
        ]);
    }

    // =====================================================================
    // purge
    // =====================================================================

    public function test_01_the_purge_does_nothing_at_all_while_the_lane_is_off(): void
    {
        config()->set('privacy_requests.enabled', false);

        $export = $this->expiredExport();

        $this->artisan('privacy:purge-expired')->assertSuccessful();

        $this->assertTrue(Storage::disk('local')->exists($export->path), 'A disabled lane must not delete a file.');
        $this->assertSame(PersonalDataExport::STATUS_READY, $export->refresh()->status);
    }

    public function test_02_the_purge_deletes_an_expired_archive_before_marking_it(): void
    {
        config()->set('privacy_requests.enabled', true);

        $export = $this->expiredExport();

        $this->artisan('privacy:purge-expired')->assertSuccessful();

        $this->assertFalse(Storage::disk('local')->exists($export->path), 'The bytes are still on disk.');
        $this->assertSame(PersonalDataExport::STATUS_EXPIRED, $export->refresh()->status);
    }

    public function test_03_a_dry_run_reports_without_touching_anything(): void
    {
        config()->set('privacy_requests.enabled', true);

        $export = $this->expiredExport();

        $this->artisan('privacy:purge-expired', ['--dry-run' => true])
            ->expectsOutputToContain('would be removed')
            ->assertSuccessful();

        $this->assertTrue(Storage::disk('local')->exists($export->path));
        $this->assertSame(PersonalDataExport::STATUS_READY, $export->refresh()->status);
    }

    public function test_04_an_unverified_request_past_its_window_expires(): void
    {
        config()->set('privacy_requests.enabled', true);
        config()->set('privacy_requests.verification_ttl_minutes', 60);

        $request = DataSubjectRequest::factory()->create([
            'status' => 'pending_verification',
            'verified_at' => null,
            'received_at' => null,
            'due_at' => null,
            'created_at' => Carbon::now()->subDays(3),
        ]);

        $this->artisan('privacy:purge-expired')->assertSuccessful();

        $this->assertSame('expired', $request->refresh()->status->value);
        $this->assertDatabaseHas('data_subject_request_events', [
            'request_id' => $request->getKey(),
            'to_status' => 'expired',
        ]);
    }

    public function test_05_a_verified_request_is_never_expired_by_the_purge(): void
    {
        config()->set('privacy_requests.enabled', true);

        $request = DataSubjectRequest::factory()->create([
            'status' => 'received',
            'verified_at' => Carbon::now()->subDays(5),
            'received_at' => Carbon::now()->subDays(5),
            'due_at' => Carbon::now()->addDays(25),
        ]);

        $this->artisan('privacy:purge-expired')->assertSuccessful();

        $this->assertSame('received', $request->refresh()->status->value);
    }

    public function test_06_spent_and_expired_tokens_are_retired(): void
    {
        config()->set('privacy_requests.enabled', true);

        $request = DataSubjectRequest::factory()->create();

        $spent = PrivacyVerificationToken::issue((int) $request->getKey());
        $spent['token']->consume();

        $stale = PrivacyVerificationToken::issue((int) $request->getKey());
        $stale['token']->forceFill(['expires_at' => Carbon::now()->subHour()])->save();

        $live = PrivacyVerificationToken::issue((int) $request->getKey());

        $this->artisan('privacy:purge-expired')->assertSuccessful();

        $this->assertDatabaseMissing('privacy_verification_tokens', ['id' => $spent['token']->getKey()]);
        $this->assertDatabaseMissing('privacy_verification_tokens', ['id' => $stale['token']->getKey()]);
        $this->assertDatabaseHas('privacy_verification_tokens', ['id' => $live['token']->getKey()]);
    }

    // =====================================================================
    // SLA sweep
    // =====================================================================

    public function test_07_the_sla_command_dispatches_nothing_while_the_lane_is_off(): void
    {
        config()->set('privacy_requests.enabled', false);
        Queue::fake();

        $this->artisan('privacy:sla-check')->assertSuccessful();

        Queue::assertNotPushed(SendPrivacySlaRemindersJob::class);
    }

    public function test_08_the_sla_command_dispatches_the_digest_when_an_address_is_configured(): void
    {
        config()->set('privacy_requests.enabled', true);
        config()->set('privacy_requests.compliance_email', 'compliance@example.test');
        Queue::fake();

        $this->artisan('privacy:sla-check')->assertSuccessful();

        Queue::assertPushed(SendPrivacySlaRemindersJob::class);
    }

    public function test_09_the_sla_command_refuses_to_pretend_a_digest_was_sent_without_an_address(): void
    {
        config()->set('privacy_requests.enabled', true);
        config()->set('privacy_requests.compliance_email', null);
        Queue::fake();

        $this->artisan('privacy:sla-check')
            ->expectsOutputToContain('no compliance address is configured')
            ->assertSuccessful();

        Queue::assertNotPushed(SendPrivacySlaRemindersJob::class);
    }

    public function test_10_the_digest_names_overdue_requests_and_records_one_reminder_per_day(): void
    {
        config()->set('privacy_requests.enabled', true);
        config()->set('privacy_requests.compliance_email', 'compliance@example.test');

        $overdue = DataSubjectRequest::factory()->create([
            'status' => 'in_review',
            'verified_at' => Carbon::now()->subDays(40),
            'received_at' => Carbon::now()->subDays(40),
            'due_at' => Carbon::now()->subDays(2),
        ]);

        $job = new SendPrivacySlaRemindersJob;
        $result = app()->call([$job, 'handle']);

        $this->assertSame(1, $result['overdue']);
        $this->assertTrue($result['mailed']);
        Mail::assertQueued(PrivacySlaDigestMail::class);

        $this->assertDatabaseHas('data_subject_request_events', [
            'request_id' => $overdue->getKey(),
            'event' => DataSubjectRequestEvent::EVENT_OVERDUE,
        ]);
        $this->assertDatabaseHas('data_subject_request_events', [
            'request_id' => $overdue->getKey(),
            'event' => DataSubjectRequestEvent::EVENT_SLA_REMINDER,
        ]);

        // A second run the same day sends nothing: "once per day" is a row, not
        // a promise about the scheduler.
        $second = app()->call([new SendPrivacySlaRemindersJob, 'handle']);

        $this->assertFalse($second['mailed']);
        $this->assertSame(0, DataSubjectRequestEvent::query()
            ->where('request_id', $overdue->getKey())
            ->where('event', DataSubjectRequestEvent::EVENT_OVERDUE)
            ->count() - 1);
    }

    public function test_11_the_digest_is_not_sent_when_nothing_is_due(): void
    {
        config()->set('privacy_requests.enabled', true);
        config()->set('privacy_requests.compliance_email', 'compliance@example.test');

        DataSubjectRequest::factory()->create([
            'status' => 'received',
            'verified_at' => Carbon::now(),
            'received_at' => Carbon::now(),
            'due_at' => Carbon::now()->addDays(29),
        ]);

        $result = app()->call([new SendPrivacySlaRemindersJob, 'handle']);

        $this->assertFalse($result['mailed']);
        Mail::assertNothingQueued();
    }
}
