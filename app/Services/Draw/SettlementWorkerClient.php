<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\DTOs\DrawResultData;
use App\DTOs\SettlementSelectionResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Signed, read-only bridge to the Rust settlement planner.
 *
 * PHP remains the monetary authority. The returned Rust plan is a parity gate:
 * every decision and exact prize is compared with the existing PHP resolver
 * before the PHP settlement writes or credits anything. A missing, stale,
 * malformed, timed-out, or disagreeing worker response aborts the transaction.
 */
final class SettlementWorkerClient
{
    public function enabled(): bool
    {
        return (bool) config('services.settlement_worker.enabled', false);
    }

    /**
     * Ask the worker to compute a read-only plan for the inclusive bet-id range.
     *
     * @return array{decisions: array<int, array{won: bool, prize: string, tier: string}>, total_selections: int}
     */
    public function planForBetRange(
        int $drawId,
        int $fromBetId,
        int $toBetId,
        DrawResultData $result,
        string $fingerprint,
    ): array {
        if (! $this->enabled()) {
            throw new RuntimeException('Settlement worker parity was requested while the worker is disabled.');
        }
        if ($drawId < 1 || $fromBetId < 1 || $toBetId < $fromBetId) {
            throw new RuntimeException('Settlement worker request range is invalid.');
        }

        $baseUrl = rtrim((string) config('services.settlement_worker.url', ''), '/');
        $secret = (string) config('services.settlement_worker.hmac_secret', '');
        if ($baseUrl === '' || strlen($secret) < 32) {
            throw new RuntimeException('Settlement worker URL and a 32-byte HMAC secret are required.');
        }

        if (preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            throw new RuntimeException(sprintf('Draw %d has no valid live publication fingerprint.', $drawId));
        }

        $body = json_encode([
            'draw_id' => $drawId,
            'from_bet_id' => $fromBetId,
            'to_bet_id' => $toBetId,
            'result' => [
                'draw_id' => $drawId,
                'first_prize' => $result->firstPrize(),
                'bottom_two' => $result->bottomTwo(),
                'result_fingerprint' => $fingerprint,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        $timeout = max(1, (int) config('services.settlement_worker.timeout', 8));

        $response = Http::connectTimeout(min(3, $timeout))
            ->timeout($timeout)
            ->acceptJson()
            ->withHeaders([
                'X-Settlement-Timestamp' => $timestamp,
                'X-Settlement-Signature' => $signature,
            ])
            ->send('POST', $baseUrl.'/v1/settlement/plan', [
                'body' => $body,
                'headers' => ['Content-Type' => 'application/json'],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Settlement worker rejected draw %d plan request with HTTP %d.',
                $drawId,
                $response->status(),
            ));
        }

        $payload = $response->json();
        if (! is_array($payload)
            || (int) ($payload['draw_id'] ?? 0) !== $drawId
            || ($payload['result_fingerprint'] ?? null) !== $fingerprint
            || ! is_array($payload['decisions'] ?? null)
            || ! is_int($payload['total_selections'] ?? null)
            || ! is_int($payload['winning_selections'] ?? null)
            || ! is_string($payload['total_prize'] ?? null)) {
            throw new RuntimeException('Settlement worker returned an invalid plan envelope.');
        }

        $decisions = [];
        $sum = '0.00';
        $winners = 0;
        foreach ($payload['decisions'] as $decision) {
            if (! is_array($decision)
                || ! is_int($decision['bet_item_id'] ?? null)
                || ! is_bool($decision['won'] ?? null)
                || ! is_string($decision['prize'] ?? null)
                || preg_match('/^\d+\.\d{2}$/', $decision['prize']) !== 1
                || ! is_string($decision['tier'] ?? null)
                || $decision['tier'] === '') {
                throw new RuntimeException('Settlement worker returned a malformed selection decision.');
            }

            $itemId = $decision['bet_item_id'];
            if (isset($decisions[$itemId])) {
                throw new RuntimeException(sprintf('Settlement worker returned duplicate bet item %d.', $itemId));
            }

            $decisions[$itemId] = [
                'won' => $decision['won'],
                'prize' => $decision['prize'],
                'tier' => $decision['tier'],
            ];
            $sum = bcadd($sum, $decision['prize'], 2);
            $winners += $decision['won'] ? 1 : 0;
        }

        if ($payload['total_selections'] !== count($decisions)
            || $payload['winning_selections'] !== $winners
            || bccomp($payload['total_prize'], $sum, 2) !== 0) {
            throw new RuntimeException('Settlement worker plan totals do not reconcile to its decisions.');
        }

        return [
            'decisions' => $decisions,
            'total_selections' => count($decisions),
        ];
    }

    /**
     * Consume and compare one item before PHP writes it or credits a wallet.
     *
     * @param array{decisions: array<int, array{won: bool, prize: string, tier: string}>, total_selections: int}|null $plan
     */
    public function assertDecisionMatches(?array &$plan, SettlementSelectionResult $record): void
    {
        if ($plan === null) {
            return;
        }

        $itemId = $record->betItemId;
        $workerDecision = $plan['decisions'][$itemId] ?? null;
        if (! is_array($workerDecision)) {
            throw new RuntimeException(sprintf('Settlement worker omitted bet item %d.', $itemId));
        }

        if ($workerDecision['tier'] !== $record->marketKey
            || $workerDecision['won'] !== $record->isWinner()
            || bccomp($workerDecision['prize'], $record->simulatedPrize, 2) !== 0) {
            throw new RuntimeException(sprintf(
                'Rust/PHP settlement parity mismatch for bet item %d (%s).',
                $itemId,
                $record->marketKey,
            ));
        }

        unset($plan['decisions'][$itemId]);
    }

    /**
     * Refuse the settlement if the worker planned a row PHP did not evaluate.
     *
     * @param array{decisions: array<int, array{won: bool, prize: string, tier: string}>, total_selections: int}|null $plan
     */
    public function assertPlanConsumed(?array $plan): void
    {
        if ($plan !== null && $plan['decisions'] !== []) {
            $ids = array_slice(array_keys($plan['decisions']), 0, 5);
            throw new RuntimeException('PHP settlement omitted worker-planned bet items: '.implode(',', $ids));
        }
    }
}
