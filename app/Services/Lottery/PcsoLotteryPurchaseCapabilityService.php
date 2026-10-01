<?php

declare(strict_types=1);

namespace App\Services\Lottery;

/**
 * Fail-closed capability report for the PCSO purchase page.
 *
 * The repository contains PCSO result infrastructure, but it does not expose
 * a verified PCSO product-to-draw-to-selection-to-wallet purchase contract.
 * This service therefore publishes a disabled capability instead of inventing
 * a price, balance rule, reservation, ticket issuer or checkout endpoint.
 */
final class PcsoLotteryPurchaseCapabilityService
{
    /**
     * @return array{status: string, enabled: bool, reason: string, missing: list<string>}
     */
    public function capability(): array
    {
        return [
            'status' => 'NOT_CONFIGURED',
            'enabled' => false,
            'reason' => 'No complete PCSO purchase contract is verified for this application.',
            'missing' => [
                'product definition',
                'draw inventory',
                'selection validation',
                'authoritative price',
                'responsible gaming gate',
                'wallet debit',
                'reservation and ticket issuance',
                'purchase ledger and idempotency record',
            ],
        ];
    }
}
