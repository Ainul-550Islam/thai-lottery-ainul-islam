<?php

declare(strict_types=1);

namespace App\Services\Bot\Signals;

use App\Services\Bot\Contracts\BotSignal;
use Illuminate\Http\Request;

/**
 * Class FormTimingSignal
 *
 * Verifies signed session-bound form render vs submission elapsed time.
 * Instantaneous submission (< 800ms) indicates scripted automated form filler.
 */
class FormTimingSignal implements BotSignal
{
    private const MIN_HUMAN_FILL_MS = 800;

    public function getKey(): string
    {
        return 'form_timing';
    }

    public function getWeight(): int
    {
        return 20;
    }

    public function evaluate(Request $request, array $context = []): array
    {
        $token = $request->input('_form_rendered_at');

        if (!$token || !is_numeric($token)) {
            return [
                'score' => 0.00,
                'reason_code' => null,
                'metadata' => [],
            ];
        }

        $renderedTime = (float) $token;
        $now = microtime(true);
        $elapsedMs = ($now - $renderedTime) * 1000;

        if ($elapsedMs < self::MIN_HUMAN_FILL_MS && $elapsedMs > 0) {
            return [
                'score' => 0.90,
                'reason_code' => 'SUPERHUMAN_FORM_SUBMISSION_SPEED',
                'metadata' => ['elapsed_ms' => round($elapsedMs, 2), 'threshold_ms' => self::MIN_HUMAN_FILL_MS],
            ];
        }

        return [
            'score' => 0.00,
            'reason_code' => null,
            'metadata' => ['elapsed_ms' => round($elapsedMs, 2)],
        ];
    }
}
