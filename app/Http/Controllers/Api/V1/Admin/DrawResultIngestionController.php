<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ResultIngestion\DrawResultConfirmationService;
use App\Services\ResultIngestion\GloResultIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class DrawResultIngestionController extends Controller
{
    public function __construct(
        private readonly GloResultIngestionService $ingestionService,
        private readonly DrawResultConfirmationService $confirmationService
    ) {}

    /**
     * Operator 1: Ingest draft draw result.
     */
    public function ingest(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'draw_id' => ['required', 'uuid'],
            'first_prize' => ['required', 'string', 'size:6'],
            'two_digit_back' => ['required', 'string', 'size:2'],
            'three_digit_front' => ['nullable', 'array'],
            'three_digit_back' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'VALIDATION_FAILED',
                'messages' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $operatorId = (string) $request->user()?->id ?? (string) $request->input('operator_id');

        try {
            $result = $this->ingestionService->ingestPayload(
                drawId: (string) $request->input('draw_id'),
                resultPayload: $request->only([
                    'first_prize',
                    'two_digit_back',
                    'three_digit_front',
                    'three_digit_back',
                    'second_prizes',
                    'third_prizes',
                ]),
                operatorId: $operatorId
            );

            return response()->json([
                'success' => true,
                'status' => 'RESULT_INGESTED_PENDING_CONFIRMATION',
                'data' => [
                    'id' => $result->id,
                    'draw_id' => $result->draw_id,
                    'fingerprint' => $result->payload_fingerprint,
                    'verification_status' => $result->verification_status,
                ],
            ], Response::HTTP_ACCEPTED);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'INGESTION_FAILED',
                'message' => $e->getMessage(),
            ], Response::HTTP_CONFLICT);
        }
    }

    /**
     * Operator 2: Confirm official result and trigger settlement.
     */
    public function confirm(Request $request, string $resultId): JsonResponse
    {
        $confirmerId = (string) $request->user()?->id ?? (string) $request->input('operator_id');

        try {
            $result = $this->confirmationService->confirmResult(
                drawResultId: $resultId,
                confirmingOperatorId: $confirmerId
            );

            return response()->json([
                'success' => true,
                'status' => 'RESULT_CONFIRMED_SETTLEMENT_TRIGGERED',
                'data' => [
                    'id' => $result->id,
                    'draw_id' => $result->draw_id,
                    'verification_status' => $result->verification_status,
                    'confirmed_at' => $result->confirmed_at?->toIso8601String(),
                ],
            ], Response::HTTP_OK);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'CONFIRMATION_REJECTED',
                'message' => $e->getMessage(),
            ], Response::HTTP_FORBIDDEN);
        }
    }
}
