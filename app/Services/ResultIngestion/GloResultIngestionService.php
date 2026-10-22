<?php

declare(strict_types=1);

namespace App\Services\ResultIngestion;

use App\Enums\DrawStatus;
use App\Models\Draw;
use App\Models\DrawResult;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Class GloResultIngestionService
 *
 * Implements Stage 1 of the GLO 4-Eyes Write Boundary:
 * Validates payload schema, computes SHA-256 fingerprint, and creates an unconfirmed draft result.
 */
class GloResultIngestionService
{
    /**
     * Ingest official draw results from GLO API or signed payload.
     *
     * @param  string  $drawId
     * @param array{
     *     first_prize: string,
     *     two_digit_back: string,
     *     three_digit_front?: array<int, string>,
     *     three_digit_back?: array<int, string>,
     *     second_prizes?: array<int, string>,
     *     third_prizes?: array<int, string>,
     *     fourth_prizes?: array<int, string>,
     *     fifth_prizes?: array<int, string>
     * } $resultPayload
     * @param  string  $operatorId
     */
    public function ingestPayload(
        int|string $drawId,
        array $resultPayload,
        int|string $operatorId,
        string $sourceProvider = 'GLO_OFFICIAL'
    ): DrawResult {
        // 1. Strict Schema Validation
        $firstPrize = trim((string) ($resultPayload['first_prize'] ?? ''));
        $twoDigitBack = trim((string) ($resultPayload['two_digit_back'] ?? ''));

        if (strlen($firstPrize) !== 6 || ! ctype_digit($firstPrize)) {
            throw new InvalidArgumentException("Invalid first prize format: '{$firstPrize}'. Must be exactly 6 digits.");
        }

        if (strlen($twoDigitBack) !== 2 || ! ctype_digit($twoDigitBack)) {
            throw new InvalidArgumentException("Invalid 2-digit back format: '{$twoDigitBack}'. Must be exactly 2 digits.");
        }

        return DB::transaction(function () use (
            $drawId,
            $resultPayload,
            $operatorId,
            $sourceProvider,
            $firstPrize,
            $twoDigitBack
        ) {
            /** @var Draw $draw */
            $draw = Draw::where('id', $drawId)->lockForUpdate()->firstOrFail();

            // Lifecycle Guard: Result cannot be ingested if draw is still accepting bets or already settled
            if (! in_array($draw->status, [DrawStatus::Closed, DrawStatus::Drawing], true)) {
                throw new RuntimeException("Cannot ingest results for draw [{$draw->draw_code}] with status: {$draw->status->value}. Draw must be CLOSED.");
            }

            // 2. Canonical JSON Fingerprint calculation
            $canonicalPayload = [
                'draw_id' => (string) $draw->id,
                'first_prize' => $firstPrize,
                'two_digit_back' => $twoDigitBack,
                'three_digit_front' => $resultPayload['three_digit_front'] ?? [],
                'three_digit_back' => $resultPayload['three_digit_back'] ?? [],
            ];
            ksort($canonicalPayload);
            $fingerprint = hash('sha256', json_encode($canonicalPayload, JSON_THROW_ON_ERROR));

            // Prevent duplicate unconfirmed submissions
            $existing = DrawResult::where('draw_id', $draw->id)->first();
            if ($existing) {
                if ($existing->verification_status === 'CONFIRMED') {
                    throw new RuntimeException("Draw [{$draw->draw_code}] already has a CONFIRMED result and cannot be re-ingested.");
                }

                // Update unconfirmed draft
                $existing->update([
                    'first_prize' => $firstPrize,
                    'three_digit_front' => $resultPayload['three_digit_front'] ?? null,
                    'three_digit_back' => $resultPayload['three_digit_back'] ?? null,
                    'two_digit_back' => $twoDigitBack,
                    'second_prizes' => $resultPayload['second_prizes'] ?? null,
                    'third_prizes' => $resultPayload['third_prizes'] ?? null,
                    'fourth_prizes' => $resultPayload['fourth_prizes'] ?? null,
                    'fifth_prizes' => $resultPayload['fifth_prizes'] ?? null,
                    'source_provider' => $sourceProvider,
                    'payload_fingerprint' => $fingerprint,
                    'ingested_by_operator_id' => (int) $operatorId,
                    'ingested_at' => now(),
                    'verification_status' => 'PENDING_CONFIRMATION',
                ]);

                return $existing;
            }

            // Create initial unconfirmed Draft via query builder staging path
            /** @var DrawResult $draft */
            $draft = DrawResult::query()->create([
                'draw_id' => (int) $draw->id,
                'first_prize' => $firstPrize,
                'three_digit_front' => $resultPayload['three_digit_front'] ?? null,
                'three_digit_back' => $resultPayload['three_digit_back'] ?? null,
                'two_digit_back' => $twoDigitBack,
                'second_prizes' => $resultPayload['second_prizes'] ?? null,
                'third_prizes' => $resultPayload['third_prizes'] ?? null,
                'fourth_prizes' => $resultPayload['fourth_prizes'] ?? null,
                'fifth_prizes' => $resultPayload['fifth_prizes'] ?? null,
                'source_provider' => $sourceProvider,
                'payload_fingerprint' => $fingerprint,
                'ingested_by_operator_id' => (int) $operatorId,
                'confirmed_by_operator_id' => null,
                'verification_status' => 'PENDING_CONFIRMATION',
                'ingested_at' => now(),
            ]);

            return $draft;
        });
    }
}
