<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\FraudDecision;
use App\Events\FraudReviewRequired;
use App\Services\Fraud\FraudDetectionService;
use App\Services\Fraud\FraudEventRecorder;
use App\Services\Fraud\FraudRuleEngine;
use App\Services\Fraud\Rules\DuplicateReferenceRule;
use App\Services\Fraud\Rules\RapidDepositWithdrawalRule;
use App\Services\Fraud\Rules\TransactionVelocityRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class FraudDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private FraudDetectionService $fraudService;

    protected function setUp(): void
    {
        parent::setUp();

        $rules = [
            new TransactionVelocityRule,
            new RapidDepositWithdrawalRule,
            new DuplicateReferenceRule,
        ];

        $this->fraudService = new FraudDetectionService(
            new FraudRuleEngine($rules),
            new FraudEventRecorder
        );
    }

    public function test_evaluates_clean_transaction_as_allow(): void
    {
        $payload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'DEPOSIT',
            'user_id' => 'user-123',
            'amount' => '500.0000',
            'currency' => 'THB',
            'client_ip' => '203.0.113.1',
            'reference_id' => 'DEP-10001',
        ];

        $decision = $this->fraudService->evaluateTransactionRisk($payload);

        $this->assertSame(FraudDecision::ALLOW, $decision->decision);
        $this->assertLessThan(60, $decision->riskScore);
    }

    public function test_detects_duplicate_reference_and_requires_review(): void
    {
        Event::fake([FraudReviewRequired::class]);

        $payload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'DEPOSIT',
            'user_id' => 'user-456',
            'amount' => '10000.0000',
            'currency' => 'THB',
            'client_ip' => '203.0.113.2',
            'reference_id' => 'REUSED-REF-999',
        ];

        // First evaluation passes
        $this->fraudService->evaluateTransactionRisk($payload);

        // Second evaluation with identical reference_id should trigger duplicate reference rule
        $secondPayload = array_merge($payload, ['event_id' => (string) Str::uuid()]);
        $decision = $this->fraudService->evaluateTransactionRisk($secondPayload);

        $this->assertTrue(in_array('DUPLICATE_EXTERNAL_REFERENCE', $decision->reasonCodes, true));
        $this->assertGreaterThanOrEqual(60, $decision->riskScore);
        $this->assertDatabaseHas('fraud_alerts', [
            'user_id' => 'user-456',
        ]);
    }
}
