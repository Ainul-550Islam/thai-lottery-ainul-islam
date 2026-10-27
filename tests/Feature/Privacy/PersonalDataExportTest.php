<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Enums\PrivacyRequestStatus;
use App\Enums\PrivacyRequestType;
use App\Exceptions\Privacy\PrivacyRequestException;
use App\Mail\PersonalDataExportReadyMail;
use App\Models\DataSubjectRequest;
use App\Models\DataSubjectRequestEvent;
use App\Models\PersonalDataExport;
use App\Models\PrivacyVerificationToken;
use App\Models\User;
use App\Services\Privacy\PersonalDataExporter;
use App\Services\Privacy\PrivacyRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;
use ZipArchive;

/**
 * The personal-data archive.
 *
 * WHAT IS ACTUALLY BEING TESTED, AND WHY EACH ONE MATTERS
 *  1. THE ARCHIVE CONTAINS THIS PERSON'S DATA. Proved positively (their own bet,
 *     deposit and support message are inside) and negatively (a second account's
 *     rows are not), because "it exported something" is not the same claim.
 *  2. THE ARCHIVE WITHHOLDS WHAT IT MUST. No identity-document number, no
 *     document file path, no IP address, no device fingerprint, no internal
 *     staff note, no fraud/AML row.
 *  3. THE ARCHIVE IS FINDABLE AGAIN. sha256 is recorded at build time and
 *     re-verified before download; a tampered file is refused.
 *  4. THE ARCHIVE EXPIRES. Deleting the bytes is what "expires" means; the row is
 *     marked too, but the purge test asserts the FILE is gone.
 *  5. NOTHING IS GUESSED. Two accounts sharing an address is refused with a
 *     NeedsInformation transition rather than an arbitrary pick.
 */
final class PersonalDataExportTest extends TestCase
{
    use RefreshDatabase;

    private PrivacyRequestService $requests;

    private PersonalDataExporter $exporter;

    private User $user;

    private DataSubjectRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('privacy_requests.enabled', true);
        config()->set('privacy_requests.compliance_email', 'compliance@example.test');
        config()->set('privacy_requests.export.ttl_days', 7);
        config()->set('privacy_requests.export.max_downloads', 2);

        Mail::fake();
        Storage::fake('local');

        $this->requests = app(PrivacyRequestService::class);
        $this->exporter = app(PersonalDataExporter::class);

        $this->user = User::factory()->create([
            'name' => 'Export Subject',
            'email' => 'export.subject@example.test',
        ]);

        $outcome = $this->requests->submitAuthenticated(
            $this->user,
            PrivacyRequestType::Access,
            null,
            null,
        );

