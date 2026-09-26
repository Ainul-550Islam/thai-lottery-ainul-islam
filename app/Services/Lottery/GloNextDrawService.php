<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\DrawStatus;
use App\Models\Draw;
use Illuminate\Support\Carbon;

/**
 * Next public draw moment for the Home countdown.
 *
 * SOURCES OF TRUTH (in order)
 * 1. A live/upcoming draws row (Scheduled, Open, Closed with future
 *    scheduled_at) — the project's canonical scheduler.
 * 2. The official GLO calendar rules in config('lottery.draw.official_schedule')
 *    (days of month + time, Asia/Bangkok) — only when no row exists yet.
 *
 * The client NEVER chooses the target: this service returns an ISO-8601
 * instant, the business timezone, and a formatted local date. JavaScript
 * only counts down for display.
 */
class GloNextDrawService
{
    public const TIMEZONE = 'Asia/Bangkok';

    /**
     * @return array{
     *     status: string,
     *     draw_id: int|null,
     *     draw_number: string|null,
     *     draw_status: string|null,
     *     scheduled_at_iso: string|null,
     *     scheduled_at_display: string|null,
     *     timezone: string,
     *     source: string,
     *     message: string|null,
     * }
     */
    public function nextDraw(): array
    {
        $tz = (string) config('lottery.draw.timezone', self::TIMEZONE);
        if ($tz === '') {
            $tz = self::TIMEZONE;
        }

        $upcoming = Draw::query()
            ->whereIn('status', [
                DrawStatus::Scheduled->value,
                DrawStatus::Open->value,
                DrawStatus::Closed->value,
            ])
            ->where('scheduled_at', '>=', now()->subMinutes(5))
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->first();

        if ($upcoming instanceof Draw) {
            $when = $upcoming->scheduled_at instanceof Carbon
                ? $upcoming->scheduled_at->copy()->timezone($tz)
                : Carbon::parse((string) $upcoming->scheduled_at, $tz);

            return [
                'status' => 'AVAILABLE',
                'draw_id' => (int) $upcoming->getKey(),
                'draw_number' => (string) $upcoming->draw_number,
                'draw_status' => $upcoming->status instanceof DrawStatus
                    ? $upcoming->status->value
                    : (string) $upcoming->status,
                'scheduled_at_iso' => $when->toIso8601String(),
                'scheduled_at_display' => $when->format('j M Y, H:i T'),
                'timezone' => $tz,
                'source' => 'draw_schedule',
                'message' => null,
            ];
        }

        $fromCalendar = $this->fromOfficialCalendar($tz);

        if ($fromCalendar !== null) {
            return $fromCalendar + [
                'draw_id' => null,
                'draw_number' => null,
                'draw_status' => null,
                'timezone' => $tz,
            ];
        }

        return [
            'status' => 'UNAVAILABLE',
            'draw_id' => null,
            'draw_number' => null,
            'draw_status' => null,
            'scheduled_at_iso' => null,
            'scheduled_at_display' => null,
            'timezone' => $tz,
            'source' => 'none',
            'message' => 'No upcoming draw is scheduled.',
        ];
    }

    /**
     * Official calendar fallback: next 1st or 16th at configured time in Bangkok.
     *
     * @return array<string, mixed>|null
     */
    private function fromOfficialCalendar(string $tz): ?array
    {
        $days = array_values(array_map(
            'intval',
            (array) config('lottery.draw.official_schedule.days_of_month', [1, 16]),
        ));
        $time = (string) config('lottery.draw.official_schedule.time', '15:00');

        if ($days === [] || ! preg_match('/^\d{2}:\d{2}$/', $time)) {
            return null;
        }

        sort($days);
        $now = Carbon::now($tz);

        for ($offset = 0; $offset <= 400; $offset++) {
            $day = $now->copy()->addDays($offset);
            if (! in_array((int) $day->format('j'), $days, true)) {
                continue;
            }

            [$hour, $minute] = array_map('intval', explode(':', $time));
            $candidate = $day->copy()->setTime($hour, $minute, 0);

            if ($candidate->greaterThan($now)) {
                return [
                    'status' => 'AVAILABLE',
                    'scheduled_at_iso' => $candidate->toIso8601String(),
                    'scheduled_at_display' => $candidate->format('j M Y, H:i T'),
                    'source' => 'official_calendar',
                    'message' => null,
                ];
            }
        }

        return null;
    }
}
