<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\DTOs\Compliance\AmlRiskAssessmentData;
use App\Enums\AmlRiskLevel;
use App\Exceptions\AmlRiskAssessmentException;
use App\Models\AmlRiskAssessment;
use App\Models\User;

/**
 * Universal Compliance AML Risk Service Orchestrator.
 *
 * Provides a clean interface for AML risk scoring, tier enforcement,
 * transaction gating, and compliance assessment retrieval.
 */
class AmlRiskService
{
    public function __construct(
        private readonly AmlRiskAssessmentService $assessmentService,
    ) {
    }

    /**
     * Compute real-time live AML risk facts and score for a user.
     *
     * @return array{evidence: array<string, mixed>, score: int, level: AmlRiskLevel, reason_codes: array<int, string>}
     */
    public function measure(int $userId): array
    {
        return $this->assessmentService->measure($userId);
    }

    /**
     * Pronounce and record the deterministic AML risk assessment for a user.
     *
     * @return array{assessment: AmlRiskAssessment, replayed: bool, superseded: bool}
     */
    public function pronounce(int $userId): array
    {
        return $this->assessmentService->pronounce($userId);
    }

    /**
     * Assert that the user's AML assessment matches a claimed fingerprint.
     */
    public function assertCurrent(int $userId, string $claimedEvidenceFingerprint): AmlRiskAssessment
    {
        return $this->assessmentService->assertCurrent($userId, $claimedEvidenceFingerprint);
    }

    /**
     * Determine if a user exceeds the allowable risk threshold for high-value financial actions.
     */
    public function isHighRisk(int $userId): bool
    {
        $result = $this->assessmentService->measure($userId);

        return $result['level'] === AmlRiskLevel::High || $result['level'] === AmlRiskLevel::Prohibited;
    }

    /**
     * Check if a user is completely blocked from financial transactions due to AML sanctions/prohibitions.
     */
    public function isProhibited(int $userId): bool
    {
        $result = $this->assessmentService->measure($userId);

        return $result['level'] === AmlRiskLevel::Prohibited;
    }
}