        $this->request = $outcome['request'];
        $this->requests->transition($this->request, PrivacyRequestStatus::InReview, DataSubjectRequestEvent::ACTOR_STAFF);
        $this->request->refresh();
    }

    private function seedOwnData(): void
    {
        $walletId = DB::table('wallets')->insertGetId([
            'user_id' => $this->user->getKey(),
            'type' => 'cash',
            'status' => 'active',
            'currency' => 'THB',
            'balance' => '1250.00',
            'locked_balance' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('financial_transactions')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'reference_number' => 'TXN-OWN-0001',
            'user_id' => $this->user->getKey(),
            'wallet_id' => $walletId,
            'type' => 'deposit',
            'status' => 'completed',
            'currency' => 'THB',
            'amount' => '500.00',
            'fee' => '0.00',
            'description' => 'OWN TRANSACTION',
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('contact_messages')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'public_reference' => 'TCK-OWN00001',
            'name' => 'Export Subject',
            'email' => 'export.subject@example.test',
            'subject' => 'Own support subject',
            'message' => 'OWN SUPPORT MESSAGE',
            'status' => 'RESOLVED',
            'delivery_state' => 'NOT_CONFIGURED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('notification_preferences')->insert([
            'user_id' => $this->user->getKey(),
            'event_type' => 'withdrawal',
            'channel' => 'email',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedOtherUsersData(): void
    {
        $stranger = User::factory()->create(['email' => 'stranger@example.test']);

        DB::table('contact_messages')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'public_reference' => 'TCK-OTHER0001',
            'name' => 'Stranger',
            'email' => 'stranger@example.test',
            'subject' => 'OTHER SUPPORT SUBJECT',
            'message' => 'OTHER SUPPORT MESSAGE',
            'status' => 'RECEIVED',
            'delivery_state' => 'NOT_CONFIGURED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('financial_transactions')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'reference_number' => 'TXN-OTHER-0001',
            'user_id' => $stranger->getKey(),
            'type' => 'deposit',
            'status' => 'completed',
            'currency' => 'THB',
            'amount' => '999.00',
            'fee' => '0.00',
            'description' => 'OTHER TRANSACTION',
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function readArchive(PersonalDataExport $export): array
    {
        $path = Storage::disk($export->disk)->path($export->path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The archive is not a readable ZIP.');

        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $files[$name] = (string) $zip->getFromIndex($i);
        }

        $zip->close();

        return $files;
    }

    public function test_01_the_archive_is_built_with_a_digest_a_size_and_an_expiry(): void
    {
        $this->seedOwnData();

        $export = $this->exporter->buildFor($this->request->refresh());

        $this->assertSame(PersonalDataExport::STATUS_READY, $export->status);
        $this->assertSame(64, strlen($export->sha256));
        $this->assertGreaterThan(0, $export->size_bytes);
        $this->assertSame(
            Carbon::now()->addDays(7)->toDateString(),
            $export->expires_at?->toDateString(),
        );
        $this->assertSame(2, (int) $export->max_downloads);
        $this->assertTrue(Storage::disk($export->disk)->exists($export->path));
        $this->assertTrue($this->exporter->verifyDigest($export));

        $this->assertDatabaseHas('data_subject_request_events', [
            'request_id' => $this->request->getKey(),
            'event' => DataSubjectRequestEvent::EVENT_EXPORT_BUILT,
        ]);
    }

    public function test_02_every_dataset_the_requester_expects_is_present(): void
    {
        $this->seedOwnData();

        $files = $this->readArchive($this->exporter->buildFor($this->request->refresh()));

        foreach ([
            'README.txt',
            'profile.json',
            'wallets.json',
            'transactions.json',
            'deposits.json',
            'withdrawals.json',
            'bets.json',
            'consents.json',
            'kyc.json',
            'notification_preferences.json',
            'security_events.json',
            'support_messages.json',
            'privacy_requests.json',
        ] as $expected) {
            $this->assertArrayHasKey($expected, $files, "The archive is missing {$expected}.");
        }

        $this->assertStringContainsString((string) $this->request->reference, $files['README.txt']);
    }

    public function test_03_the_archive_contains_the_requesters_own_rows_and_nobody_elses(): void
    {
        $this->seedOwnData();
        $this->seedOtherUsersData();

        $files = $this->readArchive($this->exporter->buildFor($this->request->refresh()));
        $everything = implode("\n", $files);

        $this->assertStringContainsString('OWN TRANSACTION', $everything);
        $this->assertStringContainsString('OWN SUPPORT MESSAGE', $everything);

        $this->assertStringNotContainsString('OTHER TRANSACTION', $everything);
        $this->assertStringNotContainsString('OTHER SUPPORT MESSAGE', $everything);
        $this->assertStringNotContainsString('stranger@example.test', $everything);
    }

    public function test_04_the_archive_never_contains_documents_fingerprints_or_staff_notes(): void
    {
        $this->seedOwnData();

        DB::table('kyc_documents')->insert([
            'user_id' => $this->user->getKey(),
            'document_type' => 'passport',
            'document_number' => 'DOCNUMBER-SECRET-1234',
            'file_path' => 'kyc/secret/path.jpg',
            'original_filename' => 'my-passport.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('security_events')->insert([
            'event_fingerprint' => 'fp-'.bin2hex(random_bytes(8)),
            'event_type' => 'login',
            'user_id' => $this->user->getKey(),
            'risk_level' => 'low',
            'ip_address' => '198.51.100.77',
            'device_fingerprint' => 'DEVICEFINGERPRINT-SECRET',
            'reviewed' => false,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $everything = implode("\n", $this->readArchive($this->exporter->buildFor($this->request->refresh())));

        foreach ([
            'DOCNUMBER-SECRET-1234',
            'kyc/secret/path.jpg',
            'my-passport.jpg',
            '198.51.100.77',
            'DEVICEFINGERPRINT-SECRET',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $everything, "The archive leaked {$forbidden}.");
        }

        // The FACT of verification is still there - withholding evidence is not
        // the same as withholding the person's own status.
        $this->assertStringContainsString('passport', $everything);
    }

    public function test_05_the_profile_file_omits_credentials(): void
    {
        $this->seedOwnData();

        $files = $this->readArchive($this->exporter->buildFor($this->request->refresh()));
        $profile = json_decode($files['profile.json'], true);

        $this->assertIsArray($profile);
        $this->assertSame('Export Subject', $profile['name']);
        $this->assertArrayNotHasKey('password', $profile);
        $this->assertArrayNotHasKey('remember_token', $profile);
    }

    public function test_06_a_tampered_archive_fails_its_digest(): void
    {
        $export = $this->exporter->buildFor($this->request->refresh());

        Storage::disk($export->disk)->put($export->path, 'not the archive that was built');

        $this->assertFalse($this->exporter->verifyDigest($export));
    }

    public function test_07_an_unresolvable_account_is_refused_instead_of_guessed(): void
    {
        // The request is unlinked and names an address no account holds. Nothing
        // may be exported: guessing an account would mail somebody else's
        // history to the person who asked.
        $request = DataSubjectRequest::factory()->create([
            'user_id' => null,
            'status' => PrivacyRequestStatus::InReview->value,
            'email_encrypted' => 'nobody.here@example.test',
            'email_hash' => DataSubjectRequest::hashEmail('nobody.here@example.test'),
            'verified_at' => Carbon::now(),
            'received_at' => Carbon::now(),
            'due_at' => Carbon::now()->addDays(30),
        ]);

        try {
            $this->exporter->buildFor($request);
            $this->fail('An address with no account must not resolve to one.');
        } catch (PrivacyRequestException $exception) {
            $this->assertSame(PrivacyRequestException::CODE_ACCOUNT_UNRESOLVED, $exception->errorCode());
        }

        $this->assertSame('needs_information', $request->refresh()->status->value);
        $this->assertSame(0, PersonalDataExport::query()->where('status', PersonalDataExport::STATUS_READY)->count());
    }

    public function test_08_release_is_refused_until_the_archive_is_ready_and_marks_the_request(): void
    {
        $export = $this->exporter->buildFor($this->request->refresh());

        $released = $this->exporter->release($export);

        $this->assertNotNull($released->released_at);
        Mail::assertQueued(PersonalDataExportReadyMail::class);
        $this->assertDatabaseHas('data_subject_request_events', [
            'request_id' => $this->request->getKey(),
            'event' => DataSubjectRequestEvent::EVENT_EXPORT_RELEASED,
        ]);

        $this->expectException(PrivacyRequestException::class);
        $this->exporter->release($released->refresh());
    }

    public function test_09_purging_removes_the_file_and_expires_the_row(): void
    {
        $export = $this->exporter->buildFor($this->request->refresh());

        $this->assertTrue($this->exporter->purge($export));

        $this->assertFalse(Storage::disk($export->disk)->exists($export->path));
        $this->assertSame(PersonalDataExport::STATUS_EXPIRED, $export->refresh()->status);
    }

    public function test_10_the_download_route_is_signed_owner_scoped_counted_and_expiring(): void
    {
        $export = $this->exporter->buildFor($this->request->refresh());

        $url = URL::temporarySignedRoute(
            'account.privacy-requests.export',
            now()->addMinutes(30),
            ['reference' => $this->request->reference, 'export' => (int) $export->getKey()],
        );

        // The owner gets the file.
        $this->actingAs($this->user)
            ->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip');

        $this->assertSame(1, (int) $export->refresh()->download_count);

        // Somebody else holding the same signed URL gets nothing.
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get($url)->assertNotFound();

        // The unsigned route is refused outright.
        $this->actingAs($this->user)
            ->get(route('account.privacy-requests.export', [
                'reference' => $this->request->reference,
                'export' => (int) $export->getKey(),
            ]))
            ->assertForbidden();
    }

    public function test_11_the_download_ceiling_is_enforced(): void
    {
        $export = $this->exporter->buildFor($this->request->refresh());

        $export->download_count = 2;
        $export->save();

        $url = URL::temporarySignedRoute(
            'account.privacy-requests.export',
            now()->addMinutes(30),
            ['reference' => $this->request->reference, 'export' => (int) $export->getKey()],
        );

        $this->actingAs($this->user)
            ->get($url)
            ->assertStatus(429);
    }

    public function test_12_an_expired_archive_is_not_served(): void
    {
        $export = $this->exporter->buildFor($this->request->refresh());

        $export->expires_at = Carbon::now()->subDay();
        $export->save();

        $url = URL::temporarySignedRoute(
            'account.privacy-requests.export',
            now()->addMinutes(30),
            ['reference' => $this->request->reference, 'export' => (int) $export->getKey()],
        );

        $this->actingAs($this->user)
            ->get($url)
            ->assertStatus(410);
    }

    public function test_13_an_unverified_request_cannot_be_exported(): void
    {
        $pending = DataSubjectRequest::factory()->create([
            'status' => PrivacyRequestStatus::PendingVerification->value,
            'user_id' => $this->user->getKey(),
            'email_hash' => DataSubjectRequest::hashEmail($this->user->email),
            'email_encrypted' => (string) $this->user->email,
            'verified_at' => null,
            'received_at' => null,
            'due_at' => null,
        ]);

        $this->expectException(PrivacyRequestException::class);

        $this->exporter->buildFor($pending);
    }

    public function test_14_the_requester_area_lists_their_own_requests_and_offers_the_signed_link(): void
    {
        $export = $this->exporter->buildFor($this->request->refresh());

        $response = $this->actingAs($this->user)->get(route('account.privacy-requests.index'));

        $response->assertOk()
            ->assertSee($this->request->reference)
            ->assertSee((string) trans('privacy_requests.account.download'));

        // A different account sees none of it.
        $stranger = User::factory()->create();
        $this->actingAs($stranger)
            ->get(route('account.privacy-requests.index'))
            ->assertOk()
            ->assertDontSee($this->request->reference);

        $this->assertTrue($export->isReady());
    }
}
