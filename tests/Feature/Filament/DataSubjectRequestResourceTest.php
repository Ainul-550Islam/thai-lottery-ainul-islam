<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\PrivacyRequestStatus;
use App\Enums\PrivacyRequestType;
use App\Exceptions\Privacy\PrivacyRequestException;
use App\Filament\Resources\DataSubjectRequestResource;
use App\Filament\Resources\DataSubjectRequestResource\DataSubjectRequestActions;
use App\Filament\Resources\DataSubjectRequestResource\Pages\ListDataSubjectRequests;
use App\Filament\Resources\DataSubjectRequestResource\Pages\ViewDataSubjectRequest;
use App\Models\DataSubjectRequest;
use App\Models\DataSubjectRequestEvent;
use App\Models\User;
use App\Services\Privacy\PrivacyRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The compliance queue, exercised the way an operator uses it.
 *
 * WHAT THIS FILE IS PROTECTING
 *  - The two staff moves that start the clock (Acknowledge) and the work
 *    (Start review) are distinct, and each is offered only in its own state.
 *  - A request cannot be closed from receipt: the service refuses, and the
 *    screen does not offer it.
 *  - Erasure and restriction are REVIEWED: the retention check is the only path,
 *    and it never deletes anything.
 *  - Opening a record writes an audit row, because that screen is where the
 *    decrypted words are read.
 *  - A requester cannot reach the panel, and no screen offers create/edit/delete.
 */
