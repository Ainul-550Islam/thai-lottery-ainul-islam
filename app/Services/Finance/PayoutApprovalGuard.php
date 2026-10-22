<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DTOs\Finance\PayoutRequestData;
use App\Enums\PrizePayoutMethod;
use App\Enums\UserStatus;
use App\Exceptions\PayoutTransferException;
use App\Models\Payout;
use App\Models\User;
use App\Services\Payment\PayoutTransferAccess;
use App\Services\Payment\WithdrawalProviderAccountService;
use App\Services\Prize\PrizeClaimGuard;

/** Immutable approval facts; no gateway, ledger or wallet mutation. */
final class PayoutApprovalGuard
{
    public function method(Payout $row): PrizePayoutMethod
    {
        $value = $row->metadata['claim']['payout_method'] ?? null;
        $method = is_string($value) ? PrizePayoutMethod::tryFrom($value) : null;
        if (! $method || ! $method->supportsCurrency($row->currency)) {
            $this->refuse($row);
        }

        return $method;
    }

    public function beneficiary(Payout $row): array
    {
        $method = $this->method($row);
        $target = $row->metadata['claim']['beneficiary'] ?? null;
        if ($method === PrizePayoutMethod::Wallet) {
            return [];
        }
        $required = $method === PrizePayoutMethod::BankTransfer
            ? ['bank_name', 'account_name', 'account_number'] : ['payee_name', 'address'];
        $allowed = array_merge($required, ['bank_code', 'branch', 'routing_number', 'iban', 'swift', 'country']);
        if (! is_array($target) || array_diff(array_keys($target), $allowed)
            || array_diff($required, array_keys($target))
            || array_any($target, fn ($v) => ! is_string($v) || trim($v) === '' || strlen($v) > 500 || preg_match('/[\x00-\x1f\x7f]/', $v) === 1)) {
            $this->refuse($row);
        }
        ksort($target);

        return $target;
    }

    public function fingerprint(Payout $row): string
    {
        $method = $this->method($row);
        $scope = $method === PrizePayoutMethod::Wallet ? null
            : app(WithdrawalProviderAccountService::class)->current($method === PrizePayoutMethod::Cheque ? 'manual' : 'bank_transfer');

        return hash('sha256', json_encode([(int) $row->id, $row->reference_number, (int) $row->user_id,
            $row->currency->value, (string) $row->amount, $method->value, $this->beneficiary($row), $scope], JSON_THROW_ON_ERROR));
    }

    public function assertBeneficiary(Payout $row): void
    {
        $owner = User::query()->find($row->user_id);
        if (! $owner || $owner->status !== UserStatus::Active || ($this->method($row)->requiresVerifiedKyc() && ! $owner->kycStatus()->isVerified())) {
            $this->refuse($row);
        }
    }

    public function assertApproved(Payout $row, bool $completed = false): array
    {
        app(PrizeClaimGuard::class)->assertPayableClaim($row);
        $a = $row->metadata['approval'] ?? null;
        if (! is_array($a) || ($a['status'] ?? null) !== ($completed ? 'completed' : 'approved')
            || ! is_string($a['facts_hash'] ?? null) || ! hash_equals($this->fingerprint($row), $a['facts_hash'])
            || ($a['request_key'] ?? null) !== PayoutRequestData::deriveRequestKey($row->reference_number, (string) $row->amount, $row->currency, (int) $row->user_id)
            || ! is_int($a['maker_user_id'] ?? null) || ! is_int($a['checker_user_id'] ?? null)
            || $a['maker_user_id'] === $a['checker_user_id'] || in_array((int) $row->user_id, [$a['maker_user_id'], $a['checker_user_id']], true)) {
            $this->refuse($row);
        }
        app(PayoutTransferAccess::class)->authorize(User::query()->find($a['maker_user_id']), PayoutTransferAccess::REQUEST);
        app(PayoutTransferAccess::class)->authorize(User::query()->find($a['checker_user_id']), PayoutTransferAccess::APPROVE);
        $this->assertBeneficiary($row);

        return $a;
    }

    private function refuse(Payout $row): never
    {
        throw PayoutTransferException::gatewayRefused($row->reference_number, 'Approval, original destination, beneficiary authority or currency requires controlled review.');
    }
}
