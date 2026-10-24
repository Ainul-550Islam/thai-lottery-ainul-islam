<?php

declare(strict_types=1);

namespace App\Services\Lottery;

/**
 * Public purchase capability for the dedicated GLO L6 home.
 *
 * The repository has an authoritative engine with wallet and ledger methods,
 * but no verified public product-to-draw-to-selection web/API contract that
 * this page may invoke. The public surface therefore fails closed and does not
 * expose a selection form, price button or checkout mutation.
 */
final class GloL6PurchaseCapabilityService
{
    /**
     * @return array{status: string, enabled: bool, reason: string, missing: list<string>}
     */
    public function capability(): array
    {
        return [
            'status' => 'NOT_CONFIGURED',
            'enabled' => false,
            'reason' => 'No verified public GLO L6 purchase endpoint is registered for this page.',
            'missing' => [
                'public product and draw contract',
                'selection inventory and validation contract',
                'responsible-gaming admission gate',
                'wallet reservation and debit endpoint',
                'ticket issuance, ledger and idempotency endpoint',
            ],
        ];
    }
}
