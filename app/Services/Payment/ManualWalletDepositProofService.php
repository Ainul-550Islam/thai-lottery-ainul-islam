<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Records the sender number and TrxID a player submits for a manual
 * bKash/Nagad deposit.
 *
 * THIS SERVICE NEVER MOVES MONEY. It stores a claim for an operator to verify
 * against the merchant account. The wallet is credited only by the existing
 * administrator completion step. A TrxID can be claimed once per method, so one
 * real payment cannot back two deposits (database unique index, race-safe).
 */
final class ManualWalletDepositProofService
{
    public const TABLE = 'manual_wallet_deposit_proofs';

    /**
     * @throws FinancialException
     */
    public function submit(User $user, string $depositIdentifier, string $senderNumber, string $trxId): Deposit
    {
        $sender = $this->normaliseNumber($senderNumber);
        if ($sender === null) {
            throw FinancialException::withCode('manual_proof_sender_invalid', 'The sender number must be a valid Bangladeshi mobile number.');
        }

        $trx = $this->normaliseTrxId($trxId);
        if ($trx === null) {
            throw FinancialException::withCode('manual_proof_trx_invalid', 'The TrxID must be 8 to 20 letters and digits.');
        }

        return DB::transaction(function () use ($user, $depositIdentifier, $sender, $trx): Deposit {
            $deposit = Deposit::query()
                ->where('user_id', $user->id)
                ->where(function ($query) use ($depositIdentifier): void {
                    $query->where('reference_number', $depositIdentifier)
                        ->orWhere('uuid', $depositIdentifier);
                })
                ->lockForUpdate()
                ->first();

            if (! $deposit instanceof Deposit) {
                throw FinancialException::withCode('deposit_not_found', 'Deposit not found.');
            }

            $method = $deposit->method;
            if (! in_array($method, [PaymentMethod::Bkash, PaymentMethod::Nagad], true)
                || $deposit->currency !== Currency::BDT
                || strtolower(trim((string) config('payment.gateways.'.$method->value.'.mode', 'api'))) !== 'manual') {
                throw FinancialException::withCode('manual_proof_not_applicable', 'This deposit does not accept a manual payment proof.');
            }

            if ($deposit->status !== DepositStatus::Pending || $deposit->financial_transaction_id !== null) {
                throw FinancialException::withCode('manual_proof_deposit_not_pending', 'Payment proof can only be submitted while the deposit is pending.');
            }

            $existing = DB::table(self::TABLE)->where('deposit_id', $deposit->id)->first();
            if ($existing !== null) {
                if ((string) $existing->sender_number === $sender && (string) $existing->trx_id === $trx) {
                    return $deposit; // Same claim again: idempotent, nothing changes.
                }

                throw FinancialException::withCode('manual_proof_already_submitted', 'A payment proof was already submitted for this deposit.');
            }

            if (DB::table(self::TABLE)->where('method', $method->value)->where('trx_id', $trx)->exists()) {
                throw FinancialException::withCode('manual_proof_trx_used', 'This TrxID has already been used.');
            }

            $now = now();
            try {
                DB::table(self::TABLE)->insert([
                    'deposit_id' => $deposit->id,
                    'user_id' => $user->id,
                    'method' => $method->value,
                    'sender_number' => $sender,
                    'trx_id' => $trx,
                    'submitted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (QueryException) {
                // A concurrent submission claimed the same TrxID first.
                throw FinancialException::withCode('manual_proof_trx_used', 'This TrxID has already been used.');
            }

            $metadata = is_array($deposit->metadata) ? $deposit->metadata : [];
            $metadata['manual_proof'] = [
                'method' => $method->value,
                'sender_number' => $sender,
                'trx_id' => $trx,
                'submitted_at' => $now->toIso8601String(),
            ];
            $deposit->metadata = $metadata;
            $deposit->save();

            return $deposit;
        });
    }

    private function normaliseNumber(string $value): ?string
    {
        $digits = preg_replace('/[\s\-]/', '', trim($value)) ?? '';
        if (str_starts_with($digits, '+880')) {
            $digits = '0'.substr($digits, 4);
        } elseif (str_starts_with($digits, '880') && strlen($digits) === 13) {
            $digits = '0'.substr($digits, 3);
        }

        return preg_match('/\A01[3-9][0-9]{8}\z/D', $digits) === 1 ? $digits : null;
    }

    private function normaliseTrxId(string $value): ?string
    {
        $trx = strtoupper(trim($value));

        return preg_match('/\A[A-Z0-9]{8,20}\z/D', $trx) === 1 ? $trx : null;
    }
}
