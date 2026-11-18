<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use RuntimeException;

/**
 * A preference change this system refuses to make.
 *
 * The one reason that matters is `mandatory_category`: a member asked to silence
 * a notice the operator is obliged to send. The refusal names the category so a
 * client can explain it, and it is a refusal rather than a silent no-op because a
 * toggle that appears to work and does nothing is worse than one that says no.
 */
final class NotificationPreferenceRefused extends RuntimeException
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
            'error' => 'notification_preference_refused',
            'reason' => $this->reasonCode,
            'message' => $this->getMessage(),
        ];
    }
}
