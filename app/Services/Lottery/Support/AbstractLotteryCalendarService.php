<?php

declare(strict_types=1);

namespace App\Services\Lottery\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/**
 * The ONE place in this application that knows about the Buddhist Era offset.
 *
 * WHY THIS CLASS EXISTS (AND WHY IT WAS EXTRACTED IN PROMPT 6)
 * ---------------------------------------------------------------------------
 * PROMPT 5 put this logic in NationalLotteryDateService. PROMPT 6 needs the
 * identical behaviour for the Weekly lane. Copying it would have produced two
 * classes that each add 543 somewhere, free to drift the moment one is fixed
 * and the other is not - and a Thai-facing lottery page that prints the wrong
 * era next to a draw date is not a cosmetic bug, it is the page stating the
 * wrong year.
 *
 * So the behaviour lives here once, and each product lane supplies only its
 * CONFIG PREFIX. NationalLotteryDateService and WeeklyLotteryDateService are
 * both four-line subclasses; neither contains arithmetic.
 *
 * grep -rn "543" resources/ must return nothing for either lane. That is the
 * invariant this class exists to keep.
 *
 * WHAT ELSE LIVES HERE
 * ---------------------------------------------------------------------------
 * Year bounds. Every public entry point that accepts a year - a route
 * parameter, an API query string, a search box - runs it through
 * isAcceptableYear() BEFORE a query is built, so year 999999999 is refused by
 * a comparison against two integers rather than by the database.
 *
 * Date parsing. Public date input is tried against a CLOSED list of formats
 * from config, in the market timezone, with strict parsing and a round-trip
 * comparison. Nothing here calls strtotime(), which would happily accept
 * "next tuesday" and turn a public search box into an expression evaluator.
 *
 * Timezone. Draw dates are business dates in Asia/Bangkok (configurable). A
 * server running in UTC must not decide that a draw published at 01:00
 * Bangkok belongs to the previous day, so every "today" in either lane comes
 * from today() below and never from a bare now().
 */
abstract class AbstractLotteryCalendarService
{
    public function __construct(
        protected readonly ConfigRepository $config,
    ) {}

    /**
     * The product lane's config file name, e.g. 'weekly_lottery'.
     *
     * This is the ONLY thing a subclass provides. Everything else is shared,
     * which is what makes "the two lanes convert years differently" an
     * unreachable state rather than a bug waiting to happen.
     */
    abstract protected function configPrefix(): string;

    protected function configKey(string $key): string
    {
        return $this->configPrefix().'.'.$key;
    }

    /**
     * The market timezone. Never the server's, never the request's.
     */
    public function timezone(): string
    {
        $timezone = $this->config->get($this->configKey('timezone'));

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Asia/Bangkok';
    }

