<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Enums\GloFreezeStatus;
use App\Enums\UserStatus;
use App\Exceptions\GloFreezeException;
use App\Models\Draw;
use App\Models\GloTicket;
use App\Models\GloTicketFreeze;
use App\Models\User;
use App\Services\Lottery\GloTicketFreezeService;
use App\Support\Admin\AdminAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GLO-11 ticket freeze / seizure state machine.
 *
 * Mandatory: requested→under_review→frozen; requested→rejected; frozen→released/expired;
 * forbidden requested→paid and rejected→frozen without reopen; evidence before final
 * freeze; exact ticket match (leading zeros preserved, no int-cast, no mass freeze);
 * idempotent fingerprint; multiple active freezes block payment until ALL released;
 * expiry = audited transition; secure evidence = IDs/hashes only.
 */
class GloTicketFreezeTest extends TestCase
{
    use DatabaseTruncation;

    private GloTicketFreezeService $service;

    private User $operator;

    private string $token;

    private Draw $draw;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->service = app(GloTicketFreezeService::class);

        $this->operator = $this->freshUser()->create();
        $this->operator->syncRoles(['admin']);
        $this->token = $this->operator->createToken('api')->plainTextToken;

        $this->draw = Draw::factory()->create([
            'status' => 'result_published',
            'scheduled_at' => now()->subDays(10),
            'result_published_at' => now()->subDays(9),
        ]);
    }

    public function test_freeze_lifecycle_requested_review_frozen_release(): void
    {
        $freeze = $this->makeFreezeRequest();

        $this->assertSame(GloFreezeStatus::Requested, $freeze->status);

        $freeze = $this->service->startReview($freeze, $this->operator);
        $this->assertSame(GloFreezeStatus::UnderReview, $freeze->status);

        $freeze = $this->service->approveToFrozen($freeze, $this->operator, 'evidence complete');
        $this->assertSame(GloFreezeStatus::Frozen, $freeze->status);
        $this->assertNotNull($freeze->effective_at);
        $this->assertTrue($this->service->hasActiveEffectiveFreeze((int) $freeze->ticket_id));

        $freeze = $this->service->release($freeze, $this->operator, 'case closed');
        $this->assertSame(GloFreezeStatus::Released, $freeze->status);
        $this->assertFalse($this->service->hasActiveEffectiveFreeze((int) $freeze->ticket_id));
    }

    public function test_requested_to_rejected_and_rejected_cannot_frozen_without_reopen(): void
    {
        $freeze = $this->makeFreezeRequest();

        $freeze = $this->service->reject($freeze, $this->operator, 'insufficient standing');
        $this->assertSame(GloFreezeStatus::Rejected, $freeze->status);

        try {
            $this->service->approveToFrozen($freeze, $this->operator);
            $this->fail('rejected → frozen must be forbidden without reopen');
        } catch (GloFreezeException $e) {
            $this->assertStringContainsString('Illegal freeze transition', $e->getMessage());
        }

        // Authorized reopen path: rejected → requested → under_review → frozen.
        $freeze = $this->service->reopen($freeze, $this->operator, 'new warrant attached');
        $this->assertSame(GloFreezeStatus::Requested, $freeze->status);
        $freeze = $this->service->startReview($freeze, $this->operator);
        $freeze = $this->service->approveToFrozen($freeze, $this->operator);
        $this->assertSame(GloFreezeStatus::Frozen, $freeze->status);
    }

    public function test_requested_directly_to_frozen_is_forbidden(): void
    {
        $freeze = $this->makeFreezeRequest();

        try {
            $this->service->approveToFrozen($freeze, $this->operator);
            $this->fail('requested → frozen must require under_review first');
        } catch (GloFreezeException) {
            $this->assertTrue(true);
        }
    }

    public function test_final_freeze_requires_complete_evidence(): void
    {
        $freeze = $this->makeFreezeRequest();

        // Corrupt evidence to empty via model (simulating partial intake).
        $freeze->evidence_reference = '';
        $freeze->save();

        $freeze = $this->service->startReview($freeze, $this->operator);

        try {
            $this->service->approveToFrozen($freeze, $this->operator);
            $this->fail('freeze without evidence must be refused');
        } catch (GloFreezeException $e) {
            $this->assertStringContainsString('evidence', strtolower($e->getMessage()));
        }

        $this->assertSame(GloFreezeStatus::UnderReview, $freeze->fresh()->status);
    }

    public function test_exact_ticket_match_preserves_leading_zero_and_rejects_mass_freeze(): void
    {
        // Six-digit string with leading zero — must never int-cast.
        $freeze = $this->makeFreezeRequest(['ticket_number' => '007321']);

        $this->assertSame('007321', $freeze->ticket_number);
        $this->assertSame('007321', $freeze->ticket->ticket_number);
        $this->assertStringContainsString('-007321', (string) $freeze->ticket->ticket_reference);

        // Wrong digit count refused.
        try {
            $this->makeFreezeRequest(['ticket_number' => '7321']);
            $this->fail('non-6-digit L6 number must be refused');
        } catch (GloFreezeException) {
            $this->assertTrue(true);
        }

        // Only one freeze case exists for this exact ticket — no mass freeze.
        $this->assertSame(1, GloTicketFreeze::query()->where('ticket_id', $freeze->ticket_id)->count());
        $this->assertSame(1, GloTicket::query()->count());
    }

    public function test_idempotent_fingerprint_replay_is_stable(): void
    {
        $payload = $this->freezePayload();

        $a = $this->service->requestFreeze($payload, $this->operator);
        $b = $this->service->requestFreeze($payload, $this->operator);

        $this->assertSame($a->id, $b->id);
        $this->assertSame($a->fingerprint, $b->fingerprint);
        $this->assertSame(1, GloTicketFreeze::query()->count());

        // Distinct case reference → distinct freeze row (not collapsed).
        $c = $this->service->requestFreeze(array_merge($payload, [
            'case_reference' => 'CASE-OTHER-999',
            'evidence_reference' => 'EVD-OTHER-999',
        ]), $this->operator);

        $this->assertNotSame($a->id, $c->id);
        $this->assertSame(2, GloTicketFreeze::query()->count());
    }

    public function test_multiple_active_freezes_block_until_all_released(): void
    {
        $ticketNumber = '112233';

        $f1 = $this->service->requestFreeze($this->freezePayload([
            'ticket_number' => $ticketNumber,
            'case_reference' => 'CASE-A1',
            'evidence_reference' => 'EVD-A1',
        ]), $this->operator);
        $f1 = $this->service->startReview($f1, $this->operator);
        $f1 = $this->service->approveToFrozen($f1, $this->operator);

        $f2 = $this->service->requestFreeze($this->freezePayload([
            'ticket_number' => $ticketNumber,
            'case_reference' => 'CASE-B2',
            'evidence_reference' => 'EVD-B2',
        ]), $this->operator);
        $f2 = $this->service->startReview($f2, $this->operator);
        $f2 = $this->service->approveToFrozen($f2, $this->operator);

        $this->assertTrue($this->service->hasActiveEffectiveFreeze((int) $f1->ticket_id));

        $this->service->release($f1, $this->operator, 'case A closed');
        $this->assertTrue(
            $this->service->hasActiveEffectiveFreeze((int) $f1->ticket_id),
            'Second active freeze must still block payment after first release',
        );

        $this->service->release($f2, $this->operator, 'case B closed');
        $this->assertFalse($this->service->hasActiveEffectiveFreeze((int) $f1->ticket_id));
    }

    public function test_expiry_is_audited_transition_and_keeps_history(): void
    {
        $freeze = $this->makeFreezeRequest([
            'case_reference' => 'CASE-EXP-1',
            'evidence_reference' => 'EVD-EXP-1',
            'expiry_at' => now()->addDay()->toIso8601String(),
        ]);
        $freeze = $this->service->startReview($freeze, $this->operator);
        $freeze = $this->service->approveToFrozen($freeze, $this->operator);

        // Not yet due → expire refused.
        try {
            $this->service->expire($freeze, $this->operator);
            $this->fail('unexpired freeze must not expire early');
        } catch (GloFreezeException) {
            $this->assertTrue(true);
        }

        // Force due and sweep.
        $freeze->expiry_at = now()->subHour();
        $freeze->save();

        $result = $this->service->expireDueFreezes($this->operator);
        $this->assertSame(1, $result['expired']);

        $fresh = $freeze->fresh();
        $this->assertSame(GloFreezeStatus::Expired, $fresh->status);
        $this->assertNotNull($fresh->expired_at);

        // History row still exists with original evidence references.
        $this->assertDatabaseHas('glo_ticket_freezes', [
            'id' => $freeze->id,
            'status' => GloFreezeStatus::Expired->value,
            'evidence_reference' => 'EVD-EXP-1',
        ]);

        $this->assertFalse($this->service->hasActiveEffectiveFreeze((int) $freeze->ticket_id));
    }

    public function test_api_freeze_routes_default_deny_and_enforce_permissions(): void
    {
        // Unauthenticated → 401.
        $this->postJson('/api/v1/glo/tickets/1/freeze', [])->assertStatus(401);

        // Authenticated player without freeze permission → 403.
        $player = $this->freshUser()->create();
        $player->syncRoles(['player']);
        $playerToken = $player->createToken('api')->plainTextToken;

        $this->apiAs($playerToken, 'POST', '/api/v1/glo/tickets/1/freeze', $this->freezePayload())
            ->assertStatus(403);

        // Operator with permission can request freeze.
        $ticket = GloTicket::create([
            'draw_id' => $this->draw->id,
            'product' => 'l6',
            'ticket_number' => '554433',
            'set_series' => null,
            'owner_user_id' => null,
            'ticket_reference' => GloTicket::buildReference((int) $this->draw->id, 'l6', '554433'),
            'metadata' => [],
        ]);

        $body = $this->freezePayload([
            'ticket_number' => '554433',
            'case_reference' => 'CASE-API-1',
            'evidence_reference' => 'EVD-API-1',
        ]);

        $response = $this->apiAs($this->token, 'POST', '/api/v1/glo/tickets/'.$ticket->id.'/freeze', $body);
        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'requested');
        $response->assertJsonPath('data.ticket_number', '554433');

        // Review + approve via API (admin has review glo freezes).
        $caseId = $response->json('data.freeze_case_id');

        $this->apiAs($this->token, 'POST', '/api/v1/glo/freezes/'.$caseId.'/review', [])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'under_review');

        $this->apiAs($this->token, 'POST', '/api/v1/glo/freezes/'.$caseId.'/approve', [])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'frozen');

        // Auditor may view freezes but not transition (admin matrix: auditor only view).
        $auditor = $this->freshUser()->create();
        $auditor->syncRoles(['auditor']);
        $auditorToken = $auditor->createToken('api')->plainTextToken;

        $this->apiAs($auditorToken, 'POST', '/api/v1/glo/freezes/'.$caseId.'/release', [
            'reason' => 'should deny',
        ])->assertStatus(403);
    }

    public function test_freeze_never_accepts_request_supplied_trust_fields(): void
    {
        $ticket = GloTicket::create([
            'draw_id' => $this->draw->id,
            'product' => 'l6',
            'ticket_number' => '900001',
            'set_series' => null,
            'owner_user_id' => null,
            'ticket_reference' => GloTicket::buildReference((int) $this->draw->id, 'l6', '900001'),
            'metadata' => [],
        ]);

        $body = $this->freezePayload([
            'ticket_number' => '900001',
            'case_reference' => 'CASE-TRUST',
            'evidence_reference' => 'EVD-TRUST',
        ]);
        // Hostile payload: client claims already frozen / paid.
        $body['status'] = 'frozen';
        $body['is_frozen'] = true;
        $body['payment_status'] = 'paid';

        $response = $this->apiAs($this->token, 'POST', '/api/v1/glo/tickets/'.$ticket->id.'/freeze', $body);
        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'requested');

        $this->assertSame(
            GloFreezeStatus::Requested,
            GloTicketFreeze::query()->where('ticket_id', $ticket->id)->firstOrFail()->status,
        );
    }

    /* --------------------------------------------------------- helpers */

    private function freezePayload(array $overrides = []): array
    {
        return array_merge([
            'draw_id' => (int) $this->draw->id,
            'product' => 'l6',
            'ticket_number' => '123456',
            'set_series' => null,
            'requesting_authority' => 'Royal Thai Police',
            'jurisdiction' => 'Bangkok',
            'case_reference' => 'CASE-REF-001',
            'evidence_reference' => 'EVD-REF-001',
            'evidence_type' => 'official_notice',
            'evidence_document_id' => 'SECDOC-abc123',
            'evidence_content_hash' => hash('sha256', 'demo-evidence-bytes'),
            'evidence_mime_type' => 'application/pdf',
        ], $overrides);
    }

    private function makeFreezeRequest(array $overrides = []): GloTicketFreeze
    {
        return $this->service->requestFreeze($this->freezePayload($overrides), $this->operator);
    }

    private function apiAs(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $data);
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
