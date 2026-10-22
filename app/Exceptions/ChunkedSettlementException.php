<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A chunked settlement run refused an operation.
 *
 * Distinct from SettlementSimulationException on purpose: that family covers
 * the MONEY logic (a tampered result, an unreadable selection), while this
 * one covers the RUN bookkeeping (a finalize attempted too early, a cursor
 * that no longer matches). Operators triage them differently — a money
 * refusal stops the draw; a run refusal usually just means "not yet".
 */
final class ChunkedSettlementException extends RuntimeException
{
    public const CANNOT_FINALIZE = 'CHUNKED_RUN_CANNOT_FINALIZE';

    public const RUN_NOT_FOUND = 'CHUNKED_RUN_NOT_FOUND';

    public const CURSOR_MISMATCH = 'CHUNKED_RUN_CURSOR_MISMATCH';

    public const RUN_ALREADY_COMPLETED = 'CHUNKED_RUN_ALREADY_COMPLETED';

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }

    public static function cannotFinalize(int $runId, int $remainingBets): self
    {
        return new self(
            sprintf('Settlement run %d cannot finalize: %d bet(s) remain past the cursor.', $runId, $remainingBets),
            self::CANNOT_FINALIZE,
            ['run_id' => $runId, 'remaining_bets' => $remainingBets],
        );
    }

    public static function runNotFound(int $drawId): self
    {
        return new self(
            sprintf('No chunked settlement run exists for draw %d.', $drawId),
            self::RUN_NOT_FOUND,
            ['draw_id' => $drawId],
        );
    }
}
