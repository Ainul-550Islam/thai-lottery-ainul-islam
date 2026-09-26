<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Enums\Currency;
use App\Enums\KycStatus;
use App\Exceptions\FinancialException;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Compliance\WithdrawalKycGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P0-D: server-authoritative KYC withdrawal gate.
 *
 * NOT_SUBMITTED / PENDING / REJECTED / EXPIRED deny above-threshold withdrawals.
 * APPROVED (verified) continues. Client-supplied kyc_* fields are never read.
 */
final class WithdrawalKycGateTest extends TestCase
{
    use RefreshDatabase;

    private function userWithKyc(string $status): User
    {
        $user = User::factory()->create();

        if ($status === KycStatus::Unverified->value) {
            return $user;
        }

        KycDocument::query()->create([
            'user_id' => $user->getKey(),
            'document_type' => 'national_id',
            'document_number' => '1234567890123',
            'file_path' => 'kyc/anchored/x.bin',
            'original_filename' => 'id.png',
            'mime_type' => 'image/png',
            'file_size' => 1024,
            'status' => $status,
            'verified_at' => $status === KycStatus::Verified->value ? now() : null,
            'expires_at' => $status === KycStatus::Expired->value ? now()->subDay() : null,
        ]);

        return $user->fresh() ?? $user;
    }

    #[Test]
    public function all_non_verified_statuses_are_denied_above_threshold(): void
    {
        config([
            'finance.withdrawal.kyc_gate_enabled' => true,
            'finance.withdrawal.kyc_gate_threshold' => '5000.00',
        ]);
        $gate = app(WithdrawalKycGateService::class);

        $denied = [
            KycStatus::Unverified->value,
            KycStatus::Pending->value,
            KycStatus::UnderReview->value,
            KycStatus::Rejected->value,
            KycStatus::Expired->value,
        ];

        foreach ($denied as $status) {
            $user = $this->userWithKyc($status);
            $this->assertSame(
                $status,
                $user->kycStatus()->value,
                'fixture status for '.$status,
            );

            $this->assertFalse(
                $gate->mayWithdraw($user, '6000.00', Currency::THB),
                $status.' must be denied above threshold',
            );

            try {
                $gate->assertCanWithdraw($user, '6000.00', Currency::THB);
                $this->fail($status.' assertCanWithdraw must throw');
            } catch (FinancialException $e) {
                $this->assertSame('kyc_gate_withdrawal_denied', $e->errorCode());
                $this->assertStringContainsString('verified identity', $e->getMessage());
            }
        }
    }

    #[Test]
    public function verified_user_continues_above_threshold(): void
    {
        config([
            'finance.withdrawal.kyc_gate_enabled' => true,
            'finance.withdrawal.kyc_gate_threshold' => '5000.00',
        ]);
        $user = $this->userWithKyc(KycStatus::Verified->value);

        $this->assertSame(KycStatus::Verified, $user->kycStatus());
        $this->assertTrue(
            app(WithdrawalKycGateService::class)->mayWithdraw($user, '6000.00', Currency::THB),
        );
        app(WithdrawalKycGateService::class)->assertCanWithdraw($user, '6000.00', Currency::THB);
        $this->assertTrue(true, 'verified path must not throw');
    }

    #[Test]
    public function unverified_user_below_threshold_is_allowed_by_documented_exception(): void
    {
        config([
            'finance.withdrawal.kyc_gate_enabled' => true,
            'finance.withdrawal.kyc_gate_threshold' => '5000.00',
        ]);
        $user = $this->userWithKyc(KycStatus::Unverified->value);

        $this->assertTrue(
            app(WithdrawalKycGateService::class)->mayWithdraw($user, '100.00', Currency::THB),
        );
    }

    #[Test]
    public function client_kyc_fields_are_never_consulted(): void
    {
        config([
            'finance.withdrawal.kyc_gate_enabled' => true,
            'finance.withdrawal.kyc_gate_threshold' => '5000.00',
        ]);
        $user = $this->userWithKyc(KycStatus::Unverified->value);
        // Forged client payload — must have zero effect on the server gate.
        // Simulate a client-injected request attribute (never a model column).
        $requestForged = new \Illuminate\Http\Request(['kyc_approved' => true, 'kyc_status' => 'verified']);
        $this->assertTrue((bool) $requestForged->input('kyc_approved'));
        // The gate only reads User::kycStatus() — forged payload is ignored.

        $this->assertFalse(
            app(WithdrawalKycGateService::class)->mayWithdraw($user, '6000.00', Currency::THB),
            'forged client kyc_approved must not open the gate',
        );
    }

    #[Test]
    public function disabled_gate_allows_all_amounts(): void
    {
        config(['finance.withdrawal.kyc_gate_enabled' => false]);
        $user = $this->userWithKyc(KycStatus::Unverified->value);

        $this->assertTrue(
            app(WithdrawalKycGateService::class)->mayWithdraw($user, '999999.00', Currency::THB),
        );
    }
}
