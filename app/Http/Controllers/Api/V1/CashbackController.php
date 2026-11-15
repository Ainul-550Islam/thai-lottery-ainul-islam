<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\FinancialException;
use App\Http\Requests\Api\V1\ClaimCashbackRequest;
use App\Http\Resources\CashbackSummaryResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Cashback\CashbackBalanceReader;
use App\Services\Cashback\CashbackClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CashbackController
{
    public function __construct(
        private readonly CashbackBalanceReader $reader,
        private readonly CashbackClaimService $claims,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $member */
        $member = $request->user();

        $summary = (new CashbackSummaryResource($this->reader->summary($member)))->resolve($request);

        return ApiResponse::success($summary, 'Cashback summary retrieved.');
    }

    public function claim(ClaimCashbackRequest $request): JsonResponse
    {
        /** @var User $member */
        $member = $request->user();

        try {
            $result = $this->claims->claim($member, $request->idempotencyKey());
        } catch (FinancialException $exception) {
            return ApiResponse::error(
                code: $exception->errorCode() ?? 'cashback_error',
                message: $exception->getMessage(),
                status: 422,
            );
        }

        return ApiResponse::success(
            data: $result,
            message: $result['replayed'] ? 'Cashback claim replayed.' : 'Cashback claimed successfully.',
            status: $result['replayed'] ? 200 : 201,
        );
    }
}
