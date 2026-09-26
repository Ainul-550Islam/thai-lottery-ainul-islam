<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Enums\DrawStatus;
use App\Enums\GloClaimStatus;
use App\Enums\GloPaymentHoldStatus;
use App\Enums\UserStatus;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloPrizeClaim;
use App\Models\GloPrizePaymentHold;
use App\Models\GloPublicTicketStatus;
use App\Models\GloTicket;
use App\Models\GloTicketFreeze;
use App\Models\User;
use App\Services\Lottery\GloFrozenWinnerService;
use App\Services\Lottery\GloTicketFreezeService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GLO-12 frozen winner: hold + announcement, NEVER pay; expired freeze does
 * not auto-pay; claim on frozen winning ticket goes to HOLD / payment blocked.
 */
class GloFrozenWinnerTest extends TestCase
{
    use DatabaseTruncation;

    private GloTicketFreezeService $freezes;

    private GloFrozenWinnerService $winners;

    private User $operator;

    private string $token;

    private Draw $draw;

    private string $winningNumber = '654321';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->freezes = app(GloTicketFreezeService::class);
        $this->winners = app(GloFrozenWinnerService::class);

        $this->operator = $this->freshUser()->create();
        $this->operator->syncRoles(['admin']);
        $this->token = $this->operator->createToken('api')->plainTextToken;

        $this->draw = Draw::factory()->create([
            'status' => DrawStatus::Completed,
            'scheduled_at' => now()->subDays(15),
            'result_published_at' => now()->subDays(14),
            'completed_at' => now()->subDays(14),
        ]);

