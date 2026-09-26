<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Account\AccountDiscountService;
use App\Services\Account\AccountGradeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Authenticated Account Grade page + history.
 *
 * Grade is always calculated server-side for $request->user().
 * Clients cannot set grade, spend, or discount. Cross-user access
 * is impossible: no user id is accepted from the client.
 */
final class AccountGradeController
{
    public function __construct(
        private readonly AccountGradeService $grades,
        private readonly AccountDiscountService $discounts,
    ) {
    }

    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $grade = $this->grades->current($user);
        $history = $this->grades->history($user, 25);
        $discounts = $this->discounts->eligibleForDisplay($user);

        return view('account.grade', [
            'meta' => [
                'title' => (string) trans('account_services.grade_meta_title'),
                'description' => (string) trans('account_services.grade_meta_description'),
            ],
            'grade' => $grade,
            'history' => $history,
            'discounts' => $discounts,
            'tiers' => (array) config('account_grades.tiers', []),
        ]);
    }

    public function history(Request $request): \Illuminate\Http\JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'history' => $this->grades->history($user, 50),
            ],
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
