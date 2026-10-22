<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A settlement PREVIEW refused an operation.
 *
 * A preview is a read-only rehearsal: it prices a draw exactly as settlement
 * would and records what WOULD be paid, moving no money. Every refusal here
 * is therefore about the rehearsal's integrity — most importantly the
 * read-only boundary itself. Anything that would turn a preview into an
 * execution (a mode flag, a live flag, a caller-supplied amount) is refused
 * with READ_ONLY_MODE rather than merely ignored, because "ignored" flags
 * have a habit of becoming honoured flags in a later refactor.
 */
final class SettlementPreviewException extends RuntimeException
{
    public const READ_ONLY_MODE = 'SETTLEMENT_PREVIEW_READ_ONLY';

    public const PREVIEW_NOT_FOUND = 'SETTLEMENT_PREVIEW_NOT_FOUND';

    public const CURSOR_MISMATCH = 'SETTLEMENT_PREVIEW_CURSOR_MISMATCH';

    public const RULES_CHANGED = 'SETTLEMENT_PREVIEW_RULES_CHANGED';

    public const INVALID_CHUNK_SIZE = 'SETTLEMENT_PREVIEW_INVALID_CHUNK_SIZE';

    public const PREVIEW_INCOMPLETE = 'SETTLEMENT_PREVIEW_INCOMPLETE';

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        private readonly string $errorCode = self::READ_ONLY_MODE,
        string $message = '',
        private readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $errorCode, 0, $previous);
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
}
