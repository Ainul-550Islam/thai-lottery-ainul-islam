<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\LotteryGameException;
use App\Http\Controllers\Api\V1\Concerns\RendersGameResponses;
use App\Models\Draw;
use App\Models\GameResultImport;
use App\Services\LotteryGames\DrawScheduler;
use App\Services\LotteryGames\GameRegistry;
use App\Services\LotteryGames\GameResultIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public result feed of a digit game: what was drawn, and what was drawn before.
 *
 * WHY THIS IS READ-ONLY AND WHY THAT IS THE WHOLE DESIGN
 * Results enter the platform through one path only: a provider payload is staged, then
 * published through the Batch-1 draw-result confirmation boundary under four eyes. This
 * controller sits at the far end of that pipeline and can only READ what the boundary
 * published. There is no POST here, no "import" endpoint and no correction route,
 * because every one of those would be a second way to write a draw result — the exact
 * thing Rule §0.5 and the f5cec15 regression note forbid.
 *
 * ONLY PUBLISHED IMPORTS ARE VISIBLE
 * A staged row is an unconfirmed claim from a feed. Serving it would tell players what
 * they won before two operators agreed it was true. So `latest` and `history` filter on
 * status=published, and nothing else is reachable from the API.
 *
 * AN UNPUBLISHED RESULT IS A 404 WITH A REASON, NOT AN EMPTY 200
 * `game_result_unavailable` says "this game has no published result for that query yet",
 * which is a different fact from "this game does not exist" (game_unknown) and from "the
 * deployment cannot do this" (503). Collapsing them would make a client poll forever on a
 * game code that is simply wrong.
 */
final class GameResultController
{
    use RendersGameResponses;

    public function __construct(
        private readonly GameRegistry $registry,
        private readonly GameResultIngestionService $ingestion,
        private readonly DrawScheduler $scheduler,
    ) {}

    /**
     * GET /api/v1/games/{code}/results/latest
     */
    public function latest(Request $request, string $code): JsonResponse
    {
        try {
            $this->assertDeclared($code);
        } catch (LotteryGameException $e) {
            return $this->gameError($e);
        }

        $published = array_values(array_filter(
            $this->ingestion->recent($code, 25),
            static fn (array $row): bool => ($row['status'] ?? null) === GameResultImport::STATUS_PUBLISHED,
        ));

        if ($published === []) {
            return $this->gameError(LotteryGameException::resultUnavailable($code));
        }

        return $this->gameSuccess($this->shape($published[0]), 'Latest result retrieved.');
    }

    /**
     * GET /api/v1/games/{code}/results/calendar
     *
     * One month of a game's draws, grouped by day, each with its published result when
     * there is one.
     *
     * WHY A CALENDAR IS NOT JUST A HISTORY WITH A DATE FILTER
     * A player looking at a digit game needs three different things from one screen: what
     * was drawn (the result), what is about to be drawn (the schedule), and what is missing
     * (a draw that closed but has no published result yet - the one thing that looks like a
     * platform error to them). A filtered list of published imports answers only the first.
     * So this projection is built from TWO sources and labels which is which:
     *
     *   draws        rows that exist, with their real status, from the draws table
     *   projected    future slots computed by DrawScheduler from the game's configured
     *                cadence, marked `projected: true` because they are a PLAN and not a
     *                commitment. A projected slot carries no draw id and no result.
     *
     * WHY PROJECTIONS ARE COMPUTED FROM CONFIGURATION AND NOT INVENTED
     * The cadence comes from the game's own rule block. When the block does not declare one
     * (the shipped blueprints declare REQUIRED_INPUT), nextRun() returns nothing, the
     * projection is empty and the response says so - it does not guess a nightly draw for a
     * game nobody has specified. A calendar full of invented draw times would be the exact
     * failure Rule §0.3 forbids, wearing a friendly interface.
     *
     * WHY THE PROJECTION LOOP IS BOUNDED
     * It walks at most DrawScheduler::MAX_AHEAD slots forward from the start of the month and
     * stops as soon as a slot falls outside it. A month therefore cannot turn into an
     * unbounded cron evaluation, and a malformed cadence cannot spin: the loop is bounded by
     * a constant, not by the calendar.
     *
     * ONLY PUBLISHED RESULTS ARE SHOWN, AND A DRAW WITH NONE SAYS SO
     * A staged import is an unconfirmed claim; `result` stays null and `result_pending` is
     * true, which is the honest answer for a draw that has closed. Serving staged digits
     * would tell players what they won before two operators agreed it was true.
     */
    public function calendar(Request $request, string $code): JsonResponse
    {
        try {
            $this->assertDeclared($code);
            $game = $this->registry->definition($code);
        } catch (LotteryGameException $e) {
            return $this->gameError($e);
        }

        $month = trim((string) $request->query('month', ''));

        if ($month === '') {
            $month = CarbonImmutable::now($game->timezone)->format('Y-m');
        }

        // STRICT PARSING. '2026-13' and 'next month' are refused rather than coerced: a
        // calendar that silently answers a different month than the one asked for is worse
        // than one that refuses the question.
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return $this->gameError(LotteryGameException::invalidResultPayload(
                'A calendar month must be formatted YYYY-MM.',
                ['game_code' => $code, 'month' => $month, 'expected' => 'YYYY-MM'],
            ));
        }

