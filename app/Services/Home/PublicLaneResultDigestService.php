<?php

declare(strict_types=1);

namespace App\Services\Home;

use App\Contracts\Lottery\ProvidesCurrentResult;
use App\Services\Lottery\BingoLotteryResultService;
use App\Services\Lottery\NationalLotteryResultService;
use App\Services\Lottery\PcsoLotteryResultService;
use App\Services\Lottery\WeeklyLotteryResultService;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * The latest published result from each public lane, for the Home page.
 *
 * WHY THIS EXISTS. The four result lanes shipped complete, tested and
 * reachable only from the top navigation. A visitor landing on the Home page
 * saw the GLO card and no sign that National, Weekly, Mega or PCSO results
 * were published at all. The lanes were built; nothing pointed at them from
 * the one page everybody arrives on.
 *
 * IT OWNS NO RESULT LOGIC. Every lane's own ResultService answers for its own
 * lane - current-draw selection, publication rules, provenance and caching all
 * stay where they already are. This class asks four questions and arranges the
 * answers; if it computed anything itself, that computation would be a second
 * definition of "the current result".
 *
 * FIELD LABELS COME FROM EACH LANE'S OWN CONFIG, so PCSO shows four numbers,
 * Weekly and Mega show three, and National shows its own set. A hardcoded
 * list here would be the same mistake PROMPT 9 removed from the search
 * engine.
 *
 * WHY CONFIG AND NOT LaneSchema. Three lanes are described by a LaneSchema,
 * but National is not, and forcing it to be would be wrong rather than
 * tidy: its 3 Front and 3 After are LISTS of up to four values with their own
 * cardinality rules, which the scalar schema cannot express. Reading the
 * shared 'fields' block covers all four honestly.
 *
 * LIST FIELDS ARE OMITTED FROM THIS CARD, not flattened. A home-page summary
 * showing four 3 Front values next to one 1st Prize reads as though they were
 * the same kind of thing. The lane page presents them properly; this card
 * links to it.
 *
 * A LANE THAT FAILS IS OMITTED, NOT FAKED. Each lane is resolved inside its
 * own try/catch: one misconfigured lane must not take the Home page down, and
 * a lane with no data renders an honest "no result yet" rather than a zero
 * row.
 */
class PublicLaneResultDigestService
{
    public function __construct(
        private readonly NationalLotteryResultService $national,
        private readonly WeeklyLotteryResultService $weekly,
        private readonly BingoLotteryResultService $bingo,
        private readonly PcsoLotteryResultService $pcso,
    ) {}

    /**
     * One entry per public lane, in product order.
     *
     * @return array{status: string, lanes: list<array<string, mixed>>}
     */
    public function lanes(): array
    {
        $definitions = [
            ['key' => 'national_lottery', 'service' => $this->national, 'route' => 'national-lottery.index'],
            ['key' => 'weekly_lottery', 'service' => $this->weekly, 'route' => 'weekly-lottery.index'],
            ['key' => 'bingo_lottery', 'service' => $this->bingo, 'route' => 'bingo-lottery.index'],
            ['key' => 'pcso_lottery', 'service' => $this->pcso, 'route' => 'pcso-lottery.index'],
        ];

        $lanes = [];

        foreach ($definitions as $definition) {
            $lane = $this->lane($definition['key'], $definition['service'], $definition['route']);

            if ($lane !== null) {
                $lanes[] = $lane;
            }
        }

        return [
            'status' => $lanes === [] ? 'UNAVAILABLE' : 'OK',
            'lanes' => $lanes,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lane(string $configKey, ProvidesCurrentResult $service, string $routeName): ?array
    {
        // A lane switched off in config is not a lane with no results - it is
        // a lane that should not be advertised at all.
        if ((bool) config($configKey.'.enabled', true) === false) {
            return null;
        }

        if (! Route::has($routeName)) {
            return null;
        }

        try {
            $current = $service->currentResult();
        } catch (Throwable $exception) {
            // One broken lane must not remove the other three from the page.
            report($exception);

            return null;
        }

        $declared = config($configKey.'.fields');
        $declared = is_array($declared) ? $declared : [];

        $available = (bool) ($current['available'] ?? false)
            && (bool) ($current['has_numbers'] ?? true);

        $draw = is_array($current['draw'] ?? null) ? $current['draw'] : [];
        $date = is_array($draw['date'] ?? null) ? $draw['date'] : [];
        $numbers = is_array($current['numbers'] ?? null) ? $current['numbers'] : [];

        $fields = [];

        foreach ($declared as $column => $definition) {
            if (! is_string($column) || ! is_array($definition)) {
                continue;
            }

            // Scalars only. A list field is a set of values with its own
            // meaning and belongs on the lane page, not squeezed into a
            // summary card beside a single headline number.
            if (($definition['type'] ?? 'scalar') !== 'scalar') {
                continue;
            }

            $value = $numbers[$column] ?? null;

            $labelKey = $definition['label_key'] ?? null;

            $fields[] = [
                'column' => $column,
                'label_key' => is_string($labelKey) && $labelKey !== ''
                    ? $labelKey
                    : $configKey.'.field_'.$column,
                // Stays a string all the way to the template. An absent value
                // is null and renders as "not published", never as a zero.
                'value' => is_string($value) && $value !== '' ? $value : null,
            ];
        }

        if ($fields === []) {
            return null;
        }

        return [
            'key' => $configKey,
            'title_key' => $configKey.'.heading',
            'route' => route($routeName),
            'available' => $available,
            'status' => (string) ($current['status'] ?? 'UNAVAILABLE'),
            'reference' => isset($draw['reference']) ? (string) $draw['reference'] : null,
            'date_iso' => isset($date['iso']) ? (string) $date['iso'] : null,
            'date_display_en' => isset($date['display_en']) ? (string) $date['display_en'] : null,
            'date_display_th' => isset($date['display_th']) ? (string) $date['display_th'] : null,
            // Only PCSO carries one; the others simply have no time to show.
            'time_display' => isset($draw['time_display']) ? (string) $draw['time_display'] : null,
            'source_state' => isset($current['provenance']['source_state'])
                ? (string) $current['provenance']['source_state']
                : null,
            'fields' => $fields,
        ];
    }
}
