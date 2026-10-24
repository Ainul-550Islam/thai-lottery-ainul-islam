<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\CommissionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Exceptions\FinancialException;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Bet;
use App\Models\FinancialTransaction;
use App\Models\Wallet;
use App\Services\Finance\FinancialReversalService;
use App\Services\Finance\FinancialTransactionService;
use App\Services\Finance\IdempotencyService;
use App\Services\Finance\LedgerBalanceValidator;
use App\Services\Finance\LedgerPostingService;
use App\Services\Finance\Money;
use App\Services\Finance\ReversalIntegrityGuard;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service handling commission cancellations and financial reversals upon bet refund / void.
 */
class AgentCommissionReversalService
{
    public function __construct(
        private readonly FinancialReversalService $reversalService,
    ) {}

    /**
     * Reverse all commissions associated with a refunded or cancelled bet.
     *
     * @return list<AgentCommission>
     *
     * @throws FinancialException
     */
    public function reverseForBet(Bet $bet, string $reason = 'Bet refunded'): array
    {
        return DB::transaction(function () use ($bet, $reason): array {
            $commissions = AgentCommission::query()
                ->where('bet_id', $bet->getKey())
                ->whereNotIn('status', [CommissionStatus::Reversed, CommissionStatus::Cancelled])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            return $this->reverseCollection($commissions->all(), sprintf('bet %s: %s', (string) $bet->bet_number, $reason), $reason);
        });
    }

    /**
     * Reverse all commissions for a cancelled draw.
     *
     * @return list<AgentCommission>
     */
    public function reverseForDraw(int $drawId, string $reason = 'Draw cancelled'): array
    {
        return DB::transaction(function () use ($drawId, $reason): array {
            $commissions = AgentCommission::query()
                ->where('draw_id', $drawId)
                ->whereNotIn('status', [CommissionStatus::Reversed, CommissionStatus::Cancelled])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            return $this->reverseCollection($commissions->all(), sprintf('Draw %d: %s', $drawId, $reason), $reason);
        });
    }

    /**
     * @param  list<AgentCommission>  $commissions
     * @return list<AgentCommission>
     */
    private function reverseCollection(array $commissions, string $contextLabel, string $reason): array
    {
        $reversed = [];

        foreach ($commissions as $commission) {
            $agent = Agent::query()->whereKey($commission->agent_id)->lockForUpdate()->first();
            $amount = bcadd((string) $commission->commission_amount, '0', 2);

            if ($commission->status === CommissionStatus::Paid && $commission->financial_transaction_id !== null) {
                $tx = FinancialTransaction::query()->whereKey($commission->financial_transaction_id)->lockForUpdate()->first();

                if ($tx instanceof FinancialTransaction && $tx->status === TransactionStatus::Completed) {
                    $this->compensatePaidTransaction($tx, $commission, sprintf('Reversal of agent commission for %s', $contextLabel));
                }

                if ($agent instanceof Agent) {
                    $newPaid = bcsub((string) $agent->total_commission_paid, $amount, 2);
                    if (bccomp($newPaid, '0.00', 2) < 0) {
                        $newPaid = '0.00';
                    }
                    $agent->total_commission_paid = $newPaid;
                }
            }

            if ($agent instanceof Agent) {
                $newEarned = bcsub((string) $agent->total_commission_earned, $amount, 2);
                if (bccomp($newEarned, '0.00', 2) < 0) {
                    $newEarned = '0.00';
                }
                $agent->total_commission_earned = $newEarned;
                $agent->save();
            }

            $commission->status = CommissionStatus::Reversed;
            $commission->reversed_at = Carbon::now();
            $metadata = is_array($commission->metadata) ? $commission->metadata : [];
            $metadata['reversal_reason'] = $reason;
            $commission->metadata = $metadata;
            $commission->save();

            if (DB::table('bet_commission_ownerships')->where('commission_id', $commission->id)->exists()) {
                DB::table('bet_commission_ownerships')
                    ->where('commission_id', $commission->id)
                    ->update([
                        'state' => 'cancelled',
                        'cancelled_at' => $commission->reversed_at->toDateTimeString(),
                        'cancelled_reason' => $reason,
                        'updated_at' => Carbon::now(),
                    ]);
            }

            $reversed[] = $commission;
        }

        return $reversed;
    }

    private function compensatePaidTransaction(FinancialTransaction $original, AgentCommission $commission, string $description): void
    {
        $wallet = app(WalletLockService::class)->lock((int) $original->wallet_id);
        $guard = app(ReversalIntegrityGuard::class);
        $entries = $guard->entries($original);
        $sourceHash = $guard->fingerprint($original, $entries);
        $key = $this->reversalService->reversalKeyFor($original);
        $amount = bcadd((string) $original->amount, '0', 2);

        $claimed = app(IdempotencyService::class)->claim($key, [
            'type' => FinancialTransactionType::Reversal,
            'amount' => $amount,
            'currency' => $original->currency->value,
            'wallet_id' => $wallet->id,
            'user_id' => $wallet->user_id,
        ], function (string $canonical) use ($original, $sourceHash, $description, $commission): FinancialTransaction {
            $tx = new FinancialTransaction;
            $tx->fill([
                'reference_number' => 'REV-'.strtoupper(bin2hex(random_bytes(10))),
                'type' => FinancialTransactionType::Reversal->toTransactionType(),
                'user_id' => $original->user_id,
                'wallet_id' => $original->wallet_id,
                'currency' => $original->currency,
                'amount' => (string) $original->amount,
                'fee' => '0.00',
                'description' => $description,
                'idempotency_key' => $canonical,
                'reference_type' => FinancialTransaction::class,
                'reference_id' => (int) $original->id,
                'metadata' => [
                    'engine_type' => FinancialTransactionType::Reversal->value,
                    'reversal_of_transaction_id' => (int) $original->id,
                    'reversal_source_hash' => $sourceHash,
                    'reason' => $description,
                    'commission_id' => (int) $commission->id,
                ],
            ]);
            $tx->uuid = (string) Str::uuid();
            $tx->status = TransactionStatus::Pending;
            $tx->save();

            return $tx;
        });

        if ($claimed['replayed']) {
            return;
        }

        $tx = $claimed['transaction'];
        $transactions = app(FinancialTransactionService::class);
        $transactions->transitionTo($tx, TransactionStatus::Processing)->save();
        $balance = app(WalletService::class)->applyReversal($wallet, Money::fromDatabase($amount, $wallet->currency), LedgerEntryType::Credit);
        app(LedgerPostingService::class)->post($tx, $this->reversalService->mirrorEntries($entries, $original), [0 => $balance->toString()]);
        app(LedgerBalanceValidator::class)->assertTransactionBalanced($tx);
        $transactions->transitionTo($tx, TransactionStatus::Completed);
        $tx->processed_at = Carbon::now();
        $tx->save();
        $guard->assertCompensation($original, $tx, $entries, $key);
        $transactions->transitionTo($original, TransactionStatus::Reversed);
        $original->reversed_at = Carbon::now();
        $original->reversed_by = $commission->user_id;
        $original->save();
    }
}
