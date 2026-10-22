<?php

declare(strict_types=1);

namespace App\Services\Home;

use App\Services\Lottery\GloNextDrawService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Compatibility adapter for the Home countdown contract.
 *
 * GloNextDrawService is the single source of the next draw target, including
 * open/closed future draws and its configured calendar fallback. This adapter
 * adds the legacy countdown fields without inventing schedule data or implying
 * betting is open from the amount of time remaining.
 */
class HomeCountdownService
{
    public function __construct(private readonly GloNextDrawService $nextDraw) {}

    /**
     * Compatibility name retained for callers of the original Home contract.
     *
     * @return array<string, mixed>
     */
    public function nextDrawCountdown(): array
    {
        return $this->getNextDrawTarget();
    }

    /**
     * @return array<string, mixed>
     */
    public function getNextDrawTarget(): array
    {
        try {
            $source = $this->nextDraw->nextDraw();
            $targetIso = $source['scheduled_at_iso'] ?? null;
            $hasNextDraw = is_string($targetIso) && $targetIso !== '';
            $timezone = (string) ($source['timezone'] ?? GloNextDrawService::TIMEZONE);

            if (! $hasNextDraw) {
                return [
                    'status' => (string) ($source['status'] ?? 'UNAVAILABLE'),
                    'has_next_draw' => false,
                    'draw_number' => null,
                    'draw_name' => null,
                    'scheduled_at_iso' => null,
                    'scheduled_at_formatted' => null,
                    'remaining_seconds' => 0,
                    'timezone' => $timezone,
                    'is_open_for_betting' => false,
                    'message' => $source['message'] ?? 'No upcoming draw is scheduled.',
                ];
            }

            $target = CarbonImmutable::parse($targetIso)->setTimezone($timezone);
            $now = CarbonImmutable::now($timezone);
            $remaining = max(0, (int) $now->diffInSeconds($target, false));
            $drawStatus = (string) ($source['draw_status'] ?? '');

            return [
                'status' => (string) ($source['status'] ?? 'AVAILABLE'),
                'has_next_draw' => true,
                'draw_number' => isset($source['draw_number']) ? (string) $source['draw_number'] : null,
                'draw_name' => isset($source['draw_name']) ? (string) $source['draw_name'] : null,
                'scheduled_at_iso' => $target->toIso8601String(),
                'scheduled_at_formatted' => $source['scheduled_at_display'] ?? $target->format('j M Y, H:i T'),
                'remaining_seconds' => $remaining,
                'timezone' => $timezone,
                'is_open_for_betting' => $drawStatus === 'open',
                'message' => $source['message'] ?? null,
            ];
        } catch (\Throwable $e) {
            report($e);

            return [
                'status' => 'UNAVAILABLE',
                'has_next_draw' => false,
                'draw_number' => null,
                'draw_name' => null,
                'scheduled_at_iso' => null,
                'scheduled_at_formatted' => null,
                'remaining_seconds' => 0,
                'timezone' => GloNextDrawService::TIMEZONE,
                'is_open_for_betting' => false,
                'message' => 'Next draw temporarily unavailable.',
            ];
        }
    }
}
