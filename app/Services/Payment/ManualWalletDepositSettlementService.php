<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserStatus;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\WalletLockService;
use App\Support\Admin\AdminAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Staff settlement of a manual bKash/Nagad deposit.
 *
 * The player's claim (sender number and TrxID, see ManualWalletDepositProofService)
 * is evidence for a human, never settlement authority. Staff open the merchant app,
 * find the payment, and type the TrxID they actually saw. Only when that equals the
 * TrxID on file, and the staff member holds the payout duty, does this service mark
 * the deposit's Payment captured and hand the deposit to the canonical
 * DepositCompletionService, which posts the balanced double entry and moves the
 * balance. All of it is one database transaction: a refusal leaves nothing behind,
 * and a replay credits nothing twice.
 */
final class ManualWalletDepositSettlementService
{
    public const MARKER = 'manual_wallet_settlement';

    /** Whether this deposit is one the manual rail can settle (used to offer the admin action). */
    public static function appliesTo(Deposit $deposit): bool
    {
        return in_array($deposit->method, [PaymentMethod::Bkash, PaymentMethod::Nagad], true)
            && $deposit->currency === Currency::BDT
            && strtolower(trim((string) config('payment.gateways.'.$deposit->method->value.'.mode', 'api'))) === 'manual'
            && $deposit->financial_transaction_id === null
            && in_array($deposit->status, [DepositStatus::Pending, DepositStatus::Approved], true)
            && DB::table(ManualWalletDepositProofService::TABLE)->where('deposit_id', $deposit->id)->exists();
    }

    /**
     * @return array{deposit: Deposit, credited: bool, replayed: bool, amount: string|null}
     *
     * @throws AuthorizationException
     * @throws FinancialException
     */
    public function settle(Deposit $route, ?User $actor, string $verifiedTrxId): array
    {
        $staff = $this->authorize($actor);

        $trx = strtoupper(trim($verifiedTrxId));
        if (preg_match('/\A[A-Z0-9]{8,20}\z/D', $trx) !== 1) {
            throw FinancialException::withCode('manual_settlement_trx_invalid', 'Enter the TrxID exactly as shown in the merchant app.');
        }

        return DB::transaction(function () use ($route, $staff, $trx): array {
            // Lock order matches the canonical services: wallet first, then the deposit.
            app(WalletLockService::class)->lock((int) $route->wallet_id);
            $row = Deposit::query()->whereKey($route->getKey())->lockForUpdate()->first();
            if (! $row instanceof Deposit) {
                throw FinancialException::withCode('deposit_not_found', 'Deposit not found.');
            }

            if ($row->status === DepositStatus::Confirmed) {
                app(DepositCompletionService::class)->verifySettled($row);

                return ['deposit' => $row, 'credited' => false, 'replayed' => true, 'amount' => (string) $row->amount];
            }

            if (! in_array($row->method, [PaymentMethod::Bkash, PaymentMethod::Nagad], true)
                || $row->currency !== Currency::BDT
                || strtolower(trim((string) config('payment.gateways.'.$row->method->value.'.mode', 'api'))) !== 'manual') {
                throw FinancialException::withCode('manual_settlement_not_applicable', 'This deposit is not a manual bKash/Nagad deposit.');
            }

            if (! in_array($row->status, [DepositStatus::Pending, DepositStatus::Approved], true) || $row->financial_transaction_id !== null) {
                throw FinancialException::withCode('manual_settlement_not_settleable', 'This deposit can no longer be settled.');
            }

            $proof = DB::table(ManualWalletDepositProofService::TABLE)->where('deposit_id', $row->id)->lockForUpdate()->first();
            if ($proof === null) {
                throw FinancialException::withCode('manual_settlement_no_proof', 'The player has not submitted a sender number and TrxID yet.');
            }
            if (! hash_equals((string) $proof->trx_id, $trx)) {
                throw FinancialException::withCode('manual_settlement_trx_mismatch', 'The TrxID you verified does not match the one the player submitted. Nothing was credited.');
            }

            $payments = Payment::query()
                ->where('payable_type', Deposit::class)
                ->where('payable_id', $row->id)
                ->lockForUpdate()
                ->get();
            $payment = $payments->count() === 1 ? $payments->first() : null;
            if (! $payment instanceof Payment
                || ! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Authorized], true)
                || $row->provider_reference === null
                || $payment->gateway_reference !== $row->provider_reference
                || $payment->method !== $row->method
                || (int) $payment->user_id !== (int) $row->user_id) {
                throw FinancialException::withCode('manual_settlement_payment_unproven', 'The checkout record for this deposit cannot be proven. Nothing was credited.');
            }

            if ($row->status === DepositStatus::Pending) {
                app(DepositApprovalService::class)->approve($row, (int) $staff->id, 'TrxID verified by staff in the merchant app.');
                $row->refresh();
            }

            $payment->status = PaymentStatus::Captured;
            $payment->captured_at = now();
            $metadata = is_array($payment->metadata) ? $payment->metadata : [];
            $metadata[self::MARKER] = ['trx_id' => $trx, 'actor_id' => (int) $staff->id, 'at' => now()->toIso8601String()];
            $payment->metadata = $metadata;
            $payment->save();

            $outcome = app(DepositCompletionService::class)->complete($row, null, [
                'provider' => $row->method->value,
                'provider_reference' => (string) $row->provider_reference,
                'actor_user_id' => (int) $staff->id,
                'description' => 'Manual '.$row->method->value.' deposit credited after TrxID verification',
                'metadata' => ['manual_wallet_trx_id' => $trx, 'settled_by' => (int) $staff->id],
            ]);

            return [
                'deposit' => $row->fresh(),
                'credited' => ($outcome['credited'] ?? true) === true,
                'replayed' => ($outcome['replayed'] ?? false) === true,
                'amount' => isset($outcome['amount']) ? (string) $outcome['amount'] : null,
            ];
        });
    }

    private function authorize(?User $actor): User
    {
        $current = $actor instanceof User ? User::query()->useWritePdo()->find($actor->getKey()) : null;
        if (! $current instanceof User
            || $current->status !== UserStatus::Active
            || ! AdminAccess::allows($current, AdminAccess::MANAGE_PAYOUTS)) {
            throw new AuthorizationException('Current active staff with the payout duty are required to settle a manual deposit.');
        }

        return $current;
    }
}
