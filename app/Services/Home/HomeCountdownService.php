<?php

declare(strict_types=1);

namespace App\Services\Home;

use App\Enums\DrawStatus;
use App\Models\Draw;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;

/**
 * Calculates authoritative next draw countdown and schedule targets.
 * Never relies on client-side hardcoded date arrays.
 */
class HomeCountdownService
{
    private const DEFAULT_TIMEZONE = 'Asia/Bangkok';

    /**
     * Compute next scheduled draw target information.
     *
     * @return array<string, mixed>
     */
    /**
     * Compatibility name retained for callers of the original home contract.
     * The authoritative calculation remains getNextDrawTarget().
     *
     * @return array<string, mixed>
     */
    public function nextDrawCountdown(): array
    {
        return $this->getNextDrawTarget();
    }

    public function getNextDrawTarget(): array
    {
        try {
            return Cache::remember('home.countdown.next_draw', 30, function (): array {
                $tz = new DateTimeZone(self::DEFAULT_TIMEZONE);
                $now = CarbonImmutable::now($tz);

                $draw = Draw::query()
                    ->where('status', DrawStatus::Scheduled->value)
                    ->where('scheduled_at', '>', $now)
                    ->orderBy('scheduled_at')
                    ->first();

                if (! $draw instanceof Draw || ! $draw->scheduled_at instanceof CarbonInterface) {
                    return [
                        'status' => 'NO_SCHEDULED_DRAW',
                        'has_next_draw' => false,
                        'draw_number' => null,
                        'draw_name' => null,
                        'scheduled_at_iso' => null,
                        'scheduled_at_formatted' => null,
                        'remaining_seconds' => 0,
                        'timezone' => self::DEFAULT_TIMEZONE,
                        'is_open_for_betting' => false,
                    ];
                }

                $target = CarbonImmutable::parse($draw->scheduled_at, $tz);
                $remaining = max(0, $now->diffInSeconds($target, false));

                return [
                    'status' => 'SCHEDULED',
                    'has_next_draw' => true,
                    'draw_number' => (string) ($draw->draw_number ?? 'GLO-'.ltrim($target->format('d/m/Y'), '0')),
                    // draw_name is the HUMAN label for the draw and draw_number
                    // is its canonical identifier. Only the identifier was ever
                    // returned, so every consumer that wanted something to print
                    // had to render the reference string itself.
                    'draw_name' => (string) ($draw->name ?? $target->format('j F Y')),
                    'scheduled_at_iso' => $target->toIso8601String(),
                    'scheduled_at_formatted' => $target->format('d F Y, H:i').' GMT+7',
                    'remaining_seconds' => (int) $remaining,
                    'timezone' => self::DEFAULT_TIMEZONE,
                    'is_open_for_betting' => $remaining > 300,
                ];
            });
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
                'timezone' => self::DEFAULT_TIMEZONE,
                'is_open_for_betting' => false,
            ];
        }
    }
}
