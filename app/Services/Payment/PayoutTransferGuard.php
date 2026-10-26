<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\PayoutTransferData;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\PrizePayoutMethod;
use App\Exceptions\PayoutTransferException;
use App\Models\Payment;
use App\Models\Payout;
use App\Services\Finance\PayoutApprovalGuard;
use App\Services\Finance\PayoutBatchMembershipService;

final class PayoutTransferGuard
{
    public function identity(Payout $row, PayoutTransferData $data, bool $verifyKyc = true): void
    {
        $guard = app(PayoutApprovalGuard::class);
        $target = $data->beneficiary;
        if (is_array($target)) {
            ksort($target);
        }
        if (config('finance.prize_payout.safety_mode') !== 'LIVE' || ! $data->amountIsWellFormed() || strlen($data->amount) > 21 || bccomp($data->amount, '0', 2) <= 0
            || $data->payoutReference !== $row->reference_number || $data->beneficiaryUserId !== (int) $row->user_id
            || $data->currency !== $row->currency || bccomp($data->amount, (string) $row->amount, 2) !== 0
            || $data->method === PrizePayoutMethod::Wallet || $data->method !== $guard->method($row)
            || $target !== $guard->beneficiary($row) || ($row->metadata['batch']['batch_key'] ?? null) !== $data->batchKey
            || $data->batchKey === '' || strlen($data->batchKey) > 255
            || $data->idempotencyKey !== PayoutTransferData::deriveIdempotencyKey($data->batchKey, $row->reference_number, (int) $row->user_id, (string) $row->amount, $row->currency)) {
            $this->refuse($row);
        }
        $guard->assertApproved($row, $row->status === PayoutStatus::Completed, $verifyKyc);
        app(PayoutBatchMembershipService::class)->assertMember($row);
    }

    public function account(Payout $row): string
    {
        return app(WithdrawalProviderAccountService::class)->current($this->gateway($row));
    }

    public function gateway(Payout $row): string
    {
        return app(PayoutApprovalGuard::class)->method($row) === PrizePayoutMethod::Cheque ? 'manual' : 'bank_transfer';
    }

    public function payment(Payout $row, array $record): Payment
    {
        $payments = Payment::withTrashed()->where('payable_type', Payout::class)->where('payable_id', $row->id)->lockForUpdate()->limit(2)->get();
        $p = $payments->first();
        $expected = match ($record['lane_status'] ?? null) {
            'open' => PaymentStatus::Pending, 'completed' => PaymentStatus::Captured, 'failed' => PaymentStatus::Failed,
            default => null,
        };
        if ($payments->count() !== 1 || ! $p || $p->trashed() || ! $expected || $p->status !== $expected
            || (int) $p->user_id !== (int) $row->user_id || $p->currency !== $row->currency
            || bccomp((string) $p->amount, (string) $row->amount, 2) !== 0 || bccomp((string) $p->fee, '0', 2) !== 0
            || $p->gateway !== $this->gateway($row) || $p->method->value !== $this->gateway($row)
            || $p->reference_number !== ($record['payment_reference'] ?? null)
            || ($p->metadata['idempotency_key'] ?? null) !== ($record['idempotency_key'] ?? null)
            || ($record['facts_hash'] ?? null) !== app(PayoutApprovalGuard::class)->fingerprint($row)
            || ($record['account_scope'] ?? null) !== $this->account($row)
            || ($p->metadata['account_scope'] ?? null) !== $record['account_scope']
            || ($record['gateway_reference'] ?? null) !== $p->gateway_reference) {
            $this->refuse($row);
        }

        return $p;
    }

    public function fromRow(Payout $row): PayoutTransferData
    {
        $guard = app(PayoutApprovalGuard::class);
        $batch = $row->metadata['batch']['batch_key'] ?? '';
        if (! is_string($batch)) {
            $this->refuse($row);
        }

        return new PayoutTransferData($batch, $row->reference_number, (int) $row->user_id, $guard->method($row),
            (string) $row->amount, $row->currency, $guard->beneficiary($row),
            PayoutTransferData::deriveIdempotencyKey($batch, $row->reference_number, (int) $row->user_id, (string) $row->amount, $row->currency));
    }

    public function refuse(Payout $row): never
    {
        throw PayoutTransferException::gatewayRefused($row->reference_number, 'Transfer identity, immutable approval, Payment or account context is incompatible; no resend or automatic repair.');
    }
}