        DrawResult::create([
            'draw_id' => $this->draw->id,
            'first_prize' => $this->winningNumber,
            'second_prize' => ['111111', '222222'],
            'third_prize' => ['333331', '333332'],
            'consolation_prizes' => [],
            'all_numbers' => [],
            'total_winners' => 0,
            'total_payout' => '0.00',
            'house_profit' => '0.00',
            'published_at' => now()->subDays(14),
            'metadata' => ['glo' => [
                'fourth' => ['444441'],
                'fifth' => ['555551'],
                'front_three' => ['123'],
                'last_three' => ['321'],
                'last_two' => ['21'],
            ]],
        ]);
    }

    public function test_frozen_winning_ticket_gets_payment_hold_and_public_announcement(): void
    {
        $freeze = $this->freezeWinningTicket();

        $result = $this->winners->processDraw((int) $this->draw->id, false, $this->operator);

        $this->assertSame(1, $result['holds_created']);
        $this->assertSame(0, $result['paid'] ?? 0);

        $hold = GloPrizePaymentHold::query()->where('ticket_id', $freeze->ticket_id)->firstOrFail();
        $this->assertSame(GloPaymentHoldStatus::Active, $hold->status);
        $this->assertSame('FROZEN_TICKET', $hold->hold_reason);
        $this->assertSame('first', $hold->winning_category);

        $announcement = GloPublicTicketStatus::query()->where('ticket_id', $freeze->ticket_id)->firstOrFail();
        $this->assertSame('FROZEN_AND_WINNING_PAYMENT_HELD', $announcement->status);

        // Public announcement must not carry PII keys.
        $public = $announcement->toPublicArray();
        $this->assertArrayNotHasKey('claimant', $public);
        $this->assertArrayNotHasKey('national_id', $public);
        $this->assertArrayNotHasKey('evidence', $public);
        $this->assertArrayNotHasKey('id', $public);

        // Idempotent second run.
        $again = $this->winners->processDraw((int) $this->draw->id, false, $this->operator);
        $this->assertSame(0, $again['holds_created']);
        $this->assertSame(1, $again['holds_existing']);
        $this->assertSame(1, GloPrizePaymentHold::query()->count());
        $this->assertSame(1, GloPublicTicketStatus::query()->count());
    }

    public function test_dry_run_does_not_write_holds(): void
    {
        $this->freezeWinningTicket();

        $result = $this->winners->processDraw((int) $this->draw->id, true, $this->operator);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, $result['holds_created']);
        $this->assertSame(0, GloPrizePaymentHold::query()->count());
        $this->assertSame(0, GloPublicTicketStatus::query()->count());
    }

    public function test_non_winning_frozen_ticket_is_not_held(): void
    {
        // Freeze a non-winning number.
        $freeze = $this->freezes->requestFreeze([
            'draw_id' => (int) $this->draw->id,
            'product' => 'l6',
            'ticket_number' => '999999',
            'requesting_authority' => 'Police',
            'jurisdiction' => 'BKK',
            'case_reference' => 'CASE-NW',
            'evidence_reference' => 'EVD-NW',
        ], $this->operator);
        $freeze = $this->freezes->startReview($freeze, $this->operator);
        $freeze = $this->freezes->approveToFrozen($freeze, $this->operator);

        $result = $this->winners->processDraw((int) $this->draw->id, false, $this->operator);

        $this->assertSame(0, $result['holds_created']);
        $this->assertSame(0, GloPrizePaymentHold::query()->count());
    }

    public function test_released_freeze_does_not_create_hold(): void
    {
        $freeze = $this->freezeWinningTicket();
        $this->freezes->release($freeze, $this->operator, 'released before draw settlement');

        $result = $this->winners->processDraw((int) $this->draw->id, false, $this->operator);

        $this->assertSame(0, $result['holds_created']);
        $this->assertSame(0, GloPrizePaymentHold::query()->count());
    }

    public function test_expired_freeze_does_not_auto_pay_and_clear_returns_claim_to_eligible_not_paid(): void
    {
        $freeze = $this->freezeWinningTicket([
            'case_reference' => 'CASE-EXPAY',
            'evidence_reference' => 'EVD-EXPAY',
            'expiry_at' => now()->addDay()->toIso8601String(),
        ]);

        // Winner hold created while frozen.
        $this->winners->processDraw((int) $this->draw->id, false, $this->operator);
        $this->assertSame(1, GloPrizePaymentHold::query()->where('status', GloPaymentHoldStatus::Active)->count());

        // Claim exists in HOLD (submitted while frozen).
        $claimant = $this->makeVerifiedClaimant();
        $claim = GloPrizeClaim::create([
            'claim_reference' => 'GLOCLM-TEST-EXPAY',
            'ticket_id' => $freeze->ticket_id,
            'draw_id' => $this->draw->id,
            'product' => 'l6',
            'prize_category' => 'first',
            'ticket_number' => $this->winningNumber,
            'gross_prize' => '6000000.00',
            'stamp_duty' => '30000.00',
            'net_prize' => '5970000.00',
            'claimant_user_id' => $claimant->id,
            'identity_reference' => 'kyc:'.$claimant->id,
            'age_verification_result' => 'verified',
            'age_verified_at' => now(),
            'verified_age_years' => 30,
            'original_ticket_evidenced' => true,
            'identity_document_evidenced' => true,
            'claim_channel' => 'glo_office',
            'status' => GloClaimStatus::Hold,
            'payment_status' => 'blocked',
            'hold_status' => 'active',
            'hold_reason' => 'FROZEN_TICKET',
            'submitted_at' => now(),
            'fingerprint' => hash('sha256', 'expay-claim'),
        ]);

        // Expire the freeze (audited).
        $freeze->expiry_at = now()->subHour();
        $freeze->save();
        $this->freezes->expireDueFreezes($this->operator);

        // Expired freeze does NOT auto-pay: claim still Hold until hold cleared,
        // and never jumps to Paid.
        $claim->refresh();
        $this->assertNotSame(GloClaimStatus::Paid, $claim->status);

        // Clear holds (authorized): hold → cleared, claim → eligible, NOT paid.
        $cleared = app(\App\Services\Lottery\GloPrizeClaimService::class)
            ->clearHoldsIfFreezesInactive((int) $freeze->ticket_id, $this->operator);

        $this->assertGreaterThanOrEqual(1, $cleared);

        $claim->refresh();
        $this->assertSame(GloClaimStatus::Eligible, $claim->status);
        $this->assertNull($claim->paid_at);
        $this->assertNull($claim->payment_transaction_reference);

        $this->assertSame(
            0,
            GloPrizePaymentHold::query()->where('status', GloPaymentHoldStatus::Active)->count(),
        );

        // Payment history: no executed payment ever written by expiry path.
        $this->assertSame(
            0,
            GloPrizeClaim::query()->where('status', GloClaimStatus::Paid)->count(),
        );
    }

    public function test_process_frozen_winners_command_json_and_never_pays(): void
    {
        $this->freezeWinningTicket();

        $exit = Artisan::call('glo:process-frozen-winners', [
            '--draw' => $this->draw->id,
            '--json' => true,
            '--actor' => $this->operator->email,
        ]);

        $this->assertSame(0, $exit);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame(0, $payload['paid']);
        $this->assertSame(1, $payload['holds_created']);
        $this->assertSame(0, GloPrizeClaim::query()->where('status', GloClaimStatus::Paid)->count());
    }

    public function test_command_dry_run_flag(): void
    {
        $this->freezeWinningTicket();

        $exit = Artisan::call('glo:process-frozen-winners', [
            '--draw' => $this->draw->id,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame(0, GloPrizePaymentHold::query()->count());
    }

    public function test_freeze_command_requires_actor_and_permission(): void
    {
        // Missing actor → failure (default deny).
        $exit = Artisan::call('glo:freeze-ticket', [
            'ticket' => '1',
            '--number' => '123456',
            '--draw' => $this->draw->id,
            '--authority' => 'Police',
            '--case-reference' => 'CASE-CLI',
            '--evidence-reference' => 'EVD-CLI',
        ]);
        $this->assertNotSame(0, $exit);

        // With authorized actor → success.
        $exit = Artisan::call('glo:freeze-ticket', [
            'ticket' => '1',
            '--number' => '123456',
            '--draw' => $this->draw->id,
            '--authority' => 'Police',
            '--jurisdiction' => 'BKK',
            '--case-reference' => 'CASE-CLI-OK',
            '--evidence-reference' => 'EVD-CLI-OK',
            '--actor' => $this->operator->email,
        ]);
        $this->assertSame(0, $exit);
        $this->assertSame(1, GloTicketFreeze::query()->where('case_reference', 'CASE-CLI-OK')->count());
    }

    /* --------------------------------------------------------- helpers */

    private function freezeWinningTicket(array $overrides = []): \App\Models\GloTicketFreeze
    {
        $payload = array_merge([
            'draw_id' => (int) $this->draw->id,
            'product' => 'l6',
            'ticket_number' => $this->winningNumber,
            'requesting_authority' => 'Royal Thai Police',
            'jurisdiction' => 'Bangkok',
            'case_reference' => 'CASE-WIN-1',
            'evidence_reference' => 'EVD-WIN-1',
            'evidence_type' => 'official_notice',
        ], $overrides);

        $freeze = $this->freezes->requestFreeze($payload, $this->operator);
        $freeze = $this->freezes->startReview($freeze, $this->operator);

        return $this->freezes->approveToFrozen($freeze, $this->operator);
    }

    private function makeVerifiedClaimant(): User
    {
        $user = $this->freshUser()->create();
        $user->syncRoles(['player']);

        // Authoritative DOB: 30 years ago.
        $user->forceFill(['date_of_birth' => now()->subYears(30)->toDateString()]);
        $user->save();

        // Verified KYC evidence.
        \App\Models\KycDocument::create([
            'user_id' => $user->id,
            'document_type' => 'national_id',
            'document_number' => '1234567890123',
            'file_path' => 'kyc/test.png',
            'original_filename' => 'id.png',
            'mime_type' => 'image/png',
            'file_size' => 1024,
            'status' => 'verified',
            'verified_at' => now(),
            'verified_by' => $this->operator->id,
            'metadata' => [],
        ]);

        return $user;
    }

    private function apiAs(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $data);
    }

    private function freshUser(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return User::factory()->state(fn (): array => [
            'email' => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(12)).'@glofrozen.local',
            'username' => 'gf'.\Illuminate\Support\Str::random(10),
            'phone' => '+8801'.random_int(100_000_000, 999_999_999),
            'status' => UserStatus::Active,
        ]);
    }
}
