<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\SlipVerificationStatus;
use App\Exceptions\FinancialException;
use App\Filament\Resources\DepositSlipVerificationResource;
use Illuminate\Auth\Access\AuthorizationException;
use App\Models\DepositSlipVerification;
use App\Models\User;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;
use App\Support\Admin\AdminAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Payment\PaymentTestCase;

final class DepositSlipVerificationResourceTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_staff_with_existing_permission_can_list_and_view_verification(): void
    {
        $player = $this->createPlayer('0.00');
        $verification = $this->verification($player['user']->getKey());
        $staff = $this->operator('admin');

        $this->actingAs($staff)->get(DepositSlipVerificationResource::getUrl('index'))
            ->assertSuccessful()->assertSee((string) $verification->getKey());
        $this->actingAs($staff)->get(DepositSlipVerificationResource::getUrl('view', ['record' => $verification]))
            ->assertSuccessful()->assertSee((string) $verification->getKey());
        self::assertTrue(DepositSlipVerificationResource::canViewAny());
    }

    public function test_staff_can_approve_and_credit_once_and_can_replay_idempotently(): void
    {
        $player = $this->createPlayer('0.00');
        $deposit = app(DepositService::class)->request(
            $player['wallet'], Money::of('82.50', Currency::THB), PaymentMethod::BankTransfer,
        );
        $verification = $this->verification($player['user']->getKey(), $deposit->getKey(), 'SLIP-STAFF-CREDIT-001', '82.50');
        $staff = $this->operator('admin');
        $this->actingAs($staff);

        $first = DepositSlipVerificationResource::approveAndCredit($verification, 'Provider and receiver evidence checked.', (int) $staff->getKey());
        $second = DepositSlipVerificationResource::approveAndCredit($first, 'Provider and receiver evidence checked.', (int) $staff->getKey());

        self::assertSame(SlipVerificationStatus::Credited, $first->fresh()->status);
        self::assertSame(SlipVerificationStatus::Credited, $second->status);
        self::assertSame('82.50', $player['wallet']->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 1);
        self::assertDatabaseCount('ledger_entries', 2);
        self::assertSame($staff->getKey(), $first->fresh()->reviewed_by);
    }

    public function test_staff_can_reject_with_required_reason_without_credit(): void
    {
        $player = $this->createPlayer('0.00');
        $verification = $this->verification($player['user']->getKey(), null, 'SLIP-STAFF-REJECT-001', '22.00');
        $staff = $this->operator('admin');
        $this->actingAs($staff);

        $rejected = DepositSlipVerificationResource::rejectReview($verification, 'Receiver account does not match.', (int) $staff->getKey());

        self::assertSame(SlipVerificationStatus::Rejected, $rejected->status);
        self::assertSame('Receiver account does not match.', $rejected->review_reason);
        self::assertSame('0.00', $player['wallet']->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 0);
    }

    public function test_staff_without_permission_is_refused_and_stale_actions_recheck_status(): void
    {
        $player = $this->createPlayer('0.00');
        $verification = $this->verification($player['user']->getKey(), null, 'SLIP-STALE-001', '15.00');
        $staff = $this->operator('admin');
        $unprivileged = $this->operator('player');

        $this->actingAs($unprivileged);
        self::assertFalse(DepositSlipVerificationResource::canViewAny());
        self::assertFalse(Gate::allows('view', $verification), 'A player may not inspect another player’s slip.');
        self::assertFalse(Gate::allows('approve', $verification));
        $this->get(DepositSlipVerificationResource::getUrl('index'))->assertForbidden();
        try {
            DepositSlipVerificationResource::approveAndCredit($verification, 'Unauthorized attempt.', (int) $unprivileged->getKey());
            self::fail('A user without the finance permission must not approve a slip.');
        } catch (AuthorizationException) {
            self::assertFalse(Gate::allows('approve', $verification));
        }

        $this->actingAs($staff);
        try {
            DepositSlipVerificationResource::rejectReview($verification, 'Wrong reviewer identity.', (int) $unprivileged->getKey());
            self::fail('A review action must be attributed to the authenticated operator.');
        } catch (FinancialException $exception) {
            self::assertSame('slip_review_actor_mismatch', $exception->errorCode());
        }
        $stale = DepositSlipVerification::query()->findOrFail($verification->getKey());
        DepositSlipVerificationResource::rejectReview($verification->fresh(), 'Reviewed and rejected by another staff member.', (int) $staff->getKey());
        try {
            DepositSlipVerificationResource::approveAndCredit($stale, 'Attempt from a stale review screen.', (int) $staff->getKey());
            self::fail('A stale action must not approve or credit a rejected verification.');
        } catch (FinancialException $exception) {
            self::assertSame('slip_review_state_conflict', $exception->errorCode());
        }

        self::assertSame(SlipVerificationStatus::Rejected, $verification->fresh()->status);
        self::assertSame('0.00', $player['wallet']->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 0);
    }

    public function test_review_action_requires_complete_provider_checks_and_a_credit_safe_fraud_decision(): void
    {
        $player = $this->createPlayer('0.00');
        $deposit = app(DepositService::class)->request(
            $player['wallet'], Money::of('64.00', Currency::THB), PaymentMethod::BankTransfer,
        );
        $verification = $this->verification($player['user']->getKey(), $deposit->getKey(), 'SLIP-FRAUD-UNKNOWN-001', '64.00');
        $payload = $verification->provider_payload;
        $staff = $this->operator('admin');
        $this->actingAs($staff);

        foreach (['step_up', null] as $decision) {
            $payload['verification_facts']['fraud_decision'] = $decision;
            $verification->forceFill(['provider_payload' => $payload])->save();
            try {
                DepositSlipVerificationResource::approveAndCredit($verification, 'Attempt without a completed fraud decision.', (int) $staff->getKey());
                self::fail('A slip without a credit-safe fraud decision must not be credited.');
            } catch (FinancialException $exception) {
                self::assertSame('slip_credit_proof_missing', $exception->errorCode());
            }
        }

        self::assertSame('0.00', $player['wallet']->fresh()->balance);
        self::assertSame('pending', $deposit->fresh()->status->value);
        self::assertDatabaseCount('financial_transactions', 0);
    }

    private function operator(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    private function verification(int $userId, ?int $depositId = null, string $transRef = 'SLIP-STAFF-001', string $amount = '10.00'): DepositSlipVerification
    {
        return DepositSlipVerification::factory()->create([
            'user_id' => $userId,
            'deposit_id' => $depositId,
            'trans_ref' => $transRef,
            'amount' => $amount,
            'currency' => 'THB',
            'sending_bank' => 'KBANK',
            'receiving_account_masked' => '••••1234',
            'status' => SlipVerificationStatus::NeedsReview,
            'review_reason' => 'manual_review_required',
            'provider_payload' => [
                'trans_ref_hash' => hash('sha256', $transRef),
                'verification_facts' => [
                    'hard_checks_passed' => true,
                    'provider_valid' => true,
                    'provider_duplicate' => false,
                    'provider_amount' => $amount,
                    'claimed_amount' => $amount,
                    'currency' => 'THB',
                    'receiver_matches' => true,
                    'fraud_decision' => 'allow',
                    'trans_ref_hash' => hash('sha256', $transRef),
                ],
            ],
        ]);
    }
}
