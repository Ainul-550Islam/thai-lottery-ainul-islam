<?php

namespace App\Http\Middleware;

use App\Models\Draw;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Not applied to any route in the running route table.
 *
 * the purchase path enforces the betting window in BetValidationService, gated by
 * config(lottery.betting.require_open_draw), which config/lottery.php sets to true and no
 * environment template overrides. Applying this middleware as well would put a second,
 * race-prone copy of a financial rule outside the row lock that decides the outcome.
 */
class EnsureDrawIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        $draw = $request->route('draw');

        if (! $draw instanceof Draw) {
            $drawId = $draw ?? $request->input('draw_id');
            $draw = $drawId ? Draw::find($drawId) : null;
        }

        if ($draw === null) {
            abort(404, 'Draw not found.');
        }

        if (! $draw->canAcceptBets()) {
            abort(422, 'This draw is closed and can no longer accept bets.');
        }

        return $next($request);
    }
}
