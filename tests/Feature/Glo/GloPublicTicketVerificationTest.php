<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Enums\DrawStatus;
use App\Enums\GloFreezeStatus;
use App\Enums\GloPublicStatus;
use App\Enums\UserStatus;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloPublicTicketStatus;
use App\Models\GloTicket;
use App\Models\User;
use App\Services\Lottery\GloFrozenWinnerService;
use App\Services\Lottery\GloTicketFreezeService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GLO-13 public ticket verification: safe status vocabulary only, rate-limited
 * unauthenticated route, anti-enumeration, no PII / no internal ids / no tokens.
 */
class GloPublicTicketVerificationTest extends TestCase
{
    use DatabaseTruncation;

    private GloTicketFreezeService $freezes;

    private GloFrozenWinnerService $winners;

    private User $operator;

    private Draw $draw;

    private string $ticketNumber = '001234';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->freezes = app(GloTicketFreezeService::class);
        $this->winners = app(GloFrozenWinnerService::class);

        $this->operator = $this->freshUser()->create();
        $this->operator->syncRoles(['admin']);

        $this->draw = Draw::factory()->create([
            'status' => DrawStatus::Completed,
            'scheduled_at' => now()->subDays(12),
            'result_published_at' => now()->subDays(11),
            'completed_at' => now()->subDays(11),
        ]);

