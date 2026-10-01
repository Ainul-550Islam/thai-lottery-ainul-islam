<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Account\AccountDiscountService;
use App\Services\Account\AccountGradeEvaluator;
use App\Services\Account\AccountGradeService;
use App\Services\Lottery\DiscountParityProjectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AccountGradeController extends Controller
{
    public function __construct(
        private readonly AccountGradeService $grades,
        private readonly AccountDiscountService $discounts,
        private readonly AccountGradeEvaluator $evaluator,
        private readonly DiscountParityProjectionService $projection,
    ) {
    }

    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $grade = $this->grades->current($user);
        $history = $this->grades->history($user, 25);
        $discounts = $this->discounts->eligibleForDisplay($user);

        // GRADE PARITY BATCH: the canonical evaluation of the SAME user
        // through the same window/spend source the engine uses, plus the
        // explicit per-game entitlement answers for the caller's own
        // page. Figures arrive pre-resolved; the view only escapes.
        $evaluation = $this->evaluator->evaluate($user);
        $nextTier = $this->evaluator->nextTierFor($user);

        return view('account.grade', [
            'meta' => [
                'title' => (string) trans('account_services.grade_meta_title'),
                'description' => (string) trans('account_services.grade_meta_description'),
            ],
            'grade' => $grade,
            'history' => $history,
            'discounts' => $discounts,
            'tiers' => (array) config('account_grades.tiers', []),
            'evaluation' => [
                'spend_remaining_to_next' => $nextTier['remaining'],
                'eligible_games' => $this->projection->userEntitlements($evaluation->tier),
                'rule_version' => $evaluation->ruleVersion,
            ],
        ]);
    }

    public function history(Request $request): View|\Illuminate\Http\JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $history = $this->grades->history($user, 50);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'history' => $history,
                ],
            ]);
        }

        return view('account.grade-history', [
            'meta' => [
                'title' => (string) trans('account_services.grade_meta_title'),
                'description' => (string) trans('account_services.grade_meta_description'),
            ],
            'history' => $history,
        ]);
    }

    public function refresh(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->grades->recalculate($user);

        return redirect()
            ->route('account.grade')
            ->with('success', (string) trans('account_services.grade_refreshed'));
    }
}
