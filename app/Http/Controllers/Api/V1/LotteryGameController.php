<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\BetFundingSource;
use App\Exceptions\LotteryGameException;
use App\Http\Controllers\Api\V1\Concerns\RendersGameResponses;
use App\Http\Resources\GameDrawResource;
use App\Http\Resources\GameResource;
use App\Services\LotteryGames\GameAvailabilityService;
use App\Services\LotteryGames\GameRegistry;
use App\Services\LotteryGames\UpcomingDrawsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The read side of the digit-game API: which games exist, what their rules are, whether
 * they can be sold, and when the next draw is.
 *
 * WHY THIS CONTROLLER IS SO THIN
 * Every value it returns is produced by a service that already owns that decision:
 * GameRegistry owns the rules, GameAvailabilityService owns the nine gates, and
 * UpcomingDrawsService owns "which draw is next and how long is left". The controller's
 * entire job is to pick the game, ask those three questions and shape the answer. A
 * controller that filtered draws or re-derived sellability would be the fourth place
 * those rules live, and the first to disagree with the other three.
 *
 * THE GAME CODE COMES FROM THE ROUTE AND IS VALIDATED BY THE ROUTE
 * The route constrains `{code}` to `[a-z0-9_]{1,64}`, so no path traversal or SQL-shaped
 * input can reach a query. The registry then decides whether the code is DECLARED, and an
 * undeclared code is a 404 — not an empty game.
 *
 * UNSERVICEABLE GAMES ARE LISTED, WITH THEIR REASON
 * A game that exists and is not sellable still appears in `index` with
 * `sellable=false` and a machine reason. Hiding it would leave a client unable to explain
 * "coming soon" and unable to render the game's rules at all; showing it with a reason is
 * the honest middle ground, and it exposes no operator plumbing (see GameResource).
 */
final class LotteryGameController
{
    use RendersGameResponses;

    public function __construct(
        private readonly GameRegistry $registry,
        private readonly GameAvailabilityService $availability,
        private readonly UpcomingDrawsService $upcoming,
    ) {}

    /**
     * GET /api/v1/games
     *
     * Every declared game, in configuration order.
     */
    public function index(Request $request): JsonResponse
    {
        $games = [];

        foreach ($this->registry->codes() as $code) {
            $games[] = (new GameResource($this->payloadFor($code)))->toArray($request);
        }

        return $this->gameSuccess($games, 'Games retrieved.', 200, [
            'count' => count($games),
            'sellable_count' => count(array_filter($games, static fn (array $game): bool => $game['sellable'] === true)),
        ]);
    }

    /**
     * GET /api/v1/games/{code}
     */
    public function show(Request $request, string $code): JsonResponse
    {
        try {
            $payload = $this->payloadFor($code);
        } catch (LotteryGameException $e) {
            return $this->gameError($e);
        }

        return $this->gameSuccess((new GameResource($payload))->toArray($request), 'Game retrieved.');
    }

    /**
     * GET /api/v1/games/{code}/upcoming
     *
     * The next draw and its countdown. Deliberately a separate route from `show`: a
     * countdown changes every second and a game's rules do not, so a client can poll the
     * cheap one without re-downloading the rule set.
     */
    public function upcoming(Request $request, string $code): JsonResponse
    {
        try {
            if (! $this->registry->declares($code)) {
                throw LotteryGameException::unknownGame($code);
            }

            $summary = $this->upcoming->summary($code);
        } catch (LotteryGameException $e) {
            return $this->gameError($e);
        }

        return $this->gameSuccess(
            (new GameDrawResource($summary))->toArray($request),
            $summary['draw_id'] === null ? 'No draw is scheduled.' : 'Next draw retrieved.',
            $summary['draw_id'] === null ? 200 : 200,
            ['sellable' => $summary['can_accept_bets'] === true],
        );
    }

    /**
     * The three answers a GameResource needs, for one game.
     *
     * @return array{definition: \App\DTOs\LotteryGames\GameDefinition, capability: \App\DTOs\LotteryGames\GameCapability, upcoming: array<string, mixed>}
     *
     * @throws LotteryGameException game_unknown
     */
    private function payloadFor(string $code): array
    {
        return [
            'definition' => $this->registry->definition($code),
            'capability' => $this->availability->capability($code),
            'upcoming' => $this->upcoming->summary($code),
        ];
    }
}
