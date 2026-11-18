<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ComplianceBadgeResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Compliance\ComplianceBadgeService;
use App\Services\Compliance\ResponsibleGamingSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public compliance badges + the member's own responsible-gaming summary.
 *
 * THE PUBLIC ENDPOINT RETURNS NOTHING IT CANNOT PROVE
 * ComplianceBadgeService is the only producer, and it returns a badge only when a
 * valid, unrevoked, evidence-bearing attestation row exists. With no such rows this
 * endpoint legitimately returns an empty `badges` array plus the two policy notices
 * (18+, play responsibly) that need no third party. A footer that says anything
 * else is a hardcoded claim and ForbiddenClaimsTest fails the build for it.
 */
final class ComplianceController
{
    public function __construct(
        private readonly ComplianceBadgeService $badges,
        private readonly ResponsibleGamingSummaryService $responsibleGaming,
    ) {
    }

    /**
     * GET /public/compliance-badges — no auth.
     */
    public function badges(): JsonResponse
    {
        $payload = $this->badges->publicPayload();

        /*
         * Badges are reshaped by the resource so `attested: true` is an explicit
         * field rather than something a client infers from a key being present.
         * Notices are NOT run through the resource: they carry no `code` and
         * asserting a credential against them would be exactly the claim this
         * endpoint exists to avoid.
         */
        $payload['badges'] = ComplianceBadgeResource::collection((array) ($payload['badges'] ?? []))->resolve();

        return ApiResponse::success($payload, 'Compliance statements loaded.');
    }

    /**
     * GET /me/responsible-gaming/summary — authenticated.
     */
    public function responsibleGamingSummary(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(
            $this->responsibleGaming->summary($user),
            'Responsible gaming summary loaded.',
        );
    }
}