        DrawResult::create([
            'draw_id' => $this->draw->id,
            'first_prize' => $this->ticketNumber,
            'second_prize' => [],
            'third_prize' => [],
            'consolation_prizes' => [],
            'all_numbers' => [],
            'total_winners' => 1,
            'total_payout' => '6000000.00',
            'house_profit' => '0.00',
            'published_at' => now()->subDays(11),
            'metadata' => ['glo' => []],
        ]);
    }

    public function test_unknown_reference_returns_not_found(): void
    {
        $response = $this->getJson('/api/v1/glo/public/tickets/99-l6-999999/status');
        $response->assertStatus(200);
        $response->assertJsonPath('data.status', GloPublicStatus::NotFound->value);
    }

    public function test_malformed_reference_returns_not_found(): void
    {
        // Pattern requires {draw}-{l6|n3}-{number}.
        $response = $this->getJson('/api/v1/glo/public/tickets/not-a-valid/status');
        $response->assertStatus(200);
        $response->assertJsonPath('data.status', GloPublicStatus::NotFound->value);
    }

    public function test_frozen_ticket_returns_frozen_status(): void
    {
        $this->freezeTicket();

        $ref = GloTicket::buildReference((int) $this->draw->id, 'l6', $this->ticketNumber);

        $response = $this->getJson('/api/v1/glo/public/tickets/'.rawurlencode($ref).'/status');
        $response->assertStatus(200);
        $response->assertJsonPath('data.status', GloPublicStatus::Frozen->value);
        $response->assertJsonPath('data.ticket_reference', $ref);
    }

    public function test_frozen_winning_ticket_returns_payment_held_status(): void
    {
        $this->freezeTicket();
        $this->winners->processDraw((int) $this->draw->id, false, $this->operator);

        $ref = GloTicket::buildReference((int) $this->draw->id, 'l6', $this->ticketNumber);

        $response = $this->getJson('/api/v1/glo/public/tickets/'.rawurlencode($ref).'/status');
        $response->assertStatus(200);
        $response->assertJsonPath('data.status', GloPublicStatus::FrozenWinningPaymentHeld->value);
    }

    public function test_released_freeze_returns_released_status(): void
    {
        $freeze = $this->freezeTicket();
        $this->freezes->release($freeze, $this->operator, 'released');

        $ref = GloTicket::buildReference((int) $this->draw->id, 'l6', $this->ticketNumber);

        $response = $this->getJson('/api/v1/glo/public/tickets/'.rawurlencode($ref).'/status');
        $response->assertStatus(200);
        $response->assertJsonPath('data.status', GloPublicStatus::Released->value);
    }

    public function test_expired_freeze_returns_expired_status(): void
    {
        $freeze = $this->freezeTicket([
            'expiry_at' => now()->addDay()->toIso8601String(),
        ]);

        $freeze->expiry_at = now()->subHour();
        $freeze->save();
        $this->freezes->expireDueFreezes($this->operator);

        $ref = GloTicket::buildReference((int) $this->draw->id, 'l6', $this->ticketNumber);

        $response = $this->getJson('/api/v1/glo/public/tickets/'.rawurlencode($ref).'/status');
        $response->assertStatus(200);
        $response->assertJsonPath('data.status', GloPublicStatus::Expired->value);
    }

    public function test_not_frozen_when_only_non_effective_history_exists(): void
    {
        // Ticket exists with rejected freeze only → history exists but not frozen.
        $freeze = $this->freezes->requestFreeze($this->payload([
            'case_reference' => 'CASE-REJ',
            'evidence_reference' => 'EVD-REJ',
        ]), $this->operator);
        $this->freezes->reject($freeze, $this->operator, 'withdrawn');

        $ref = GloTicket::buildReference((int) $this->draw->id, 'l6', $this->ticketNumber);

        // With only rejected history and no announcement: deriveStatus scans
        // freezes non-empty → NotFrozen branch.
        $response = $this->getJson('/api/v1/glo/public/tickets/'.rawurlencode($ref).'/status');
        $response->assertStatus(200);
        $this->assertContains(
            $response->json('data.status'),
            [GloPublicStatus::NotFrozen->value, GloPublicStatus::NotFound->value],
        );
    }

    public function test_public_payload_never_contains_pii_or_internal_secrets(): void
    {
        $this->freezeTicket();
        $this->winners->processDraw((int) $this->draw->id, false, $this->operator);

        // Attach a claim with PII-ish fields that must NOT leak.
        $claimant = $this->freshUser()->create();
        $claimant->forceFill(['date_of_birth' => '1990-01-01']);
        $claimant->save();

        \App\Models\GloPrizeClaim::create([
            'claim_reference' => 'GLOCLM-PUBLIC-1',
            'ticket_id' => GloTicket::query()->firstOrFail()->id,
            'draw_id' => $this->draw->id,
            'product' => 'l6',
            'prize_category' => 'first',
            'ticket_number' => $this->ticketNumber,
            'gross_prize' => '6000000.00',
            'stamp_duty' => '30000.00',
            'net_prize' => '5970000.00',
            'claimant_user_id' => $claimant->id,
            'identity_reference' => 'kyc:'.$claimant->id,
            'age_verification_result' => 'verified',
            'age_verified_at' => now(),
            'verified_age_years' => 35,
            'original_ticket_evidenced' => true,
            'identity_document_evidenced' => true,
            'claim_channel' => 'glo_office',
            'status' => \App\Enums\GloClaimStatus::Hold,
            'payment_status' => 'blocked',
            'hold_status' => 'active',
            'hold_reason' => 'FROZEN_TICKET',
            'submitted_at' => now(),
            'fingerprint' => hash('sha256', 'public-pii-test'),
        ]);

        $ref = GloTicket::buildReference((int) $this->draw->id, 'l6', $this->ticketNumber);
        $response = $this->getJson('/api/v1/glo/public/tickets/'.rawurlencode($ref).'/status');
        $response->assertStatus(200);

        $raw = $response->getContent();
        $this->assertIsString($raw);

        // Forbidden content class checks.
        $forbidden = [
            'national_id',
            'passport',
            'phone',
            'email',
            'claimant_user_id',
            'identity_reference',
            'evidence_reference',
            'case_reference',
            'date_of_birth',
            'password',
            'token',
            'api_key',
            $claimant->email,
            'GLOCLM-PUBLIC-1', // claim reference is internal-ish
            'kyc:'.$claimant->id,
        ];

        foreach ($forbidden as $needle) {
            $this->assertFalse(
                str_contains($raw, (string) $needle),
                'Public response must not contain: '.$needle,
            );
        }

        // Allowed keys only in data.
        $data = $response->json('data');
        $this->assertSame(
            [
                'ticket_reference',
                'status',
                'status_label',
                'product',
                'prize_category',
                'announcement',
                'updated_at',
            ],
            array_keys($data),
        );

        if (is_array($data['announcement'] ?? null)) {
            $allowedAnnouncementKeys = [
                'announcement_id',
                'ticket_reference',
                'product',
                'prize_category',
                'status',
                'published_at',
                'effective_hold_at',
                'source_authority',
            ];
            $this->assertSame($allowedAnnouncementKeys, array_keys($data['announcement']));
        }
    }

    public function test_route_is_unauthenticated_and_rate_limited(): void
    {
        // Unauthenticated must succeed (public route) — not 401.
        $this->getJson('/api/v1/glo/public/tickets/1-l6-123456/status')
            ->assertStatus(200);

        // Rate limit: config 30/min — hammer to force 429.
        // Note: RateLimiter uses array cache per process.
        $status = null;
        for ($i = 0; $i < 35; $i++) {
            $response = $this->getJson('/api/v1/glo/public/tickets/1-l6-'.sprintf('%06d', $i).'/status');
            $status = $response->getStatusCode();
            if ($status === 429) {
                break;
            }
        }

        $this->assertSame(429, $status, 'glo.public limiter must engage within 35 requests');
    }

    public function test_no_authentication_cookies_or_tokens_required_or_leaked(): void
    {
        $this->freezeTicket();
        $ref = GloTicket::buildReference((int) $this->draw->id, 'l6', $this->ticketNumber);

        $response = $this->getJson('/api/v1/glo/public/tickets/'.rawurlencode($ref).'/status');
        $response->assertStatus(200);

        $raw = (string) $response->getContent();
        $this->assertFalse(str_contains($raw, 'Bearer'));
        $this->assertFalse(str_contains($raw, 'plainTextToken'));
        $this->assertFalse(str_contains($raw, 'remember_token'));
    }

    /* --------------------------------------------------------- helpers */

    private function freezeTicket(array $overrides = []): \App\Models\GloTicketFreeze
    {
        $freeze = $this->freezes->requestFreeze($this->payload($overrides), $this->operator);
        $freeze = $this->freezes->startReview($freeze, $this->operator);

        return $this->freezes->approveToFrozen($freeze, $this->operator);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'draw_id' => (int) $this->draw->id,
            'product' => 'l6',
            'ticket_number' => $this->ticketNumber,
            'requesting_authority' => 'Royal Thai Police',
            'jurisdiction' => 'Bangkok',
            'case_reference' => 'CASE-PUB-1',
            'evidence_reference' => 'EVD-PUB-1',
            'evidence_type' => 'official_notice',
        ], $overrides);
    }

    private function freshUser(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return User::factory()->state(fn (): array => [
            'email' => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(12)).'@glopub.local',
            'username' => 'gp'.\Illuminate\Support\Str::random(10),
            'phone' => '+8801'.random_int(100_000_000, 999_999_999),
            'status' => UserStatus::Active,
        ]);
    }
}
