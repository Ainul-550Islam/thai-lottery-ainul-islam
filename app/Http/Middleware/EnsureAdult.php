<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Legal\AgeVerificationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Require a current adult eligibility attestation before protected transactions. */
final class EnsureAdult
{
    public function __construct(
        private readonly AgeVerificationService $ageVerification,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->ageVerification->isEligible($user)) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error(
                    code: 'age_attestation_required',
                    message: 'An eligible 18+ age attestation is required before this action.',
                    status: 403,
                    details: [
                        'attestation_url' => '/account/age-verification',
                        'attestation_method' => 'self_declaration',
                    ],
                );
            }

            return redirect()
                ->route('account.age-verification.form')
                ->with('status', 'Please complete the 18+ age attestation before continuing.');
        }

        return $next($request);
    }
}
