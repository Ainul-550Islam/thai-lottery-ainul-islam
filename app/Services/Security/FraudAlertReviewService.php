<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Models\FraudAlert;
use App\Models\FraudCase;
use App\Services\Audit\AuditLogService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FraudAlertReviewService
{
    private const ALLOWED_ACTIONS = [
        'RELEASE_ALLOW' => 'RESOLVED_ALLOW',
        'REJECT_DENY' => 'RESOLVED_DENY',
        'FREEZE_ACCOUNT' => 'RESOLVED_ACCOUNT_FROZEN',
        'CLOSE_FALSE_POSITIVE' => 'RESOLVED_CLOSED',
    ];

    public function __construct(
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function review(
        string $alertId,
        string $reviewerId,
        string $action,
        string $decisionReason,
        array $metadata = [],
    ): FraudAlert {
        $normalizedReviewer = trim($reviewerId);
        if ($normalizedReviewer === '') {
            throw new DomainException('Fraud alert review requires an authenticated reviewer identity.');
        }

        $normalizedReason = trim($decisionReason);
        if ($normalizedReason === '') {
            throw new DomainException('Fraud alert review requires a non-empty decision reason.');
        }

        $normalizedAction = strtoupper(trim($action));
        $resolvedStatus = self::ALLOWED_ACTIONS[$normalizedAction] ?? null;
        if ($resolvedStatus === null) {
            throw new DomainException('Unsupported fraud alert review action.');
        }

        return DB::transaction(function () use (
            $alertId,
            $normalizedReviewer,
            $normalizedAction,
            $resolvedStatus,
            $normalizedReason,
            $metadata,
        ): FraudAlert {
            $alert = FraudAlert::where('id', $alertId)->lockForUpdate()->firstOrFail();

            if ($alert->user_id !== null && trim((string) $alert->user_id) !== '' && (string) $alert->user_id === $normalizedReviewer) {
                throw new DomainException('Maker-checker violation: alert subject cannot adjudicate their own fraud alert.');
            }

            $previousStatus = (string) $alert->status;
            if (str_starts_with($previousStatus, 'RESOLVED_')) {
                throw new DomainException('Terminal fraud alerts are immutable and cannot be re-adjudicated.');
            }

            $sanitizedMetadata = $this->auditLog->redact($metadata);

            $alert->update([
                'status' => $resolvedStatus,
                'resolved_by_user_id' => $normalizedReviewer,
                'resolution_notes' => $normalizedReason,
                'resolved_at' => now(),
            ]);

            FraudCase::create([
                'id' => (string) Str::uuid(),
                'fraud_alert_id' => $alert->id,
                'reviewer_user_id' => $normalizedReviewer,
                'action_taken' => $normalizedAction,
                'decision' => $resolvedStatus,
                'decision_reason' => $normalizedReason,
                'metadata' => $sanitizedMetadata,
                'decided_at' => now(),
            ]);

            $reviewerUserId = ctype_digit($normalizedReviewer) && (int) $normalizedReviewer > 0
                ? (int) $normalizedReviewer
                : null;

            $this->auditLog->log(
                userId: $reviewerUserId,
                action: AuditAction::Update,
                riskLevel: RiskLevel::High,
                auditable: $alert,
                description: sprintf('Fraud alert %s adjudicated with %s (%s).', $alert->id, $normalizedAction, $resolvedStatus),
                metadata: [
                    'alert_id' => (string) $alert->id,
                    'subject_user_id' => $alert->user_id,
                    'action' => $normalizedAction,
                    'decision_reason' => $normalizedReason,
                    'reviewer_metadata' => $sanitizedMetadata,
                ],
                oldValues: ['status' => $previousStatus],
                newValues: ['status' => $resolvedStatus, 'resolved_by_user_id' => $normalizedReviewer],
            );

            return $alert->fresh();
        });
    }
}
