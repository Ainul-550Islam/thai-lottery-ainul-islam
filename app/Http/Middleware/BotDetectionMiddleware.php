<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\BotDecision;
use App\Services\Bot\BotDetectionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class BotDetectionMiddleware
 *
 * Applies mode-aware bot detection (`log_only`, `challenge`, `enforce`) to scoped routes.
 */
class BotDetectionMiddleware
{
    public function __construct(
        private readonly BotDetectionService $botService
    ) {}

    public function handle(Request $request, Closure $next, string $scope = 'general'): Response
    {
        $mode = (string) config('bot.mode', 'log_only');

        $decisionData = $this->botService->analyze($request, ['scope' => $scope]);

        // Attach decision to request attributes for downstream telemetry
        $request->attributes->set('bot_decision', $decisionData);

        if ($mode === 'log_only') {
            return $next($request);
        }

        if ($decisionData->decision === BotDecision::BLOCK && $mode === 'enforce') {
            return response()->json([
                'success' => false,
                'error' => 'AUTOMATED_REQUEST_BLOCKED',
                'message' => 'Request rejected due to high automated risk score.',
                'correlation_id' => $decisionData->correlationId,
            ], Response::HTTP_FORBIDDEN);
        }

        if ($decisionData->decision === BotDecision::CHALLENGE && in_array($mode, ['challenge', 'enforce'], true)) {
            return response()->json([
                'success' => false,
                'error' => 'CAPTCHA_CHALLENGE_REQUIRED',
                'message' => 'Step-up verification challenge required to proceed.',
                'correlation_id' => $decisionData->correlationId,
            ], Response::HTTP_PRECONDITION_REQUIRED);
        }

        return $next($request);
    }
}
