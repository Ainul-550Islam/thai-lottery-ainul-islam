<?php

declare(strict_types=1);

namespace App\Services\Support;

use RuntimeException;

/**
 * A support action this system refuses.
 *
 * Reasons used by Batch 3: too_many_open_conversations, conversation_not_found,
 * conversation_closed, message_too_long, already_rated, member_not_found.
 * Each is a distinct, stable code so the client can explain the refusal instead
 * of showing a generic error.
 */
final class SupportRefused extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'error' => 'support_refused',
            'reason' => $this->reasonCode,
            'message' => $this->getMessage(),
        ];
    }
}
