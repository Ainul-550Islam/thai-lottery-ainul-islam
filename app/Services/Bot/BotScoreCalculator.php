<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Enums\BotDecision;

/**
 * Class BotScoreCalculator
 *
 * Computes normalized weighted aggregate bot score and maps to policy decisions.
 */
class BotScoreCalculator
{
    /**
     * Compute weighted score (0 to 100) from evaluated signals.
     *
     * @param array<string, array{score: float, weight: int, reason_code: string|null, metadata: array<string, mixed>}> $signalResults
     * @return array{score: int, decision: BotDecision, reason_codes: array<int, string>}
     */
    public function calculate(array $signalResults): array
    {
        $totalWeight = 0;
        $weightedSum = 0.0;
        $reasonCodes = [];

        foreach ($signalResults as $result) {
            $weight = $result['weight'];
            $score = $result['score'];

            $totalWeight += $weight;
            $weightedSum += ($score * $weight);

            if ($result['reason_code'] !== null && $score > 0.40) {
                $reasonCodes[] = $result['reason_code'];
            }
        }

        $finalScore = $totalWeight > 0
            ? (int) round(($weightedSum / $totalWeight) * 100)
            : 0;

        $challengeThreshold = (int) config('bot.challenge_threshold', 45);
        $blockThreshold = (int) config('bot.block_threshold', 80);

        $decision = match (true) {
            $finalScore >= $blockThreshold => BotDecision::BLOCK,
            $finalScore >= $challengeThreshold => BotDecision::CHALLENGE,
            $finalScore >= 30 => BotDecision::THROTTLE,
            default => BotDecision::ALLOW,
        };

        return [
            'score' => min(100, max(0, $finalScore)),
            'decision' => $decision,
            'reason_codes' => array_values(array_unique($reasonCodes)),
        ];
    }
}
