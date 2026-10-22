<?php

declare(strict_types=1);

namespace App\Http\Controllers\Legal;

use App\Http\Requests\Legal\AgeAttestationRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Legal\AgeVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AccountAgeVerificationController
{
    public function __construct(
        private readonly AgeVerificationService $ageVerification,
    ) {}

    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('legal.age-verification', [
            'status' => $this->ageVerification->status($user),
            'dateOfBirthOnFile' => $user->date_of_birth !== null,
        ]);
    }

    public function store(AgeAttestationRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->ageVerification->attest(
            $user,
            $request,
            is_string($request->validated('date_of_birth')) ? $request->validated('date_of_birth') : null,
            'account_settings',
        );

        return redirect()
            ->route('player.dashboard')
            ->with('status', 'Your 18+ age attestation has been recorded.');
    }

    public function statusApi(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(
            data: $this->ageVerification->status($user),
            message: 'Age-attestation status.',
        );
    }

    public function storeApi(AgeAttestationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = $this->ageVerification->attest(
            $user,
            $request,
            is_string($request->validated('date_of_birth')) ? $request->validated('date_of_birth') : null,
            'api',
        );

        return ApiResponse::success(
            data: [
                'status' => $record->status,
                'method' => $record->method,
                'minimum_age' => (int) $record->minimum_age,
                'attested_at' => $record->attested_at?->toIso8601String(),
                'eligible' => $this->ageVerification->isEligible($user->refresh()),
            ],
            message: 'Age attestation recorded.',
        );
    }
}
