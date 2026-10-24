<?php

declare(strict_types=1);

namespace App\Services\Fraud;

use App\Models\FraudAlert;
use App\Services\Security\FraudAlertReviewService as CanonicalFraudAlertReviewService;

class FraudAlertReviewService
{
    /**
     * Delegate to canonical maker-checker-hardened review service so both
     * namespaces enforce identical separation-of-duties and audit guarantees.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function review(string $id, string $reviewerId, string $action, string $decisionReason, array $metadata = []): FraudAlert
    {
        return app(CanonicalFraudAlertReviewService::class)->review(
            alertId: $id,
            reviewerId: $reviewerId,
            action: $action,
            decisionReason: $decisionReason,
            metadata: $metadata,
        );
    }
}
