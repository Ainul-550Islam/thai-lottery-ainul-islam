<?php

declare(strict_types=1);

namespace App\Services\Transfer;

use App\DTOs\Transfer\TransferOutcome;
use App\Enums\AuditAction;
use App\Enums\TransferDirection;
use App\Enums\TransferStatus;
use App\Exceptions\BalanceTransferException;
use App\Models\AuditLog;
use App\Models\BalanceTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Operator-authorized compensating reversal for a completed balance transfer.
 *
 * Never mutates or deletes the original transfer row or its ledger entries:
 * executes an append-only compensating transfer in the opposite direction
 * and links the reversal row via metadata (`reverses_transfer_id` /
 * `reverses_reference`).
 */
final class BalanceTransferReversalService
{
    public function __construct(
        private readonly BalanceTransferService $transfers,
    ) {}

    public function reverse(
        BalanceTransfer $original,
        User $operator,
        string $reason,
        ?string $idempotencyKey = null,
    ): TransferOutcome {
        $cleanReason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        if (mb_strlen($cleanReason) < 10) {
            throw new BalanceTransferException(
                machineCode: 'transfer_reversal_reason_required',
                message: 'A reversal reason of at least 10 characters is required.',
                httpStatus: 422,
            );
        }

        return DB::transaction(function () use ($original, $operator, $cleanReason, $idempotencyKey): TransferOutcome {
            /** @var BalanceTransfer $locked */
            $locked = BalanceTransfer::query()->lockForUpdate()->findOrFail($original->getKey());

            if ($locked->status !== TransferStatus::Completed) {
                throw new BalanceTransferException(
                    machineCode: 'transfer_not_reversible',
                    message: 'Only completed transfers can be reversed.',
                    httpStatus: 409,
                    context: ['reference' => (string) $locked->reference_number],
                );
            }

            $meta = is_array($locked->metadata) ? $locked->metadata : [];
            if (! empty($meta['reversed_by_transfer_id']) || ! empty($meta['is_reversal'])) {
                throw new BalanceTransferException(
                    machineCode: 'transfer_already_reversed',
                    message: 'This transfer has already been reversed or is itself a reversal.',
                    httpStatus: 409,
                    context: ['reference' => (string) $locked->reference_number],
                );
            }

            /** @var User $player */
            $player = User::query()->findOrFail($locked->user_id);

            $reverseDirection = $locked->direction === TransferDirection::CashToWin
                ? TransferDirection::WinToCash
                : TransferDirection::CashToWin;

            $effectiveKey = $idempotencyKey !== null && trim($idempotencyKey) !== ''
                ? trim($idempotencyKey)
                : 'reverse:'.$locked->reference_number;

            $outcome = $this->transfers->transfer(
                user: $player,
                direction: $reverseDirection,
                amount: (string) $locked->amount,
                idempotencyKey: $effectiveKey,
                requestContext: [
                    'metadata' => [
                        'is_reversal' => true,
                        'reverses_transfer_id' => (int) $locked->getKey(),
                        'reverses_reference' => (string) $locked->reference_number,
                        'reversed_by_operator_id' => (int) $operator->getAuthIdentifier(),
                        'reversal_reason' => $cleanReason,
                    ],
                ],
            );

            $reversalRow = $outcome->transfer;
            $meta['reversed_by_transfer_id'] = (int) $reversalRow->getKey();
            $meta['reversed_by_reference'] = (string) $reversalRow->reference_number;
            $meta['reversed_by_operator_id'] = (int) $operator->getAuthIdentifier();
            $meta['reversal_reason'] = $cleanReason;
            $locked->metadata = $meta;
            $locked->save();

            AuditLog::query()->create([
                'user_id' => (int) $operator->getAuthIdentifier(),
                'action' => AuditAction::Update,
                'auditable_type' => BalanceTransfer::class,
                'auditable_id' => (int) $locked->getKey(),
                'old_values' => ['reversed_by_transfer_id' => null],
                'new_values' => [
                    'reversed_by_transfer_id' => (int) $reversalRow->getKey(),
                    'reversed_by_reference' => (string) $reversalRow->reference_number,
                    'reason' => $cleanReason,
                ],
            ]);

            return $outcome;
        });
    }
}
