<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Exceptions\LotteryGameException;
use App\Http\Responses\ApiResponse;
use App\Http\Support\GameErrorStatus;
use Illuminate\Http\JsonResponse;

/**
 * One place that turns a batch-2 refusal into an HTTP status and a JSON body.
 *
 * WHY THIS IS A TRAIT AND NOT LOGIC IN EACH CONTROLLER
 * Three controllers serve seven routes, and every one of them can hit the same ten
 * refusals. If each decided its own status code, "the draw closed" would be a 409 on
 * the placement route and a 422 on the quote route, and a client would have to special
 * case each. So the mapping is declared once, here, and the mapping is the CONTRACT.
 *
 * THE STATUS CHOICES, AND WHY EACH IS THE RIGHT ONE
 *   404  game_unknown, game_draw_unavailable, game_result_unavailable
 *        The caller named something that does not exist (or no longer does). A 404 is
 *        what makes a client stop retrying.
 *   409  game_not_sellable, game_draw_closed, game_release_refused,
 *        game_settlement_refused, game_liability_exceeded
 *        The request is well formed and the resource exists, but the CURRENT STATE
 *        refuses it: the window closed, the cap is full, the game was suspended. A
 *        retry may succeed later, which is exactly what 409 means and 422 does not.
 *   422  game_number_invalid, stake_* violations, game_funding_unavailable,
 *        game_publication_bridge_invalid, game_idempotency_key_required
 *        The caller sent something unusable. Retrying the same body will fail again.
 *   503  game_not_configured, game_result_source_not_configured,
 *        game_lane_disabled, game_rules_unverified, game_fixture_refused
 *        The DEPLOYMENT cannot serve this, not the caller. A 503 tells a client (and an
 *        operator's monitoring) that the fault is on our side.
 *
 * EVERY ERROR CARRIES `details` FROM THE EXCEPTION'S OWN CONTEXT
 * The exception already carries the structured facts — the game code, the cap that was
 * breached, the missing configuration paths. Those are passed through verbatim rather
 * than reformatted, so a client, a log and an operator screen all read the same values.
 *
 * NOTHING IS SWALLOWED
 * There is no catch-all here. A controller calls gameGuard() around the lane call; an
 * unexpected exception is NOT converted into a friendly 500 here, because a bug in this
 * lane must surface as a bug, with a stack trace, not as a plausible error code.
 */
trait RendersGameResponses
{
    /**
     * A batch-2 refusal as a JSON error response.
     */
    protected function gameError(LotteryGameException $exception): JsonResponse
    {
        $code = $exception->errorCode();

        return ApiResponse::error(
            $code,
            $exception->getMessage(),
            GameErrorStatus::for($code),
            $exception->context(),
        );
    }

    /**
     * A batch-2 success.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    protected function gameSuccess(array $data, string $message = 'OK', int $status = 200, array $meta = []): JsonResponse
    {
        return ApiResponse::success($data, $message, $status, $meta);
    }

    /**
     * The status a code maps to.
     *
     * Delegated to GameErrorStatus, which is the single table: a trait constant cannot be
     * read from outside the trait, and four controllers plus the API docs all need this
     * mapping to be assertable on its own.
     */
    protected function gameStatusFor(string $code): int
    {
        return GameErrorStatus::for($code);
    }
}