    /**
     * Today's business date in the market timezone.
     */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay();
    }

    /**
     * Buddhist Era year for a Gregorian year.
     *
     * Integer addition on a YEAR is safe and is not a lottery number. This is
     * the only arithmetic in the lane's date handling, and it happens here.
     */
    public function toBuddhistYear(int $gregorianYear): int
    {
        return $gregorianYear + $this->offset();
    }

    /**
     * Gregorian year for a Buddhist Era year.
     */
    public function toGregorianYear(int $buddhistYear): int
    {
        return $buddhistYear - $this->offset();
    }

    /**
     * Decide which calendar a user-supplied year is expressed in.
     *
     * A visitor may type 2568 (BE) or 2025 (CE) into the same box. The
     * discriminator is the bound, not a guess: anything at or above the
     * minimum plausible BE year is treated as BE and converted; anything else
     * is treated as Gregorian. The result is then bounds-checked like any
     * other year, so an absurd value is rejected either way.
     */
    public function normaliseYearInput(int $year): ?int
    {
        $gregorian = $year >= ($this->minGregorianYear() + $this->offset())
            ? $this->toGregorianYear($year)
            : $year;

        return $this->isAcceptableYear($gregorian) ? $gregorian : null;
    }

    /**
     * Hard bound applied before any year-scoped query is built.
     */
    public function isAcceptableYear(int $gregorianYear): bool
    {
        return $gregorianYear >= $this->minGregorianYear()
            && $gregorianYear <= $this->maxGregorianYear();
    }

    public function minGregorianYear(): int
    {
        $min = $this->config->get($this->configKey('calendar.min_gregorian_year'));

        return is_int($min) && $min > 0 ? $min : 1990;
    }

    public function maxGregorianYear(): int
    {
        $ahead = $this->config->get($this->configKey('calendar.max_future_years'));
        $ahead = is_int($ahead) && $ahead >= 0 ? $ahead : 1;

        return (int) $this->today()->format('Y') + $ahead;
    }

    /**
     * Parse public date input against the closed format list.
     *
     * Returns null - never an exception, never a "best effort" date - when the
     * input is not exactly one of the accepted shapes. Strict mode is on, so
     * '2026-13-45' fails instead of overflowing into 2027.
     */
    public function parsePublicDate(string $input): ?CarbonImmutable
    {
        $input = trim($input);

        if ($input === '' || strlen($input) > 32) {
            return null;
        }

        foreach ($this->acceptedFormats() as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!'.$format, $input, $this->timezone());
            } catch (Throwable) {
                continue;
            }

            if (! $parsed instanceof CarbonImmutable) {
                continue;
            }

            // createFromFormat tolerates trailing junk unless we compare back.
            if ($parsed->format($format) !== $input) {
                continue;
            }

            if (! $this->isAcceptableYear((int) $parsed->format('Y'))) {
                return null;
            }

            return $parsed->startOfDay();
        }

        return null;
    }

    /**
     * ISO date string for storage and for machine-readable attributes.
     */
    public function toIsoDate(CarbonImmutable $date): string
    {
        return $date->format('Y-m-d');
    }

    /**
     * Human date for display, in the requested locale.
     *
     * Thai renders the Buddhist year; every other locale renders the
     * Gregorian year. Both come from this method, so a template never has to
     * decide - and therefore can never decide wrongly.
     */
    public function formatForDisplay(CarbonImmutable $date, string $locale): string
    {
        $day = $date->format('j');
        $monthIndex = (int) $date->format('n');

        if ($this->isThai($locale)) {
            return $day.' '.$this->thaiMonth($monthIndex).' '.$this->toBuddhistYear((int) $date->format('Y'));
        }

        return $day.' '.$date->format('F').' '.$date->format('Y');
    }

    /**
     * Both calendars, always available together.
     *
     * The requirement is that a page shows the Thai Buddhist year AND the
     * Gregorian/internal date - not one or the other - so the projection
     * carries both and the template picks by locale without computing
     * anything.
     *
     * @return array{
     *     iso: string,
     *     gregorian_year: int,
     *     buddhist_year: int,
     *     display_en: string,
     *     display_th: string
     * }
     */
    public function projection(CarbonImmutable $date): array
    {
        return [
            'iso' => $this->toIsoDate($date),
            'gregorian_year' => (int) $date->format('Y'),
            'buddhist_year' => $this->toBuddhistYear((int) $date->format('Y')),
            'display_en' => $this->formatForDisplay($date, 'en'),
            'display_th' => $this->formatForDisplay($date, 'th'),
        ];
    }

    /**
     * Year label for navigation and SEO: BE for Thai, CE otherwise.
     */
    public function yearLabel(int $gregorianYear, string $locale): string
    {
        return (string) ($this->isThai($locale)
            ? $this->toBuddhistYear($gregorianYear)
            : $gregorianYear);
    }

    private function isThai(string $locale): bool
    {
        return str_starts_with(strtolower($locale), 'th');
    }

    private function offset(): int
    {
        $offset = $this->config->get($this->configKey('calendar.buddhist_offset'));

        return is_int($offset) ? $offset : 543;
    }

    /**
     * @return list<string>
     */
    private function acceptedFormats(): array
    {
        $formats = $this->config->get($this->configKey('calendar.accepted_date_formats'));

        if (! is_array($formats) || $formats === []) {
            return ['Y-m-d'];
        }

        $out = [];

        foreach ($formats as $format) {
            if (is_string($format) && $format !== '') {
                $out[] = $format;
            }
        }

        return $out === [] ? ['Y-m-d'] : $out;
    }

    /**
     * Thai month names.
     *
     * Hard-coded rather than taken from ext-intl because the lane must render
     * identically on a server without the intl extension; a month name that
     * silently becomes "January" on one host and "มกราคม" on another is a
     * data-presentation inconsistency in a page whose whole job is stating
     * dates precisely.
     */
    private function thaiMonth(int $month): string
    {
        $months = [
            1 => 'มกราคม',
            2 => 'กุมภาพันธ์',
            3 => 'มีนาคม',
            4 => 'เมษายน',
            5 => 'พฤษภาคม',
            6 => 'มิถุนายน',
            7 => 'กรกฎาคม',
            8 => 'สิงหาคม',
            9 => 'กันยายน',
            10 => 'ตุลาคม',
            11 => 'พฤศจิกายน',
            12 => 'ธันวาคม',
        ];

        return $months[$month] ?? (string) $month;
    }

    /**
     * Normalise a scheduled local draw time to 24-hour 'HH:MM' (PROMPT 9).
     *
     * ACCEPTS what a source realistically sends - '14:00', '2:00 PM',
     * '02:00PM', '1400' - and returns one canonical form, because two
     * spellings of the same time must not create two draws.
     *
     * IT IS A CLOCK READING, NOT AN INSTANT. The value is the time printed on
     * the draw in its own timezone. It is deliberately not converted to UTC
     * and not attached to a date here: a 21:00 draw converted through a server
     * in another zone becomes 14:00 the next day, which is how a result ends
     * up filed under the wrong date.
     *
     * Returns null when the input is not a time this method can read. The
     * caller treats that as INVALID_DRAW_TIME rather than guessing midnight,
     * since a guessed draw time is a fabricated draw identity.
     */
    public function parseLocalDrawTime(string $raw): ?string
    {
        $value = strtoupper(trim($raw));

        if ($value === '') {
            return null;
        }

        $meridiem = null;

        if (str_ends_with($value, 'AM') || str_ends_with($value, 'PM')) {
            $meridiem = substr($value, -2);
            $value = trim(substr($value, 0, -2));
        }

        $value = str_replace(['.', ' '], ['', ''], $value);

        if (preg_match('/^([0-9]{1,2}):([0-9]{2})$/', $value, $m) === 1) {
            $hour = (int) $m[1];
            $minute = (int) $m[2];
        } elseif (preg_match('/^([0-9]{2})([0-9]{2})$/', $value, $m) === 1) {
            $hour = (int) $m[1];
            $minute = (int) $m[2];
        } else {
            return null;
        }

        if ($minute > 59) {
            return null;
        }

        if ($meridiem !== null) {
            if ($hour < 1 || $hour > 12) {
                return null;
            }

            if ($meridiem === 'PM' && $hour !== 12) {
                $hour += 12;
            }

            if ($meridiem === 'AM' && $hour === 12) {
                $hour = 0;
            }
        }

        if ($hour > 23) {
            return null;
        }

        // sprintf pads with characters, not arithmetic: '9:05' becomes '09:05'
        // and stays a string from here on.
        return sprintf('%02d:%02d', $hour, $minute);
    }
}
