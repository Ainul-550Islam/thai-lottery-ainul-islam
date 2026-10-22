<?php

declare(strict_types=1);

namespace App\Services\Fraud\Rules;

use App\Models\DepositTransaction;
use Illuminate\Support\Facades\Cache;

/**
 * Class DuplicateReferenceRule
 *
 * Checks for reused provider transaction references or idempotency collision attacks.
 */
class DuplicateReferenceRule
{
    public function evaluate(string $eventType, string $userId, string $amount, array $context = []): array
    {
        $gatewayRef = $context['gateway_reference_id'] ?? null;

        if (is_string($gatewayRef) && $gatewayRef !== '') {
            $duplicateCount = DepositTransaction::where('gateway_reference_id', $gatewayRef)
                ->where('user_id', '!=', $userId)
                ->count();

            if ($duplicateCount > 0) {
                return [
                    'triggered' => true,
                    'rule_id' => 'CROSS_ACCOUNT_PAYMENT_COLLISION',
                    'risk_points' => 95,
                    'reason' => 'Payment reference was already claimed or used by a different account.',
                ];
            }
        }

        $externalRef = $context['reference_id'] ?? null;
        if (is_string($externalRef) && $externalRef !== '') {
            $cacheKey = 'fraud:seen_external_reference:'.sha1($eventType.':'.$externalRef);
            if (! Cache::add($cacheKey, $userId, 86400)) {
                return [
                    'triggered' => true,
                    'rule_id' => 'DUPLICATE_EXTERNAL_REFERENCE',
                    'risk_points' => 75,
                    'reason' => 'External transaction reference has already been submitted.',
                ];
            }
        }

        return ['triggered' => false, 'rule_id' => 'CROSS_ACCOUNT_PAYMENT_COLLISION', 'risk_points' => 0];
    }
}
