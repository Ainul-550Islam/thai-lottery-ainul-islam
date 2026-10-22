<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\IdempotencyRecord;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class IdempotencyLockMiddleware
 *
 * Prevents double-spending and duplicate submissions by combining an atomic Redis lock
 * with persistent database response caching.
 */
class IdempotencyLockMiddleware
{
    private const LOCK_TTL_SECONDS = 30;

    public function handle(Request $request, Closure $next): Response
    {
        // Only enforce for mutating financial verbs
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $idempotencyKey = $request->header('X-Idempotency-Key');

        if (! $idempotencyKey) {
            return response()->json([
                'success' => false,
                'error' => 'IDEMPOTENCY_KEY_REQUIRED',
                'message' => 'Header X-Idempotency-Key is mandatory for mutating financial requests.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $userId = $request->user()?->id;
        $requestPath = $request->path();
        $requestHash = hash('sha256', $request->getContent());

        // 1. Check persistent database record
        /** @var IdempotencyRecord|null $existingRecord */
        $existingRecord = IdempotencyRecord::where('idempotency_key', $idempotencyKey)->first();

        if ($existingRecord) {
            if ($existingRecord->isCompleted()) {
                // Return cached response immediately
                return response()->json(
                    $existingRecord->response_body,
                    $existingRecord->response_status,
                    array_merge($existingRecord->response_headers ?? [], ['X-Cache' => 'Idempotency-Hit'])
                );
            }

            // In-flight conflict
            return response()->json([
                'success' => false,
                'error' => 'REQUEST_IN_FLIGHT',
                'message' => 'A request with this idempotency key is currently processing. Please wait.',
            ], Response::HTTP_CONFLICT);
        }

        // 2. Acquire Atomic Distributed Lock in Redis
        $redisLockKey = "lock:idempotency:{$idempotencyKey}";
        $lockAcquired = Redis::set($redisLockKey, 'locked', 'EX', self::LOCK_TTL_SECONDS, 'NX');

        if (! $lockAcquired) {
            return response()->json([
                'success' => false,
                'error' => 'CONCURRENT_IDEMPOTENT_SUBMISSION',
                'message' => 'Concurrent execution detected with the same idempotency key.',
            ], Response::HTTP_CONFLICT);
        }

        // 3. Create IN_FLIGHT database record
        $record = IdempotencyRecord::create([
            'id' => Str::uuid()->toString(),
            'idempotency_key' => $idempotencyKey,
            'user_id' => $userId,
            'request_path' => $requestPath,
            'request_hash' => $requestHash,
            'status' => 'IN_FLIGHT',
            'locked_at' => now(),
        ]);

        try {
            /** @var Response $response */
            $response = $next($request);

            // 4. Capture response and update record to COMPLETED
            $responseBody = $response instanceof JsonResponse
                ? $response->getData(true)
                : json_decode($response->getContent() ?: '{}', true);

            $record->update([
                'response_status' => $response->getStatusCode(),
                'response_headers' => ['Content-Type' => 'application/json'],
                'response_body' => $responseBody,
                'status' => 'COMPLETED',
                'completed_at' => now(),
            ]);

            return $response;
        } catch (\Throwable $e) {
            $record->update(['status' => 'FAILED']);
            throw $e;
        } finally {
            Redis::del($redisLockKey);
        }
    }
}
