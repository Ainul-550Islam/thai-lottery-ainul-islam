<?php

declare(strict_types=1);

namespace App\Services\Bot\Contracts;

use Illuminate\Http\Request;

/**
 * Interface BotSignal
 *
 * Contract for individual bot risk indicators evaluated against incoming HTTP requests.
 */
interface BotSignal
{
    /**
     * Unique signal key identifier (e.g. 'user_agent', 'request_velocity').
     */
    public function getKey(): string;

    /**
     * Signal weight in total composite score calculation (1 to 100).
     */
    public function getWeight(): int;

    /**
     * Evaluate the request and return risk probability (0.0 = clean human, 1.0 = definite bot)
     * along with optional reason code.
     *
     * @param Request $request
     * @param array<string, mixed> $context
     * @return array{score: float, reason_code: string|null, metadata: array<string, mixed>}
     */
    public function evaluate(Request $request, array $context = []): array;
}
