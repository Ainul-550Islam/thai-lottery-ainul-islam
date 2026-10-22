<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Agent;
use App\Models\LotteryEntry;
use App\Services\Commission\MultiTierCommissionDistributionService;
use App\Services\Ledger\DoubleEntryLedgerService;
use App\Services\Lock\DistributedLockService;
use App\Services\Wallet\WalletMutationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class AgentPosTicketIssuanceService
{
    public function __construct(
        private readonly EscPosThermalReceiptFormatter $receiptFormatter,
        private readonly DoubleEntryLedgerService $ledgerService,
        private readonly WalletMutationService $walletService,
        private readonly DistributedLockService $lockService,
        private readonly MultiTierCommissionDistributionService $commissionService
    ) {}

    /**
     * Issues a batch of lottery tickets via an authorized agent POS terminal.
     *
     * @param  array<int, array{number: string, bet_type: string, amount: string}>  $entries
     * @return array{
     *     receipt_number: string,
     *     total_amount: string,
     *     digital_signature: string,
     *     escpos_base64: string,
     *     entries_count: int
     * }
     */
    public function issuePosTickets(
        string $agentId,
        string $drawId,
        array $entries,
        string $clientIp
    ): array {
        if (empty($entries)) {
            throw new InvalidArgumentException('Entries list cannot be empty.');
        }

        // Calculate total gross amount
        $totalGross = '0.0000';
        foreach ($entries as $entry) {
            $amt = number_format((float) ($entry['amount'] ?? 0), 4, '.', '');
            if (bccomp($amt, '0.0000', 4) <= 0) {
                throw new InvalidArgumentException("Invalid entry amount: {$amt}");
            }
            $totalGross = bcadd($totalGross, $amt, 4);
        }

        return $this->lockService->runWithLock("pos:agent:{$agentId}", function () use (
            $agentId,
            $drawId,
            $entries,
            $totalGross

        ) {
            return DB::transaction(function () use ($agentId, $drawId, $entries, $totalGross) {
                /** @var Agent $agent */
                $agent = Agent::where('id', $agentId)->lockForUpdate()->firstOrFail();

                if (! $agent->is_active) {
                    throw new RuntimeException('Agent account is currently inactive or suspended.');
                }

                $receiptNumber = 'POS-'.date('Ymd').'-'.strtoupper(Str::random(8));
                $issuedAt = now()->toIso8601String();

                // Compute digital signature hash chaining
                $signatureData = "{$receiptNumber}|{$agentId}|{$drawId}|{$totalGross}|{$issuedAt}|".json_encode($entries);
                $digitalSignature = hash('sha256', $signatureData);

                // 1. Debit Agent Wallet for ticket issuance
                $orderUuid = (string) Str::uuid();
                $this->walletService->debit(
                    userId: $agent->user_id,
                    amount: $totalGross,
                    referenceType: 'POS_TICKET_SALE',
                    referenceId: $orderUuid,
                    currency: 'THB',
                    walletType: 'main',
                    idempotencyKey: "POS-SALE-{$receiptNumber}"
                );

                // 2. Persist Lottery Entries
                $persistedEntries = [];
                foreach ($entries as $entry) {
                    $entryUuid = (string) Str::uuid();
                    LotteryEntry::create([
                        'id' => $entryUuid,
                        'order_id' => $orderUuid,
                        'user_id' => $agent->user_id,
                        'agent_id' => $agent->id,
                        'draw_id' => $drawId,
                        'number' => (string) $entry['number'],
                        'bet_type' => (string) $entry['bet_type'],
                        'amount' => (string) $entry['amount'],
                        'payout_amount' => '0.0000',
                        'currency' => 'THB',
                        'status' => 'PENDING',
                    ]);
                    $persistedEntries[] = $entry;
                }

                // 3. Trigger Multi-Tier Agent Commission Hierarchy Distribution
                $this->commissionService->distributeCommissions(
                    orderId: $orderUuid,
                    directAgentId: $agent->id,
                    totalOrderAmount: $totalGross,
                    currency: 'THB'
                );

                // 4. Generate ESC/POS Binary Receipt Stream
                $receiptPayload = [
                    'receipt_number' => $receiptNumber,
                    'agent_code' => $agent->agent_code ?? $agent->id,
                    'agent_name' => $agent->name ?? 'POS Terminal',
                    'draw_id' => $drawId,
                    'draw_date' => date('Y-m-d'),
                    'entries' => $persistedEntries,
                    'total_amount' => $totalGross,
                    'digital_signature' => $digitalSignature,
                    'issued_at' => date('Y-m-d H:i:s'),
                ];

                $rawEscPos = $this->receiptFormatter->formatReceipt($receiptPayload);

                return [
                    'receipt_number' => $receiptNumber,
                    'total_amount' => $totalGross,
                    'digital_signature' => $digitalSignature,
                    'escpos_base64' => base64_encode($rawEscPos),
                    'entries_count' => count($persistedEntries),
                ];
            });
        });
    }
}
