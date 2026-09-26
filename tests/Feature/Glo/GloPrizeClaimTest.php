<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Enums\DrawStatus;
use App\Enums\GloClaimStatus;
use App\Enums\GloPaymentHoldStatus;
use App\Enums\UserStatus;
use App\Exceptions\GloClaimException;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloPrizeClaim;
use App\Models\GloPrizePaymentHold;
use App\Models\GloTicket;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Lottery\GloPrizeClaimService;
use App\Services\Lottery\GloStampDutyCalculator;
use App\Services\Lottery\GloTicketFreezeService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GLO-12/14 prize claim lifecycle, age gate, stamp duty, payment fail-closed
 * conditions and ALREADY_PAID concurrency behaviour.
 */
class GloPrizeClaimTest extends TestCase
{
    use DatabaseTruncation;

    private GloPrizeClaimService $claims;

    private GloTicketFreezeService $freezes;

    private User $admin;

    private User $paymentOperator;

    private string $adminToken;

    private string $paymentToken;

    private Draw $draw;

    private GloTicket $ticket;

    private User $claimant;

    private string $ticketNumber = '445566';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->claims = app(GloPrizeClaimService::class);
        $this->freezes = app(GloTicketFreezeService::class);

        $this->admin = $this->freshUser()->create();
        $this->admin->syncRoles(['admin']);
        $this->adminToken = $this->admin->createToken('api')->plainTextToken;

        // Dedicated payment operator: admin role lacks execute permission →
        // grant explicitly (super-admin style separation of duties).
        $this->paymentOperator = $this->freshUser()->create();
        $this->paymentOperator->syncRoles(['auditor']);
        $this->paymentOperator->givePermissionTo('execute glo prize payments');
        $this->paymentOperator->givePermissionTo('view dashboard');
        $this->paymentToken = $this->paymentOperator->createToken('api')->plainTextToken;

        $this->draw = Draw::factory()->create([
            'status' => DrawStatus::Completed,
            'scheduled_at' => now()->subDays(20),
            'result_published_at' => now()->subDays(19),
            'completed_at' => now()->subDays(19),
        ]);

        DrawResult::create([
            'draw_id' => $this->draw->id,
            'first_prize' => $this->ticketNumber,
            'second_prize' => ['121212'],
            'third_prize' => ['131313'],
            'consolation_prizes' => [],
            'all_numbers' => [],
            'total_winners' => 1,
            'total_payout' => '6000000.00',
            'house_profit' => '0.00',
            'published_at' => now()->subDays(19),
            'metadata' => ['glo' => []],
        ]);

        $this->ticket = GloTicket::create([
            'draw_id' => $this->draw->id,
            'product' => 'l6',
            'ticket_number' => $this->ticketNumber,
            'set_series' => null,
            'owner_user_id' => null,
            'ticket_reference' => GloTicket::buildReference((int) $this->draw->id, 'l6', $this->ticketNumber),
            'metadata' => [],
        ]);

