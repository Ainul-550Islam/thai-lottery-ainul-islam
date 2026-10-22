<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Legal\ConsentService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Block financial actions until both current legal-document records exist. */
final class RequireCurrentLegalConsent
{
    public function __construct(
        private readonly ConsentService $consents,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->consents->hasCurrentAcceptance($user)) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error(
                    code: 'current_legal_acknowledgement_required',
                    message: 'Please review and accept the current Terms and acknowledge the Privacy Notice.',
                    status: 428,
                    details: [
                        'documents_url' => '/api/v1/legal/documents/current',
                        'acceptance_url' => '/api/v1/legal/consents',
                    ],
                );
            }

            return redirect()
                ->route('legal.consent.form')
                ->with('status', 'Please review the current Terms and Privacy Notice before continuing.');
        }

        return $next($request);
    }
}