        // Carbon throws for a well-formed but impossible month ('2026-00'), so the parse is
        // guarded: the refusal must be this lane's 422, not a library exception surfacing as
        // a 400 or a 500.
        try {
            $monthStart = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $month.'-01 00:00:00', $game->timezone);
        } catch (\Throwable) {
            $monthStart = null;
        }

        if (! $monthStart instanceof CarbonImmutable || $monthStart->format('Y-m') !== $month) {
            return $this->gameError(LotteryGameException::invalidResultPayload(
                'That calendar month does not exist.',
                ['game_code' => $code, 'month' => $month, 'expected' => 'YYYY-MM'],
            ));
        }

        $monthEnd = $monthStart->addMonth();

        /** @var array<string, array{draw_id: int|null, draw_number: string, draw_code: string|null, scheduled_at: string|null, betting_close_at: string|null, status: string|null, projected: bool}> $slots */
        $slots = [];

        // 1. Real draws in the month, whatever their status.
        $draws = Draw::query()
            ->where('game_type', $code)
            ->where('scheduled_at', '>=', $monthStart->utc())
            ->where('scheduled_at', '<', $monthEnd->utc())
            ->orderBy('scheduled_at')
            ->get();

        foreach ($draws as $draw) {
            $drawCode = (string) ($draw->draw_code ?? $draw->draw_number ?? '');

            $slots[$drawCode !== '' ? $drawCode : 'draw-'.(int) $draw->getKey()] = [
                'draw_id' => (int) $draw->getKey(),
                'draw_number' => (string) ($draw->draw_number ?? ''),
                'draw_code' => $drawCode !== '' ? $drawCode : null,
                'scheduled_at' => $draw->scheduled_at?->toIso8601String(),
                'betting_close_at' => $draw->betting_close_at?->toIso8601String(),
                'status' => $draw->status?->value,
                'projected' => false,
                'result_pending' => false,
                'result' => null,
            ];
        }

        // 2. Future slots from the CONFIGURED cadence, skipping any that already exist.
        $projected = 0;
        $projectionRefused = null;

        // THE GATE IS THE LANE'S OWN "IS THIS CALENDAR CONFIGURED" CHECK, not the absence of
        // a slot. nextRun() also returns null for a cadence that is present but unusable, so
        // treating a null as "nothing more to project" would let the calendar claim it had a
        // schedule while projecting from a rule block full of REQUIRED_INPUT.
        try {
            $this->scheduler->assertSchedulable($game);
        } catch (LotteryGameException $e) {
            // An unconfigured schedule is a property of the GAME, not an error in this
            // request: the calendar still answers, with the reason attached.
            $projectionRefused = $e->errorCode();
        }

        for ($nth = 0; $projectionRefused === null && $nth < DrawScheduler::MAX_AHEAD; $nth++) {
            $slotAt = $this->scheduler->nextRun($game, $nth, $monthStart);

            if (! $slotAt instanceof CarbonImmutable || $slotAt->greaterThanOrEqualTo($monthEnd)) {
                break;
            }

            if ($slotAt->lessThan($monthStart)) {
                continue;
            }

            $slotCode = $this->scheduler->drawCodeFor($game, $slotAt);

            if (isset($slots[$slotCode])) {
                continue;
            }

            $slots[$slotCode] = [
                'draw_id' => null,
                'draw_number' => $slotCode,
                'draw_code' => $slotCode,
                'scheduled_at' => $slotAt->toIso8601String(),
                'betting_close_at' => $this->scheduler->bettingCloseAt($game, $slotAt)->toIso8601String(),
                'status' => null,
                'projected' => true,
                'result_pending' => false,
                'result' => null,
            ];

            $projected++;
        }

        // 3. Attach published results by draw number. Only published rows are considered.
        $published = [];

        foreach ($this->ingestion->recent($code, 100) as $row) {
            if (($row['status'] ?? null) === GameResultImport::STATUS_PUBLISHED) {
                $published[(string) ($row['draw_no'] ?? '')] = $this->shape($row);
            }
        }

        foreach ($slots as $slotCode => $slot) {
            $result = $published[$slotCode] ?? $published[$slot['draw_number']] ?? null;

            if ($result !== null) {
                $slots[$slotCode]['result'] = $result;
                $slots[$slotCode]['status'] = $result['published_at'] !== null
                    ? ($slot['status'] ?? GameResultImport::STATUS_PUBLISHED)
                    : $slot['status'];
                $slots[$slotCode]['result_pending'] = false;

                continue;
            }

            // A real draw that has closed but has no published result is the case a player
            // reads as "the platform lost my draw". Naming it is cheaper than fixing a
            // support ticket about it.
            $slots[$slotCode]['result_pending'] = $slot['projected'] === false
                && in_array((string) $slot['status'], ['closed', 'drawing', 'result_pending'], true);
        }

        ksort($slots);

        $days = [];

        foreach ($slots as $slot) {
            $date = $slot['scheduled_at'] !== null
                ? CarbonImmutable::parse($slot['scheduled_at'])->setTimezone($game->timezone)->format('Y-m-d')
                : $month.'-01';

            $days[$date] ??= ['date' => $date, 'draws' => []];
            $days[$date]['draws'][] = $slot;
        }

        ksort($days);

        $dayList = array_values($days);

        return $this->gameSuccess(
            [
                'game_code' => $game->code,
                'month' => $month,
                'timezone' => $game->timezone,
                'days' => $dayList,
                'counts' => [
                    'draws' => count($slots) - $projected,
                    'projected' => $projected,
                    'published' => count(array_filter($slots, static fn (array $slot): bool => $slot['result'] !== null)),
                    'result_pending' => count(array_filter($slots, static fn (array $slot): bool => $slot['result_pending'] === true)),
                ],
                'projection' => $projectionRefused === null
                    ? ['available' => true, 'limit' => DrawScheduler::MAX_AHEAD]
                    : ['available' => false, 'reason' => $projectionRefused, 'limit' => DrawScheduler::MAX_AHEAD],
            ],
            $slots === [] ? 'No draws in that month.' : 'Calendar retrieved.',
            200,
            [
                'month' => $month,
                'timezone' => $game->timezone,
                // The month the client asked for is echoed back explicitly: a calendar
                // response whose month is implied by its rows is a response a client cannot
                // verify against what it requested.
                'days' => count($dayList),
            ],
        );
    }

    /**
     * GET /api/v1/games/{code}/results
     */
    public function history(Request $request, string $code): JsonResponse
    {
        try {
            $this->assertDeclared($code);
        } catch (LotteryGameException $e) {
            return $this->gameError($e);
        }

        $limit = $request->integer('limit', 25);
        $limit = max(1, min(100, $limit));

        $published = array_values(array_filter(
            $this->ingestion->recent($code, $limit),
            static fn (array $row): bool => ($row['status'] ?? null) === GameResultImport::STATUS_PUBLISHED,
        ));

        $rows = array_map(fn (array $row): array => $this->shape($row), $published);

        return $this->gameSuccess($rows, 'Results retrieved.', 200, [
            'count' => count($rows),
            'limit' => $limit,
        ]);
    }

    /**
     * The public shape of one result.
     *
     * The payload hash is included deliberately: a client (or a regulator) can verify that
     * the digits it was served are the exact bytes the platform recorded, without the
     * platform having to expose the raw provider payload. The staged `raw` body, the
     * message and the source's internal identifiers are withheld.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function shape(array $row): array
    {
        return [
            'game_code' => $row['game_code'] ?? null,
            'draw_no' => $row['draw_no'] ?? null,
            'digits' => $row['digits'] ?? null,
            'published_at' => $row['published_at'] ?? null,
            'payload_hash' => $row['payload_hash'] ?? null,
        ];
    }

    /**
     * @throws LotteryGameException game_unknown
     */
    private function assertDeclared(string $code): void
    {
        if (! $this->registry->declares($code)) {
            throw LotteryGameException::unknownGame($code);
        }
    }
}
