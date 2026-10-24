<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Security;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Security\ReviewFraudAlertRequest;
use App\Http\Resources\Api\V1\FraudAlertResource;
use App\Models\FraudAlert;
use App\Services\Security\FraudAlertReviewService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class FraudAlertController extends Controller
{
    public function __construct(
        private readonly FraudAlertReviewService $reviewService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FraudAlert::class);

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
        $alert = FraudAlert::with('cases')->findOrFail($id);
        $this->authorize('view', $alert);

        return response()->json([
            'success' => true,
            'data' => new FraudAlertResource($alert),
        ], Response::HTTP_OK);
    }

    public function review(ReviewFraudAlertRequest $request, string $id): JsonResponse
    {
        $reviewerId = (string) $request->user()?->id;

        try {
            $alert = $this->reviewService->review(
                alertId: $id,
                reviewerId: $reviewerId,
                action: (string) $request->input('action'),
                decisionReason: (string) $request->input('decision_reason'),
                metadata: (array) $request->input('metadata', []),
            );
        } catch (DomainException $e) {
            $status = str_contains($e->getMessage(), 'Maker-checker')
                ? Response::HTTP_FORBIDDEN
                : Response::HTTP_CONFLICT;

            return response()->json([
                'success' => false,
                'error' => $status === Response::HTTP_FORBIDDEN ? 'MAKER_CHECKER_VIOLATION' : 'FRAUD_ALERT_ALREADY_RESOLVED',
                'message' => $e->getMessage(),
            ], $status);
        }

        return response()->json([
            'success' => true,
            'message' => "Fraud alert [{$alert->id}] review decision recorded as {$alert->status}.",
            'data' => new FraudAlertResource($alert),
        ], Response::HTTP_OK);
    }
}
