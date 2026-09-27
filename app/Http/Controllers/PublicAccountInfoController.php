<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Account\PublicAccountInfoService;
use Illuminate\Contracts\View\View;

/**
 * Public, signed-out explainers for the account programme.
 *
 * These are NOT the account pages. /account/grade and /account/verification
 * remain behind authentication and show a specific person their own spend,
 * their own grade and their own documents. These two show the ladder and the
 * process to somebody who has not registered yet and therefore cannot see
 * either.
 *
 * THIN, AND STRUCTURALLY INCAPABLE OF LEAKING. The controller takes no user,
 * reads no request input and calls one service that itself touches no model.
 * There is no code path here that could return personal data, because there
 * is nothing personal in scope.
 */
final class PublicAccountInfoController
{
    public function __construct(private readonly PublicAccountInfoService $info) {}

    /**
     * GET /account-grades
     */
    public function grades(): View
    {
        return view('account-info.grades', [
            'ladder' => $this->info->gradeLadder(),
            'meta' => [
                'title' => (string) trans('account_info.grades_meta_title'),
                'description' => (string) trans('account_info.grades_meta_description'),
                'canonical' => url('/account-grades'),
            ],
        ]);
    }

    /**
     * GET /account-verification-guide
     */
    public function verification(): View
    {
        return view('account-info.verification', [
            'guide' => $this->info->verificationSteps(),
            'meta' => [
                'title' => (string) trans('account_info.verification_meta_title'),
                'description' => (string) trans('account_info.verification_meta_description'),
                'canonical' => url('/account-verification-guide'),
            ],
        ]);
    }
}
