<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Enums\UserStatus;
use App\Enums\GloDealerRequestStatus;
use App\Enums\GloDealerRequestType;
use App\Models\GloDealerChangeRequest;
use App\Models\User;
use App\Services\Lottery\GloDealerChangeRequestService;
use App\Services\Lottery\GloDealerService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * GLO-15 change request: submit/review/approve/reject/audit + self-approve ban.
 */
class GloDealerChangeRequestTest extends TestCase
{
    use DatabaseTruncation;

    private User $dealerUser;

    private User $operator;

    private string $dealerToken;

    private string $operatorToken;

    private \App\Models\GloDealer $dealer;

    private GloDealerChangeRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->service = app(GloDealerChangeRequestService::class);
        $dealers = app(GloDealerService::class);

        $this->dealerUser = $this->freshUser()->create();
        $this->dealer = $dealers->ensureDealerForUser($this->dealerUser);
        $dealers->activate($this->dealer);
        $this->dealer->refresh();
        $this->dealerToken = $this->dealerUser->createToken('api')->plainTextToken;

        $this->operator = $this->freshUser()->create();
        $this->operator->syncRoles(['admin']);
        $this->operatorToken = $this->operator->createToken('api')->plainTextToken;
    }

    public function test_submit_review_approve_updates_profile(): void
    {
        $row = $this->service->submit(
            $this->dealer,
            $this->dealerUser,
            GloDealerRequestType::Name,
            'New Dealer Name',
            'legal name change',
        );

        $this->assertSame(GloDealerRequestStatus::Submitted, $row->status);
        $this->assertNotEmpty($row->audit_fingerprint);

        $row = $this->service->startReview($row, $this->operator);
        $this->assertSame(GloDealerRequestStatus::UnderReview, $row->status);

        $row = $this->service->approve($row, $this->operator, 'ok');
        $this->assertSame(GloDealerRequestStatus::Approved, $row->status);

        $this->dealer->refresh();
        $this->assertSame('New Dealer Name', $this->dealer->display_name);
    }

    public function test_reject_does_not_mutate_profile(): void
    {
        $this->dealer->address = 'Before';
        $this->dealer->save();

        $row = $this->service->submit(
            $this->dealer,
            $this->dealerUser,
            GloDealerRequestType::Address,
            '123 New Rd',
        );
        $row = $this->service->startReview($row, $this->operator);
        $row = $this->service->reject($row, $this->operator, 'evidence missing');

        $this->assertSame(GloDealerRequestStatus::Rejected, $row->status);
        $this->dealer->refresh();
        $this->assertSame('Before', $this->dealer->address);
    }

    public function test_direct_submit_to_approved_is_forbidden(): void
    {
        $row = $this->service->submit(
            $this->dealer,
            $this->dealerUser,
            GloDealerRequestType::Phone,
            '+66800000001',
        );

        try {
            $this->service->approve($row, $this->operator);
            $this->fail('submitted → approved must require under_review first');
        } catch (\App\Exceptions\GloDealerException $e) {
            $this->assertStringContainsString('Illegal', $e->getMessage());
        }
    }

    public function test_dealer_cannot_approve_own_request(): void
    {
        $row = $this->service->submit(
            $this->dealer,
            $this->dealerUser,
            GloDealerRequestType::Name,
            'Self Approved',
        );

        try {
            $this->service->startReview($row, $this->dealerUser);
            $this->fail('dealer must not review own request');
        } catch (\App\Exceptions\GloDealerException $e) {
            $this->assertStringContainsString('own change request', $e->getMessage());
        }
    }

    public function test_duplicate_open_request_rejected(): void
    {
        $this->service->submit($this->dealer, $this->dealerUser, GloDealerRequestType::Name, 'First');

        try {
            $this->service->submit($this->dealer, $this->dealerUser, GloDealerRequestType::Name, 'Second');
            $this->fail('duplicate open request must 409');
        } catch (\App\Exceptions\GloDealerException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }
    }

    public function test_api_submit_and_list_own_requests(): void
    {
        $this->withToken($this->dealerToken)
            ->postJson('/api/v1/glo/dealer/change-requests', [
                'request_type' => 'phone',
                'requested_value' => '+66811111111',
                'reason' => 'new number',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'submitted');

        $this->withToken($this->dealerToken)
            ->getJson('/api/v1/glo/dealer/change-requests')
            ->assertOk()
            ->assertJsonPath('data.0.request_type', 'phone');
    }

    public function test_api_operator_approve_endpoint(): void
    {
        $row = $this->service->submit(
            $this->dealer,
            $this->dealerUser,
            GloDealerRequestType::SalesLocation,
            'Market Stall|Bangkok|Khlong Toei',
        );

        $this->withToken($this->operatorToken)
            ->postJson('/api/v1/glo/operator/dealer-requests/'.$row->request_reference.'/approve', [
                'review_note' => 'verified',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->dealer->refresh();
        $this->assertSame('Market Stall', $this->dealer->sales_location);
        $this->assertSame('Bangkok', $this->dealer->province);
    }

    public function test_api_operator_reject_endpoint(): void
    {
        $row = $this->service->submit(
            $this->dealer,
            $this->dealerUser,
            GloDealerRequestType::Phone,
            '+66822222222',
        );

        $this->withToken($this->operatorToken)
            ->postJson('/api/v1/glo/operator/dealer-requests/'.$row->request_reference.'/reject', [
                'review_note' => 'no proof',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    }

    public function test_api_operator_forbidden_for_non_permission_user(): void
    {
        $row = $this->service->submit(
            $this->dealer,
            $this->dealerUser,
            GloDealerRequestType::Name,
            'X',
        );

        $player = $this->freshUser()->create();
        $player->syncRoles(['player']);
        $playerToken = $player->createToken('api')->plainTextToken;

        $this->withToken($playerToken)
            ->postJson('/api/v1/glo/operator/dealer-requests/'.$row->request_reference.'/approve')
            ->assertStatus(403);
    }

    public function test_api_player_cannot_list_operator_requests(): void
    {
        $player = $this->freshUser()->create();
        $player->syncRoles(['player']);
        $playerToken = $player->createToken('api')->plainTextToken;

        $this->withToken($playerToken)
            ->getJson('/api/v1/glo/operator/dealer-requests')
            ->assertStatus(403);
    }

    public function test_audit_log_written_on_submit(): void
    {
        $row = $this->service->submit(
            $this->dealer,
            $this->dealerUser,
            GloDealerRequestType::Name,
            'Audited Name',
        );

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => GloDealerChangeRequest::class,
            'auditable_id' => $row->getKey(),
            'description' => 'glo_dealer_request_submitted',
        ]);
    }

    public function test_another_dealer_cannot_submit_for_this_dealer(): void
    {
        $stranger = $this->freshUser()->create();

        try {
            $this->service->submit($this->dealer, $stranger, GloDealerRequestType::Name, 'Hack');
            $this->fail('cross-dealer submit must be forbidden');
        } catch (\App\Exceptions\GloDealerException $e) {
            $this->assertStringContainsString('Not authorized', $e->getMessage());
        }
    }

    public function test_invalid_phone_rejected(): void
    {
        try {
            $this->service->submit($this->dealer, $this->dealerUser, GloDealerRequestType::Phone, 'not-a-phone!');
            $this->fail('invalid phone must 422');
        } catch (\App\Exceptions\GloDealerException $e) {
            $this->assertStringContainsString('phone', $e->getMessage());
        }
    }

    private function freshUser(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return User::factory()->state(fn (): array => [
            'email' => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(12)).'.'.bin2hex(random_bytes(4)).'@glofreeze.local',
            'username' => 'gz'.\Illuminate\Support\Str::random(10),
            'phone' => '+8801'.random_int(100_000_000, 999_999_999),
            'status' => UserStatus::Active,
        ]);
    }
}
