<?php

declare(strict_types=1);

namespace App\Services\Fraud;

use App\Models\FraudAlert;
use App\Models\FraudCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FraudAlertReviewService
{
    /**
     * Review a fraud alert atomically.
     *
     * @return FraudAlert
     */
    public function review(string $id, string $reviewerId, string $action, string $decisionReason, array $metadata = []): FraudAlert
    {
        return DB::transaction(function () use ($id, $reviewerId, $action, $decisionReason, $metadata) {
            /** @var FraudAlert $alert */
            $alert = FraudAlert::where('id', $id)->lockForUpdate()->firstOrFail();

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
