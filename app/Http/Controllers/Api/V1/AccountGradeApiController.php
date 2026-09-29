<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Services\Account\AccountGradeEvaluator;
use App\Services\Lottery\DiscountParityProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/account/grade — the authenticated user's own grade.
 *
 * SERVER-AUTHORITATIVE CURRENT-GRADE SUPPORT (batch section N):
 * current grade, qualifying 30-day spend, next grade, spend remaining
 * to the next grade, the applied discount, and the ordered eligible
 * games. Every value is calculated from the authoritative spend source
 * through the canonical evaluator — nothing is fabricated and nothing
 * is client-supplied.
 *
 * SECURITY
 *   - the subject is ALWAYS $request->user(): no user id is accepted
 *     from the request, so cross-user access (IDOR) has no route in;
 *   - any submitted grade / discount / spend / rule_version fields are
 *     ignored wholesale — the request body is never read at all;
 *   - the public programme (five tiers) is served anonymously by the
 *     public grade page/API; THIS endpoint exposes only the caller's
 *     own private figures.
 */
final class AccountGradeApiController
{
    public function __construct(
        private readonly AccountGradeEvaluator $evaluator,
        private readonly DiscountParityProjectionService $projection,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $this->evaluator->evaluate($user);
        $next = $this->evaluator->nextTierFor($user);

        return response()->json([
            'success' => true,
            'data' => [
                'current_grade' => [
                    'key' => $result->tier->key,
                    'name' => $result->tier->name,
                    'level' => $result->level->value,
                    'is_base' => $result->level->isBase(),
                    'sl' => $result->tier->sl,
                    'icon' => $result->tier->icon,
                ],
                'discount' => [
                    'rate' => $result->appliedRate,
                    'percent' => bcadd(bcmul($result->appliedRate, '100', 2), '0', 2),
                ],
                'qualifying_spend' => [
                    'amount' => $result->qualifyingSpend,
                    'currency' => (string) config('account_grades.currency', 'THB'),
                    'window_days' => $result->windowDays,
                    'window_start' => $result->windowStart,
                    'window_end' => $result->windowEnd,
                ],
                'next_grade' => $next['tier'] !== null ? [
                    'key' => $next['tier']->key,
                    'name' => $next['tier']->name,
                    'sl' => $next['tier']->sl,
                    'min_spend' => $next['tier']->minSpend,
                    'spend_remaining' => $next['remaining'],
                ] : null,
                'eligible_games' => array_map(
                    static fn ($game): string => $game instanceof \App\Enums\DiscountGame ? $game->value : (string) $game,
                    $result->eligibleGames,
                ),
                'rule_version' => $result->ruleVersion,
                'evaluated_at' => $result->evaluatedAt,
                'source_version' => $result->sourceVersion,
            ],
        ]);
    }

    /**
     * GET /api/v1/account/grade/entitlements — the caller's per-game
     * entitlement answers for every public matrix game, explicit
     * eligible / not-eligible state included.
     */
    public function entitlements(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $this->evaluator->evaluate($user);

        return response()->json([
            'success' => true,
            'data' => [
                'grade' => $result->tier->key,
                'rule_version' => $result->ruleVersion,
                'entitlements' => $this->projection->userEntitlements($result->tier),
            ],
        ]);
    }
}