final class DataSubjectRequestResourceTest extends TestCase
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

        config()->set('privacy_requests.enabled', true);
        config()->set('privacy_requests.compliance_email', 'compliance@example.test');
    }

    private function operator(string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    private function request(PrivacyRequestStatus $status = PrivacyRequestStatus::Received, PrivacyRequestType $type = PrivacyRequestType::Access): DataSubjectRequest
    {
        return DataSubjectRequest::factory()->create([
            'status' => $status->value,
            'type' => $type,
            'verified_at' => now(),
            'received_at' => now(),
            'due_at' => now()->addDays(30),
        ]);
    }

    // =====================================================================
    // Reads
    // =====================================================================

    public function test_01_the_queue_and_a_record_render_for_an_operator(): void
    {
        $request = $this->request();
        $admin = $this->operator();

        $this->actingAs($admin)->get(DataSubjectRequestResource::getUrl('index'))->assertSuccessful();

        Livewire::actingAs($admin)
            ->test(ListDataSubjectRequests::class, ['activeTab' => 'all'])
            ->assertOk()
            ->assertSee($request->reference);

        $this->actingAs($admin)
            ->get(DataSubjectRequestResource::getUrl('view', ['record' => $request]))
            ->assertSuccessful()
            ->assertSee($request->reference);
    }

    public function test_02_opening_a_record_is_audited(): void
    {
        $request = $this->request();

        $this->actingAs($this->operator())
            ->get(DataSubjectRequestResource::getUrl('view', ['record' => $request]))
            ->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => DataSubjectRequest::class,
            'auditable_id' => $request->getKey(),
            'action' => 'data_export',
        ]);
    }

    // =====================================================================
    // The two opening moves
    // =====================================================================

    public function test_03_acknowledge_takes_a_new_request_into_review(): void
    {
        $request = $this->request(PrivacyRequestStatus::Received);

        Livewire::actingAs($this->operator())
            ->test(ListDataSubjectRequests::class, ['activeTab' => 'all'])
            ->assertTableActionVisible('acknowledge', record: $request)
            ->callTableAction('acknowledge', record: $request)
            ->assertHasNoTableActionErrors();

        $this->assertSame(PrivacyRequestStatus::InReview, $request->refresh()->status);
        $this->assertDatabaseHas('data_subject_request_events', [
            'request_id' => $request->getKey(),
            'to_status' => PrivacyRequestStatus::InReview->value,
            'actor_type' => DataSubjectRequestEvent::ACTOR_STAFF,
        ]);
    }

    public function test_04_start_review_moves_an_acknowledged_request_into_progress(): void
    {
        $request = $this->request(PrivacyRequestStatus::InReview);

        Livewire::actingAs($this->operator())
            ->test(ListDataSubjectRequests::class, ['activeTab' => 'all'])
            ->assertTableActionHidden('acknowledge', record: $request)
            ->assertTableActionVisible('start_review', record: $request)
            ->callTableAction('start_review', record: $request)
            ->assertHasNoTableActionErrors();

        $this->assertSame(PrivacyRequestStatus::InProgress, $request->refresh()->status);
    }

    public function test_05_asking_for_information_requires_a_note_and_records_it(): void
    {
        $request = $this->request(PrivacyRequestStatus::InReview);

        Livewire::actingAs($this->operator())
            ->test(ListDataSubjectRequests::class, ['activeTab' => 'all'])
            ->callTableAction('request_information', record: $request, data: ['note' => 'x'])
            ->assertHasTableActionErrors(['note']);

        $this->assertSame(PrivacyRequestStatus::InReview, $request->refresh()->status);

        Livewire::actingAs($this->operator())
            ->test(ListDataSubjectRequests::class, ['activeTab' => 'all'])
            ->callTableAction('request_information', record: $request, data: [
                'note' => 'Which account should this cover? Please reply from the address you wrote from.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(PrivacyRequestStatus::NeedsInformation, $request->refresh()->status);
    }

    // =====================================================================
    // Erasure is reviewed, never automatic
    // =====================================================================

    public function test_06_the_retention_check_is_offered_only_for_erasure_and_restriction(): void
    {
        $access = $this->request(PrivacyRequestStatus::InReview, PrivacyRequestType::Access);
        $erasure = $this->request(PrivacyRequestStatus::InReview, PrivacyRequestType::Erasure);

        Livewire::actingAs($this->operator())
            ->test(ListDataSubjectRequests::class, ['activeTab' => 'all'])
            ->assertTableActionHidden('retention_check', record: $access)
            ->assertTableActionVisible('retention_check', record: $erasure);
    }

    public function test_07_the_retention_check_records_its_verdict_and_deletes_nothing(): void
    {
        $user = User::factory()->create();
        $request = DataSubjectRequest::factory()->create([
            'user_id' => (int) $user->getKey(),
            'email_hash' => DataSubjectRequest::hashEmail((string) $user->email),
            'type' => PrivacyRequestType::Erasure,
            'status' => PrivacyRequestStatus::InReview,
            'verified_at' => now(),
            'received_at' => now(),
            'due_at' => now()->addDays(30),
        ]);

        Livewire::actingAs($this->operator())
            ->test(ListDataSubjectRequests::class, ['activeTab' => 'all'])
            ->callTableAction('retention_check', record: $request)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('data_subject_request_events', [
            'request_id' => $request->getKey(),
            'event' => DataSubjectRequestEvent::EVENT_RETENTION_CHECK,
        ]);

        // Nothing was removed: the user is still there and the request is open.
        $this->assertDatabaseHas('users', ['id' => $user->getKey()]);
        $this->assertSame(PrivacyRequestStatus::InReview, $request->refresh()->status);
    }

    // =====================================================================
    // Export: built by staff, released by staff
    // =====================================================================

    public function test_08_building_an_export_is_offered_only_where_the_service_could_accept_it(): void
    {
        $access = $this->request(PrivacyRequestStatus::InReview, PrivacyRequestType::Access);
        $pending = DataSubjectRequest::factory()->create([
            'status' => PrivacyRequestStatus::PendingVerification->value,
            'type' => PrivacyRequestType::Access,
            'verified_at' => null,
            'received_at' => null,
            'due_at' => null,
        ]);

        Livewire::actingAs($this->operator())
            ->test(ListDataSubjectRequests::class, ['activeTab' => 'all'])
            ->assertTableActionVisible('build_export', record: $access)
            ->assertTableActionHidden('build_export', record: $pending);
    }

    public function test_09_release_is_offered_only_once_an_archive_exists(): void
    {
        $request = $this->request(PrivacyRequestStatus::InProgress, PrivacyRequestType::Access);

        Livewire::actingAs($this->operator())
            ->test(ListDataSubjectRequests::class, ['activeTab' => 'all'])
            ->assertTableActionHidden('release_export', record: $request);
    }

    // =====================================================================
    // Refusals
    // =====================================================================

    public function test_10_a_completed_request_offers_no_further_move_and_the_service_refuses_one(): void
    {
        $request = $this->request(PrivacyRequestStatus::InReview);
        $service = app(PrivacyRequestService::class);

        $service->transition($request, PrivacyRequestStatus::InProgress, DataSubjectRequestEvent::ACTOR_STAFF);
        $service->transition($request->refresh(), PrivacyRequestStatus::Completed, DataSubjectRequestEvent::ACTOR_STAFF, null, 'answered in full');

        Livewire::actingAs($this->operator())
            ->test(ListDataSubjectRequests::class, ['activeTab' => 'all'])
            ->assertTableActionHidden('acknowledge', record: $request->refresh())
            ->assertTableActionHidden('start_review', record: $request->refresh());

        try {
            $service->transition($request->refresh(), PrivacyRequestStatus::InReview, DataSubjectRequestEvent::ACTOR_STAFF);
            $this->fail('A completed request must not re-enter review.');
        } catch (PrivacyRequestException $exception) {
            $this->assertSame(PrivacyRequestException::CODE_INVALID_TRANSITION, $exception->errorCode());
        }

        $this->assertSame('completed', $request->refresh()->status->value);
    }

    public function test_11_completion_requires_a_written_answer(): void
    {
        $request = $this->request(PrivacyRequestStatus::InProgress);

        Livewire::actingAs($this->operator())
            ->test(ListDataSubjectRequests::class, ['activeTab' => 'all'])
            ->callTableAction('complete', record: $request, data: ['note' => 'short'])
            ->assertHasTableActionErrors(['note']);

        $this->assertSame(PrivacyRequestStatus::InProgress, $request->refresh()->status);

        Livewire::actingAs($this->operator())
            ->test(ListDataSubjectRequests::class, ['activeTab' => 'all'])
            ->callTableAction('complete', record: $request, data: ['note' => 'Sent the archive and the explanation of what was withheld.'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(PrivacyRequestStatus::Completed, $request->refresh()->status);
        $this->assertNotNull($request->completed_at);
    }

    public function test_12_rejection_requires_a_reason_code_and_a_note(): void
    {
        $request = $this->request(PrivacyRequestStatus::InReview);

        Livewire::actingAs($this->operator())
            ->test(ListDataSubjectRequests::class, ['activeTab' => 'all'])
            ->callTableAction('reject', record: $request, data: ['reason_code' => ''])
            ->assertHasTableActionErrors(['reason_code']);

        $this->assertSame(PrivacyRequestStatus::InReview, $request->refresh()->status);
    }

    // =====================================================================
    // Layout and authorization
    // =====================================================================

    public function test_13_the_resource_offers_no_create_edit_or_delete(): void
    {
        $request = $this->request();

        Auth::login($this->operator('super-admin'));

        $this->assertFalse(DataSubjectRequestResource::canCreate());
        $this->assertFalse(DataSubjectRequestResource::canDeleteAny());
        $this->assertFalse(DataSubjectRequestResource::canEdit($request));
        $this->assertFalse(DataSubjectRequestResource::canDelete($request));
        $this->assertArrayNotHasKey('create', DataSubjectRequestResource::getPages());
        $this->assertArrayNotHasKey('edit', DataSubjectRequestResource::getPages());
    }

    public function test_14_a_player_cannot_reach_the_queue_or_act(): void
    {
        $request = $this->request();
        $player = $this->operator('player');

        $this->actingAs($player)->get(DataSubjectRequestResource::getUrl('index'))->assertForbidden();
        $this->actingAs($player)->get(DataSubjectRequestResource::getUrl('view', ['record' => $request]))->assertForbidden();

        Auth::login($player);

        $this->assertFalse(Gate::allows('view', $request));
        $this->assertFalse(Gate::allows('update', $request));
        $this->assertFalse(Gate::allows('delete', $request));
    }

    public function test_15_an_auditor_may_read_but_is_offered_no_write(): void
    {
        $request = $this->request(PrivacyRequestStatus::Received);
        $auditor = $this->operator('auditor');

        $this->actingAs($auditor)->get(DataSubjectRequestResource::getUrl('index'))->assertSuccessful();

        Auth::login($auditor);

        $this->assertTrue(Gate::allows('view', $request));

        foreach (DataSubjectRequestActions::forTable() as $action) {
            $action->record($request);
            $this->assertFalse($action->isVisible(), "An auditor was offered [{$action->getName()}].");
        }
    }

    public function test_16_the_record_page_shows_the_timeline_of_what_happened(): void
    {
        $request = $this->request(PrivacyRequestStatus::InReview);

        app(PrivacyRequestService::class)->transition(
            $request,
            PrivacyRequestStatus::InProgress,
            DataSubjectRequestEvent::ACTOR_STAFF,
            null,
            'working through the ledger extract',
        );

        $this->actingAs($this->operator())
            ->get(DataSubjectRequestResource::getUrl('view', ['record' => $request->refresh()]))
            ->assertSuccessful()
            ->assertSee((string) trans('privacy_requests.statuses.in_progress'), false);
    }

    // =====================================================================
    // The retention gate, from the screen
    // =====================================================================

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
            'amount' => '250.00',
            'fee' => '0.00',
            'description' => 'A retained deposit',
            'processed_at' => now()->subMonth(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function erasureFor(User $user): DataSubjectRequest
    {
        return DataSubjectRequest::factory()->create([
            'user_id' => (int) $user->getKey(),
            'email_encrypted' => (string) $user->email,
            'email_hash' => DataSubjectRequest::hashEmail((string) $user->email),
            'type' => PrivacyRequestType::Erasure,
            'status' => PrivacyRequestStatus::InProgress->value,
            'verified_at' => now(),
            'received_at' => now(),
            'due_at' => now()->addDays(20),
        ]);
    }

    public function test_17_a_blocked_erasure_cannot_be_closed_from_the_screen_either(): void
    {
        $user = $this->userWithRetainedRecords();
        $request = $this->erasureFor($user);

        Livewire::actingAs($this->operator())
            ->test(ListDataSubjectRequests::class, ['activeTab' => 'all'])
            ->callTableAction('complete', record: $request, data: [
                'note' => 'Erased the profile as requested.',
            ])
            ->assertHasNoTableActionErrors();

        // The service refused; the notification said why; nothing moved.
        $this->assertSame(PrivacyRequestStatus::InProgress, $request->refresh()->status);
        $this->assertNull($request->completed_at);
        $this->assertDatabaseHas('users', ['id' => $user->getKey()]);
        $this->assertSame(0, DataSubjectRequestEvent::query()
            ->where('request_id', $request->getKey())
            ->where('event', DataSubjectRequestEvent::EVENT_RETENTION_CHECK)
            ->count());
    }

    public function test_18_a_written_justification_lets_the_screen_complete_it(): void
    {
        $user = $this->userWithRetainedRecords();
        $request = $this->erasureFor($user);

        Livewire::actingAs($this->operator())
            ->test(ListDataSubjectRequests::class, ['activeTab' => 'all'])
            ->callTableAction('complete', record: $request, data: [
                'note' => 'Erased the profile and pseudonymised the retained ledger lines.',
                'retention_override_reason' => 'Reviewed the retention report: the retained lines carry no direct identifiers after pseudonymisation.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(PrivacyRequestStatus::Completed, $request->refresh()->status);

        $this->assertDatabaseHas('data_subject_request_events', [
            'request_id' => $request->getKey(),
            'event' => DataSubjectRequestEvent::EVENT_RETENTION_CHECK,
        ]);
    }

    public function test_19_an_erasure_rejection_must_cite_the_retention_obligation(): void
    {
        $user = $this->userWithRetainedRecords();
        $request = $this->erasureFor($user);

        Livewire::actingAs($this->operator())
            ->test(ListDataSubjectRequests::class, ['activeTab' => 'all'])
            ->callTableAction('reject', record: $request, data: [
                'reason_code' => 'duplicate',
                'note' => 'We believe this is a duplicate of an earlier request.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(PrivacyRequestStatus::InProgress, $request->refresh()->status);

        Livewire::actingAs($this->operator())
            ->test(ListDataSubjectRequests::class, ['activeTab' => 'all'])
            ->callTableAction('reject', record: $request, data: [
                'reason_code' => PrivacyRequestService::RETENTION_REASON_CODE,
                'note' => 'Retained under the anti-money-laundering record-keeping rules for the statutory period.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(PrivacyRequestStatus::Rejected, $request->refresh()->status);
        $this->assertSame(PrivacyRequestService::RETENTION_REASON_CODE, $request->refresh()->rejected_reason_code);
    }
}
