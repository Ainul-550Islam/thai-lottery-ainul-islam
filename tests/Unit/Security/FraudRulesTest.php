<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Services\Fraud\Rules\AccountLinkageRule;
use App\Services\Fraud\Rules\DuplicateReferenceRule;
use App\Services\Fraud\Rules\KycMismatchRule;
use App\Services\Fraud\Rules\RapidDepositWithdrawalRule;
use App\Services\Fraud\Rules\SuspiciousPayoutChangeRule;
use App\Services\Fraud\Rules\TransactionVelocityRule;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\TestCase;

class FraudRulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_transaction_velocity_rule_triggers_on_excessive_frequency(): void
    {
        $rule = new TransactionVelocityRule();
        $userId = 'user-velocity-test';

        $payload = [
            'user_id' => $userId,
            'amount' => '100.0000',
        ];

        // First 9 transactions pass below threshold
        for ($i = 0; $i < 9; $i++) {
            $result = $rule->evaluate($payload);
            $this->assertFalse($result['triggered']);
        }

        // 10th transaction in 60s window triggers high velocity
        $tenth = $rule->evaluate($payload);
        $this->assertTrue($tenth['triggered']);
        $this->assertSame(45, $tenth['score']);
        $this->assertSame('HIGH_TRANSACTION_VELOCITY', $tenth['reason_code']);
    }

    public function test_rapid_deposit_withdrawal_rule_detects_layering(): void
    {
        $rule = new RapidDepositWithdrawalRule();
        $userId = 'user-layering-test';

        // Simulate deposit recorded 2 minutes ago
        Cache::put("user:{$userId}:last_deposit_timestamp", now()->subMinutes(2)->timestamp, 3600);

        // Immediate withdrawal attempt
        $payload = [
            'event_type' => 'WITHDRAWAL',
            'user_id' => $userId,
            'amount' => '50000.0000',
        ];

        $result = $rule->evaluate($payload);
        $this->assertTrue($result['triggered']);
        $this->assertSame(50, $result['score']);
        $this->assertSame('RAPID_DEPOSIT_TO_WITHDRAWAL', $result['reason_code']);
    }

    public function test_kyc_mismatch_rule(): void
    {
        $rule = new KycMismatchRule();

        $payloadWithMismatch = [
            'kyc_verified' => false,
            'amount' => '250000.0000', // exceeds unverified threshold
        ];

        $result = $rule->evaluate($payloadWithMismatch);
        $this->assertTrue($result['triggered']);
        $this->assertSame(65, $result['score']);
        $this->assertSame('UNVERIFIED_HIGH_VALUE_TRANSACTION', $result['reason_code']);
    }
}
