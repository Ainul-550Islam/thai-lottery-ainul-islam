<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Security;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Security\ReviewFraudAlertRequest;
use App\Http\Resources\Api\V1\FraudAlertResource;
use App\Models\FraudAlert;
use App\Models\FraudCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class FraudAlertController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = FraudAlert::with('cases')->orderBy('created_at', 'desc');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($minScore = $request->query('min_score')) {
            $query->where('risk_score', '>=', (int) $minScore);
        }

        return FraudAlertResource::collection($query->paginate(20));
    }

    public function show(string $id): JsonResponse
    {
        /** @var FraudAlert $alert */
        $alert = FraudAlert::with('cases')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new FraudAlertResource($alert),
        ], Response::HTTP_OK);
    }

    public function review(ReviewFraudAlertRequest $request, string $id): JsonResponse
    {
        $reviewerId = (string) $request->user()?->id;

        return DB::transaction(function () use ($request, $id, $reviewerId) {
            /** @var FraudAlert $alert */
            $alert = FraudAlert::where('id', $id)->lockForUpdate()->firstOrFail();

            $action = (string) $request->input('action');
            $decisionReason = (string) $request->input('decision_reason');
            $metadata = (array) $request->input('metadata', []);

            $resolvedStatus = match ($action) {
                'RELEASE_ALLOW' => 'RESOLVED_ALLOW',
                'REJECT_DENY' => 'RESOLVED_DENY',
                'FREEZE_ACCOUNT' => 'RESOLVED_ACCOUNT_FROZEN',
                default => 'RESOLVED_CLOSED',
            };

            $alert->update([
                'status' => $resolvedStatus,
                'resolved_by_user_id' => $reviewerId,
                'resolution_notes' => $decisionReason,
                'resolved_at' => now(),
            ]);

            FraudCase::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'fraud_alert_id' => $alert->id,
                'reviewer_user_id' => $reviewerId,
                'action_taken' => $action,
                'decision' => $resolvedStatus,
                'decision_reason' => $decisionReason,
                'metadata' => $metadata,
                'decided_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Fraud alert [{$alert->id}] review decision recorded as {$resolvedStatus}.",
                'data' => new FraudAlertResource($alert->fresh()),
            ], Response::HTTP_OK);
        });
    }
}