        $this->claimant = $this->freshUser()->create();
        $this->claimant->syncRoles(['player']);
        $this->claimant->forceFill(['date_of_birth' => now()->subYears(35)->toDateString()]);
        $this->claimant->save();
        $this->makeVerified($this->claimant);
    }

    public function test_stamp_duty_calculator_preserves_official_rule(): void
    {
        $calc = app(GloStampDutyCalculator::class);

        // 6,000,000 / 200 = 30,000 exactly.
        $this->assertSame('30000.00', $calc->dutyFor('6000000.00'));
        $this->assertSame('5970000.00', $calc->netAfterDuty('6000000.00'));

        // Ceil: 2000.01 / 200 = 10.00005 → 11.
        $this->assertSame('11.00', $calc->dutyFor('2000.01'));

        // Ceiling up from partial unit: 199.01 / 200 → 1 unit? 199.01/200 = 0.995 → ceil 1.
        $this->assertSame('1.00', $calc->dutyFor('199.01'));
        $this->assertSame('1.00', $calc->dutyFor('200.00'));
        $this->assertSame('2.00', $calc->dutyFor('200.01'));

        // Never 0.5% float: 10000 * 0.005 = 50, but ceil(10000/200)=50 — same here;
        // use 10001: float 0.5% = 50.005 → wrong paths; official = ceil(10001/200)=51.
        $this->assertSame('51.00', $calc->dutyFor('10001.00'));

        // Not 1% withholding: 10000/200 = 50 not 100.
        $this->assertSame('50.00', $calc->dutyFor('10000.00'));

        // Zero.
        $this->assertSame('0.00', $calc->dutyFor('0.00'));
    }

    public function test_successful_claim_submit_review_approve_pay(): void
    {
        $claim = $this->claims->submit([
            'ticket_id' => $this->ticket->id,
            'prize_category' => 'first',
            'claim_channel' => 'glo_office',
            'original_ticket_evidenced' => true,
            'identity_document_evidenced' => true,
        ], $this->claimant);

        $this->assertSame(GloClaimStatus::Pending, $claim->status);
        $this->assertSame('6000000.00', $claim->gross_prize);
        $this->assertSame('30000.00', $claim->stamp_duty);
        $this->assertSame('5970000.00', $claim->net_prize);
        $this->assertSame(35, $claim->verified_age_years);
        $this->assertSame('verified', $claim->age_verification_result);

        $claim = $this->claims->reviewEligible($claim, $this->admin);
        $this->assertSame(GloClaimStatus::Eligible, $claim->status);

        $claim = $this->claims->approve($claim, $this->admin);
        $this->assertSame(GloClaimStatus::Approved, $claim->status);
        $this->assertNotNull($claim->approved_at);

        $claim = $this->claims->pay($claim, $this->paymentOperator, 'TX-TEST-001');
        $this->assertSame(GloClaimStatus::Paid, $claim->status);
        $this->assertSame('executed', $claim->payment_status);
        $this->assertSame('TX-TEST-001', $claim->payment_transaction_reference);
        $this->assertNotNull($claim->paid_at);
        $this->assertSame($this->paymentOperator->id, $claim->paid_by);
    }

    public function test_underage_claim_is_denied_from_server_side_dob(): void
    {
        $underage = $this->freshUser()->create();
        $underage->syncRoles(['player']);
        $underage->forceFill(['date_of_birth' => now()->subYears(19)->toDateString()]);
        $underage->save();
        $this->makeVerified($underage);

        try {
            $this->claims->submit([
                'ticket_id' => $this->ticket->id,
                'prize_category' => 'first',
                'claim_channel' => 'glo_office',
                'original_ticket_evidenced' => true,
            ], $underage);
            $this->fail('under-20 claim must be denied');
        } catch (GloClaimException $e) {
            $this->assertStringContainsString('below the official minimum', $e->getMessage());
        }

        $this->assertSame(0, GloPrizeClaim::query()->count());
    }

    public function test_client_supplied_age_is_ignored_and_missing_dob_fails_closed(): void
    {
        $noDob = $this->freshUser()->create();
        $noDob->syncRoles(['player']);
        // date_of_birth intentionally left null.
        $this->makeVerified($noDob);

        // Even if a hostile client posts age=99, service never reads request age.
        // Submit throws age unverifiable OR underAge path — we deny at submit via
        // ageUnverifiable when DOB missing (ageYears null → unverified, but
        // payment/approve must fail closed). Allow submit as pending only when
        // age unproven? Spec: age must fail closed before APPROVED/PAID.
        // Current service allows submit with ageResult=unverified when DOB null.
        $claim = $this->claims->submit([
            'ticket_id' => $this->ticket->id,
            'prize_category' => 'first',
            'claim_channel' => 'bank',
            'original_ticket_evidenced' => false,
        ], $noDob);

        $this->assertSame('unverified', $claim->age_verification_result);
        $this->assertNull($claim->verified_age_years);

        $claim = $this->claims->reviewEligible($claim, $this->admin);

        try {
            $this->claims->approve($claim, $this->admin);
            $this->fail('approve must fail when age cannot be verified');
        } catch (GloClaimException $e) {
            $this->assertStringContainsString('age cannot be verified', $e->getMessage());
        }

        $this->assertNotSame(GloClaimStatus::Approved, $claim->fresh()->status);
    }

    public function test_frozen_ticket_blocks_claim_payment(): void
    {
        $freeze = $this->freezes->requestFreeze([
            'draw_id' => (int) $this->draw->id,
            'product' => 'l6',
            'ticket_number' => $this->ticketNumber,
            'requesting_authority' => 'Police',
            'jurisdiction' => 'BKK',
            'case_reference' => 'CASE-CLM-BLOCK',
            'evidence_reference' => 'EVD-CLM-BLOCK',
        ], $this->admin);
        $freeze = $this->freezes->startReview($freeze, $this->admin);
        $freeze = $this->freezes->approveToFrozen($freeze, $this->admin);

        $claim = $this->claims->submit([
            'ticket_id' => $this->ticket->id,
            'prize_category' => 'first',
            'claim_channel' => 'glo_office',
            'original_ticket_evidenced' => true,
        ], $this->claimant);

        $this->assertSame(GloClaimStatus::Hold, $claim->status);
        $this->assertSame('blocked', $claim->payment_status);
        $this->assertSame('FROZEN_TICKET', $claim->hold_reason);

        // Approve must fail while freeze active (FROZEN_TICKET gate).
        try {
            $this->claims->approve($claim, $this->admin);
            $this->fail('approve must fail on frozen ticket');
        } catch (GloClaimException $e) {
            $this->assertStringContainsString('FROZEN_TICKET', $e->getMessage());
        }

        // Even force status to Approved is not enough — pay re-checks freeze.
        $claim->status = GloClaimStatus::Approved;
        $claim->save();

        try {
            $this->claims->pay($claim, $this->paymentOperator);
            $this->fail('pay must fail on frozen ticket');
        } catch (GloClaimException) {
            $this->assertTrue(true);
        }

        $this->assertNull($claim->fresh()->payment_transaction_reference);
    }

    public function test_duplicate_claim_is_rejected_with_idempotent_identity(): void
    {
        $body = [
            'ticket_id' => $this->ticket->id,
            'prize_category' => 'first',
            'claim_channel' => 'glo_office',
            'original_ticket_evidenced' => true,
        ];

        $first = $this->claims->submit($body, $this->claimant);
        $this->assertSame(1, GloPrizeClaim::query()->count());

        try {
            $this->claims->submit($body, $this->claimant);
            $this->fail('duplicate claim must be rejected');
        } catch (GloClaimException $e) {
            $this->assertStringContainsString($first->claim_reference, $e->getMessage());
        }

        $this->assertSame(1, GloPrizeClaim::query()->count());
    }

    public function test_double_pay_second_worker_sees_already_paid(): void
    {
        $claim = $this->happyApprovedClaim();

        $paid = $this->claims->pay($claim, $this->paymentOperator, 'TX-FIRST');
        $this->assertSame(GloClaimStatus::Paid, $paid->status);

        try {
            $this->claims->pay($paid, $this->paymentOperator, 'TX-SECOND');
            $this->fail('second pay must raise ALREADY_PAID');
        } catch (GloClaimException $e) {
            $this->assertStringContainsString('ALREADY_PAID', $e->getMessage());
        }

        $fresh = $claim->fresh();
        $this->assertSame('TX-FIRST', $fresh->payment_transaction_reference);
        $this->assertSame(1, GloPrizeClaim::query()->where('status', GloClaimStatus::Paid)->count());
    }

    public function test_claim_window_expired_is_refused(): void
    {
        $oldDraw = Draw::factory()->create([
            'status' => DrawStatus::Completed,
            'scheduled_at' => now()->subYears(3),
            'result_published_at' => now()->subYears(3)->addDay(),
            'completed_at' => now()->subYears(3)->addDay(),
        ]);

        DrawResult::create([
            'draw_id' => $oldDraw->id,
            'first_prize' => '111222',
            'second_prize' => [],
            'third_prize' => [],
            'consolation_prizes' => [],
            'all_numbers' => [],
            'total_winners' => 0,
            'total_payout' => '0.00',
            'house_profit' => '0.00',
            'published_at' => now()->subYears(3)->addDay(),
            'metadata' => ['glo' => []],
        ]);

        $oldTicket = GloTicket::create([
            'draw_id' => $oldDraw->id,
            'product' => 'l6',
            'ticket_number' => '111222',
            'set_series' => null,
            'owner_user_id' => null,
            'ticket_reference' => GloTicket::buildReference((int) $oldDraw->id, 'l6', '111222'),
            'metadata' => [],
        ]);

        try {
            $this->claims->submit([
                'ticket_id' => $oldTicket->id,
                'prize_category' => 'first',
                'claim_channel' => 'glo_office',
                'original_ticket_evidenced' => true,
            ], $this->claimant);
            $this->fail('claim window must expire after 2 years');
        } catch (GloClaimException $e) {
            $this->assertStringContainsString('two-year claim period', $e->getMessage());
        }
    }

    public function test_physical_channel_requires_original_ticket_evidence(): void
    {
        try {
            $this->claims->submit([
                'ticket_id' => $this->ticket->id,
                'prize_category' => 'first',
                'claim_channel' => 'glo_office',
                'original_ticket_evidenced' => false,
            ], $this->claimant);
            $this->fail('physical channel without original ticket must fail');
        } catch (GloClaimException $e) {
            $this->assertStringContainsString('original ticket', $e->getMessage());
        }
    }

    public function test_unverified_identity_cannot_submit(): void
    {
        $raw = $this->freshUser()->create();
        $raw->syncRoles(['player']);
        $raw->forceFill(['date_of_birth' => now()->subYears(40)->toDateString()]);
        $raw->save();
        // No KycDocument → unverified.

        try {
            $this->claims->submit([
                'ticket_id' => $this->ticket->id,
                'prize_category' => 'first',
                'claim_channel' => 'bank',
            ], $raw);
            $this->fail('unverified identity must not submit');
        } catch (GloClaimException $e) {
            $this->assertStringContainsString('identity', strtolower($e->getMessage()));
        }
    }

    public function test_api_claim_routes_default_deny_and_pay_requires_payment_permission(): void
    {
        // Unauthenticated submit → 401.
        $this->postJson('/api/v1/glo/claims', [])->assertStatus(401);

        $claimantToken = $this->claimant->createToken('api')->plainTextToken;

        $submit = $this->apiAs($claimantToken, 'POST', '/api/v1/glo/claims', [
            'ticket_id' => $this->ticket->id,
            'prize_category' => 'first',
            'claim_channel' => 'glo_office',
            'original_ticket_evidenced' => true,
            'status' => 'paid', // hostile — ignored
            'age' => 99,        // hostile — ignored
            'gross_prize' => '1.00',
            'net_prize' => '1.00',
            'is_frozen' => false,
            'payment_hold' => false,
        ]);
        $submit->assertStatus(201);
        $submit->assertJsonPath('data.status', 'pending');
        $submit->assertJsonPath('data.gross_prize', '6000000.00');
        $submit->assertJsonPath('data.stamp_duty', '30000.00');

        $ref = $submit->json('data.claim_reference');

        // Claimant can view own claim.
        $this->apiAs($claimantToken, 'GET', '/api/v1/glo/claims/'.$ref)->assertStatus(200);

        // Claimant cannot approve/pay.
        $this->apiAs($claimantToken, 'POST', '/api/v1/glo/claims/'.$ref.'/approve')->assertStatus(403);
        $this->apiAs($claimantToken, 'POST', '/api/v1/glo/claims/'.$ref.'/pay')->assertStatus(403);

        // Admin (has manage claims) can review + approve, but NOT pay
        // (admin excluded from execute glo prize payments).
        $this->apiAs($this->adminToken, 'POST', '/api/v1/glo/claims/'.$ref.'/review')->assertStatus(200);
        $this->apiAs($this->adminToken, 'POST', '/api/v1/glo/claims/'.$ref.'/approve')->assertStatus(200);
        $this->apiAs($this->adminToken, 'POST', '/api/v1/glo/claims/'.$ref.'/pay')->assertStatus(403);

        // Payment operator can pay.
        $pay = $this->apiAs($this->paymentToken, 'POST', '/api/v1/glo/claims/'.$ref.'/pay', [
            'transaction_reference' => 'TX-API-1',
        ]);
        $pay->assertStatus(200);
        $pay->assertJsonPath('data.status', 'paid');

        // Second pay → 409 already_paid.
        $this->apiAs($this->paymentToken, 'POST', '/api/v1/glo/claims/'.$ref.'/pay', [
            'transaction_reference' => 'TX-API-2',
        ])->assertStatus(409);
    }

    public function test_payment_fail_closed_when_settlement_not_final(): void
    {
        $openDraw = Draw::factory()->create([
            'status' => DrawStatus::ResultPublished, // not Completed
            'scheduled_at' => now()->subDays(5),
            'result_published_at' => now()->subDays(4),
        ]);

        DrawResult::create([
            'draw_id' => $openDraw->id,
            'first_prize' => '777888',
            'second_prize' => [],
            'third_prize' => [],
            'consolation_prizes' => [],
            'all_numbers' => [],
            'total_winners' => 0,
            'total_payout' => '0.00',
            'house_profit' => '0.00',
            'published_at' => now()->subDays(4),
            'metadata' => ['glo' => []],
        ]);

        $openTicket = GloTicket::create([
            'draw_id' => $openDraw->id,
            'product' => 'l6',
            'ticket_number' => '777888',
            'set_series' => null,
            'owner_user_id' => null,
            'ticket_reference' => GloTicket::buildReference((int) $openDraw->id, 'l6', '777888'),
            'metadata' => [],
        ]);

        $claim = $this->claims->submit([
            'ticket_id' => $openTicket->id,
            'prize_category' => 'first',
            'claim_channel' => 'glo_office',
            'original_ticket_evidenced' => true,
        ], $this->claimant);

        $claim = $this->claims->reviewEligible($claim, $this->admin);

        try {
            $this->claims->approve($claim, $this->admin);
            // approve allows ResultPublished only if... assertPaymentConditions
            // forFinalPayment false: allows ResultPublished | Completed
            // for Final pay: requires Completed unless config
            $claim2 = $claim->fresh();
            if ($claim2->status === GloClaimStatus::Approved) {
                $this->claims->pay($claim2, $this->paymentOperator);
                $this->fail('pay must fail when settlement is not Completed');
            } else {
                $this->fail('approve should have thrown for non-final settlement');
            }
        } catch (GloClaimException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'settlement')
                || str_contains($e->getMessage(), 'not final'),
            );
        }

        $this->assertNull($claim->fresh()->payment_transaction_reference);
    }

    public function test_active_payment_hold_blocks_payment(): void
    {
        $claim = $this->happyApprovedClaim();

        GloPrizePaymentHold::create([
            'hold_reference' => 'GLOHOLD-API-BLOCK',
            'claim_id' => $claim->id,
            'freeze_id' => $this->activeFreezeOnTicket($claim)->id,
            'ticket_id' => $claim->ticket_id,
            'draw_id' => $claim->draw_id,
            'winning_category' => 'first',
            'gross_prize' => '6000000.00',
            'stamp_duty' => '30000.00',
            'hold_reason' => 'FROZEN_TICKET',
            'status' => GloPaymentHoldStatus::Active,
            'created_at' => now(),
            'fingerprint' => hash('sha256', 'hold-block-test'),
        ]);

        try {
            $this->claims->pay($claim, $this->paymentOperator);
            $this->fail('active hold must block payment');
        } catch (GloClaimException $e) {
            $this->assertStringContainsString('hold', strtolower($e->getMessage()));
        }

        $this->assertNull($claim->fresh()->payment_transaction_reference);
    }

    /* --------------------------------------------------------- helpers */

    private function happyApprovedClaim(): GloPrizeClaim
    {
        $claim = $this->claims->submit([
            'ticket_id' => $this->ticket->id,
            'prize_category' => 'first',
            'claim_channel' => 'glo_office',
            'original_ticket_evidenced' => true,
        ], $this->claimant);

        $claim = $this->claims->reviewEligible($claim, $this->admin);

        return $this->claims->approve($claim, $this->admin);
    }

    private function activeFreezeOnTicket(GloPrizeClaim $claim): \App\Models\GloTicketFreeze
    {
        return $this->freezes->requestFreeze([
            'draw_id' => (int) $claim->draw_id,
            'product' => 'l6',
            'ticket_number' => (string) $claim->ticket_number,
            'requesting_authority' => 'Police',
            'jurisdiction' => 'BKK',
            'case_reference' => 'CASE-HOLD-'.uniqid(),
            'evidence_reference' => 'EVD-HOLD-'.uniqid(),
        ], $this->admin);
    }

    private function makeVerified(User $user): void
    {
        KycDocument::create([
            'user_id' => $user->id,
            'document_type' => 'national_id',
            'document_number' => random_int(1000000000000, 9999999999999),
            'file_path' => 'kyc/'.$user->id.'.png',
            'original_filename' => 'id.png',
            'mime_type' => 'image/png',
            'file_size' => 2048,
            'status' => 'verified',
            'verified_at' => now(),
            'verified_by' => $this->admin->id,
            'metadata' => [],
        ]);
    }

    private function apiAs(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $data);
    }

    private function freshUser(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return User::factory()->state(fn (): array => [
            'email' => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(12)).'@gloclaim.local',
            'username' => 'gc'.\Illuminate\Support\Str::random(10),
            'phone' => '+8801'.random_int(100_000_000, 999_999_999),
            'status' => UserStatus::Active,
        ]);
    }
}
