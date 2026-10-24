<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\FraudAlert;
use App\Models\FraudCase;
use App\Services\Audit\AuditLogService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FraudAlertReviewService
{
    public function __construct(private readonly AuditLogService $audit) {}

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
        return DB::transaction(function () use ($alertId, $reviewerId, $action, $decisionReason, $metadata): FraudAlert {
            $alert = FraudAlert::where('id', $alertId)->lockForUpdate()->firstOrFail();

            // A terminal adjudication is final. Allowing a second reviewer to flip
            // RESOLVED_DENY to RESOLVED_ALLOW would erase the first decision's meaning
            // (and its audit trail) with a later write.
            $currentStatus = strtoupper((string) $alert->status);
            if (str_starts_with($currentStatus, 'RESOLVED_')) {
                throw new DomainException(
                    'This fraud alert is already resolved; a terminal adjudication cannot be reversed here.',
                );
            }

            // Maker-checker: the account whose transaction raised the alert may never
            // adjudicate that alert. Without this guard a compromised or colluding
            // subject could release its own hold, and the four-eyes property the
            // review flow claims would be nominal only.
            if ((string) $alert->user_id === $reviewerId) {
                throw new DomainException(
                    'Maker-checker separation of duties: the account under review cannot adjudicate its own alert.',
                );
            }

            $resolvedStatus = match ($action) {
                'RELEASE_ALLOW' => 'RESOLVED_ALLOW',
                'REJECT_DENY' => 'RESOLVED_DENY',
                'FREEZE_ACCOUNT' => 'RESOLVED_ACCOUNT_FROZEN',
                default => 'RESOLVED_CLOSED',
            };

            $alert->update([
                'status' => $resolvedStatus,
                'resolved_by_user_id' => $reviewerId,
                'resolution_notes' => $decisionReason,
                'resolved_at' => now(),
            ]);

            // The AuditLog entry is the durable, human-readable record of who decided
            // what. Reviewer metadata is passed through AuditLogService::log(), which
            // redacts PII (bank account, national ID, tokens) before persistence, so the
            // audit trail can be retained without retaining the identifiers themselves.
            $this->audit->log(
                userId: (int) $reviewerId,
                action: 'fraud_alert_reviewed',
                riskLevel: 'high',
                auditable: $alert,
                description: 'Fraud alert adjudicated by a separate reviewer.',
                metadata: [
                    'alert_id' => (string) $alert->id,
                    'action' => $action,
                    'resolved_status' => $resolvedStatus,
                    'reviewer_metadata' => $metadata,
                ],
            );

            FraudCase::create([
                'id' => (string) Str::uuid(),
                'fraud_alert_id' => $alert->id,
                'reviewer_user_id' => $reviewerId,
                'action_taken' => $action,
                'decision' => $resolvedStatus,
                'decision_reason' => $decisionReason,
                'metadata' => $metadata,
                'decided_at' => now(),
            ]);

            return $alert->fresh();
        });
    }
}
