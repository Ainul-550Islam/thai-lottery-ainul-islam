<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The single place where a mapped error becomes a standard API error envelope.
 *
 * WHY THIS CLASS EXISTS
 * The exception handler lives under App\Exceptions, and the domain-boundary
 * guards keep that namespace free of HTTP-layer references - a class in there
 * must not name a response builder or a JSON response type. The handler still
 * owns the decision (which mapper, which log context, which forwarded headers);
 * this adapter only performs the final, mechanical serialisation.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * - No exception inspection. Mapping a throwable to a code and a status is
 *   BetPurchaseErrorMapper's job; the input here is already mapped and safe.
 * - No data of its own. Everything in the envelope comes from the mapped array
 *   handed in, so nothing internal can leak through this class.
 */
final class ApiErrorEnvelope
{
    /**
     * Serialise an already-mapped error into the standard error envelope.
     *
     * @param  array{code: string, message: string, status: int, details: array<string, mixed>}  $mapped
     * @param  array<string, mixed>  $headers
     */
    public static function error(array $mapped, array $headers = []): JsonResponse
    {
        return ApiResponse::error(
            code: $mapped['code'],
            message: $mapped['message'],
            status: $mapped['status'],
            details: $mapped['details'],
            headers: $headers,
        );
    }
}
