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
     * The importer id recorded for an unattended fetch.
     *
     * Named rather than spelled `0` at each call site, and documented here so
     * the four-eyes check in {@see DrawResultConfirmationService} has a stable,
     * explainable value to compare against. No users row carries id 0, which is
     * precisely why it is safe: the automated importer can never be the same
     * identity as the human who confirms.
     */
    public const AUTOMATED_IMPORTER_ID = 0;

    /**
     * Ingest official draw results from GLO API or signed payload.
     *
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
     */
    public function ingestPayload(
        int|string $drawId,
        array $resultPayload,
        int|string $operatorId,
        string $sourceProvider = 'GLO_OFFICIAL'
    ): DrawResult {
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

            if (! in_array($draw->status, [DrawStatus::Closed, DrawStatus::Drawing], true)) {
                throw new RuntimeException("Cannot ingest results for draw [{$draw->draw_code}] with status: {$draw->status->value}. Draw must be CLOSED.");
            }

            $canonicalPayload = [
                'draw_id' => (string) $draw->id,
                'first_prize' => $firstPrize,
                'two_digit_back' => $twoDigitBack,
                'three_digit_front' => $resultPayload['three_digit_front'] ?? [],
                'three_digit_back' => $resultPayload['three_digit_back'] ?? [],
            ];
            ksort($canonicalPayload);
            $fingerprint = hash('sha256', json_encode($canonicalPayload, JSON_THROW_ON_ERROR));

            $metadata = [
                'two_digit_back' => $twoDigitBack,
                'three_digit_front' => $resultPayload['three_digit_front'] ?? [],
                'three_digit_back' => $resultPayload['three_digit_back'] ?? [],
                'fourth_prizes' => $resultPayload['fourth_prizes'] ?? [],
                'fifth_prizes' => $resultPayload['fifth_prizes'] ?? [],
                'source_provider' => $sourceProvider,
                'payload_fingerprint' => $fingerprint,
                'ingested_by_operator_id' => (int) $operatorId,
                'confirmed_by_operator_id' => null,
                'verification_status' => 'PENDING_CONFIRMATION',
                'ingested_at' => now()->toIso8601String(),
            ];

            $existing = DrawResult::where('draw_id', $draw->id)->first();
            if ($existing) {
                if ($existing->verification_status === 'CONFIRMED') {
                    throw new RuntimeException("Draw [{$draw->draw_code}] already has a CONFIRMED result and cannot be re-ingested.");
                }

                $existing->update([
                    'first_prize' => $firstPrize,
                    'second_prize' => $resultPayload['second_prizes'] ?? [],
                    'third_prize' => $resultPayload['third_prizes'] ?? [],
                    'metadata' => array_merge($existing->metadata ?? [], $metadata),
                ]);

                return $existing->fresh();
            }

            /** @var DrawResult $draft */
            $draft = DrawResult::query()->create([
                'draw_id' => (int) $draw->id,
                'first_prize' => $firstPrize,
                'second_prize' => $resultPayload['second_prizes'] ?? [],
                'third_prize' => $resultPayload['third_prizes'] ?? [],
                'metadata' => $metadata,
            ]);

            return $draft;
        });
    }
}
