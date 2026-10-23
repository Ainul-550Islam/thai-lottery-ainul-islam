<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Exceptions\FinancialException;
use App\Models\Deposit;

/**
 * Legacy refund service isolated behind ExternalRefundPolicy.
 *
 * Confirmed deposit refunds require an authenticated provider-specific
 * recovery workflow (such as StripeRefundRecoveryService) rather than an
 * unverified local reversal or operator reason string.
 */
class RefundService
{
    public function __construct(
        private readonly ?ExternalRefundPolicy $policy = null,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws FinancialException
     */
    public function refundDeposit(int $depositId, ?int $actorUserId = null, ?string $reason = null): array
    {
        ($this->policy ?? app(ExternalRefundPolicy::class))->refuse();
    }

    public function canRefund(Deposit $deposit): bool
    {
        return false;
    }
}
