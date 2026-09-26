<?php

declare(strict_types=1);

namespace App\Services\Lottery;

/**
 * Data Matrix parse contract (GLO-18).
 *
 * Implementations must never invent a successful decode for an encoding
 * they cannot legitimately read — return UNSUPPORTED_FORMAT / NOT_CONFIGURED.
 */
interface GloDataMatrixParserInterface
{
    public function mode(): string;

    /**
     * @return array{
     *     status: string,
     *     source_state: string,
     *     payload: array<string, mixed>|null,
     *     reason: string|null,
     *     schema: string|null
     * }
     */
    public function parse(string $raw): array;
}
