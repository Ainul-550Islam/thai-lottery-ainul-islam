<?php

declare(strict_types=1);

namespace App\Services\Lottery\Contracts;

/**
 * One rung on the official-result intake ladder.
 *
 * The ladder in GloResultProviderChain is ordered by AUTHORITY, not by health:
 * the official GLO endpoint first, a certified secondary second, manual signed
 * ingestion last. Every rung speaks this one contract so the chain can walk
 * them identically, and every rung answers with the SAME payload shape so that
 * "which source answered" is always comparable after the fact.
 *
 * Implementations must only ever target DOCUMENTED public catalog endpoints
 * (see GloResultProvider for the GLO endpoints) — never invented endpoints,
 * headers or private database integrations. When a source cannot produce a
 * result it returns a structured NOT_CONFIGURED / failed payload with a
 * failure_reason; it NEVER returns a fabricated number. A fabricated number
 * reaching a paying player is the one failure mode that is not recoverable by
 * an apology.
 */
interface ResultSource
{
    /**
     * Stable machine name of the rung (also the circuit-breaker key).
     */
    public function name(): string;

    /**
     * Whether this rung can attempt a fetch right now (config + connectivity).
     * A rung that is not configured is skipped, not asked.
     */
    public function isConfigured(): bool;

    /**
     * Fetch the official result for a draw number (or latest when null).
     *
     * @return array{
     *     status: string,            // imported | not_configured | failed
     *     provider: string,
     *     endpoint: string|null,
     *     draw_number: string|null,
     *     first_prize: string|null,  // 6-digit string, leading zeros kept
     *     second_prize: list<string>,
     *     third_prize: list<string>,
     *     n3: array<string, list<string>>,
     *     tiers: array<string, list<string>>,
     *     failure_reason: string|null,
     *     fingerprint: string|null   // pin for four-eyes confirmation
     * }
     */
    public function fetch(?string $drawNumber = null): array;
}
