<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Models\DepositTransaction;
use App\Models\User;
use App\Services\Fraud\Rules\KycMismatchRule;
use App\Services\Fraud\Rules\RapidDepositWithdrawalRule;
use App\Services\Fraud\Rules\TransactionVelocityRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FraudRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_velocity_rule_triggers_on_excessive_hourly_volume(): void
    {
        $rule = new TransactionVelocityRule;
        $userId = 'user-velocity-test';

        $clean = $rule->evaluate('DEPOSIT', $userId, '1000.0000');
        $this->assertFalse($clean['triggered']);

        $excessive = $rule->evaluate('DEPOSIT', $userId, '550000.0000');
        $this->assertTrue($excessive['triggered']);
        $this->assertSame(45, $excessive['risk_points']);
        $this->assertSame('VELOCITY_HOURLY_EXCEEDED', $excessive['rule_id']);
    }

    public function test_rapid_deposit_withdrawal_rule_detects_layering(): void
    {
        $rule = new RapidDepositWithdrawalRule;
        $userId = (string) Str::uuid();

        DepositTransaction::create([
            'id' => (string) Str::uuid(),
            'deposit_number' => 'DEP-'.strtoupper(Str::random(8)),
            'user_id' => $userId,
            'gateway_provider' => 'PROMPTPAY',
            'amount' => '50000.0000',
            'fee_amount' => '0.0000',
            'net_amount' => '50000.0000',
            'currency' => 'THB',
            'status' => 'COMPLETED',
            'idempotency_key' => 'IDEMP-'.Str::random(12),
            'completed_at' => now()->subMinutes(5),
        ]);

        $result = $rule->evaluate('WITHDRAWAL', $userId, '50000.0000');
        $this->assertTrue($result['triggered']);
        $this->assertSame(60, $result['risk_points']);
        $this->assertSame('RAPID_DEPOSIT_WITHDRAWAL', $result['rule_id']);
    }

    public function test_kyc_mismatch_rule_flags_divergent_beneficiary_name(): void
    {
        $user = User::factory()->create(['name' => 'Somchai Jaidee']);
        $rule = new KycMismatchRule;

        $match = $rule->evaluate('WITHDRAWAL', (string) $user->id, '5000.0000', [
            'account_name' => 'Somchai Jaidee',
        ]);
        $this->assertFalse($match['triggered']);

        $mismatch = $rule->evaluate('WITHDRAWAL', (string) $user->id, '5000.0000', [
            'account_name' => 'Completely Different Person',
        ]);
        $this->assertTrue($mismatch['triggered']);
        $this->assertSame(50, $mismatch['risk_points']);
        $this->assertSame('BENEFICIARY_NAME_MISMATCH', $mismatch['rule_id']);
    }
}
