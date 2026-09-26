<?php

declare(strict_types=1);

namespace App\Services\Lottery;

/**
 * Contract for a GLO official-result source.
 *
 * Implementations must only ever target DOCUMENTED public catalog endpoints:
 *   POST www.glo.or.th/api/lottery/getLatestLottery
 *   GET/POST www.glo.or.th/api/checking/getLotteryResult
 *
 * Never invent endpoints, headers or private GLO-DB integrations. When the
 * source cannot produce a result, implementors return a structured
 * NOT_CONFIGURED / failed payload — never a fabricated number.
 */
interface GloResultProvider
{
    public function name(): string;

    /**
     * Whether this provider can attempt a fetch right now (config + connectivity).
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
     *     fingerprint: string|null
     * }
     */
    public function fetch(?string $drawNumber = null): array;
}
