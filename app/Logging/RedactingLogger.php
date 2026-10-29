<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Log\Logger;

/** Redact known sensitive fields before both the destination and MessageLogged. */
final class RedactingLogger extends Logger
{
    protected function writeLog($level, $message, $context): void
    {
        if (! (bool) config('logging.redaction.enabled', true)) {
            parent::writeLog($level, $message, $context);

            return;
        }

        $processor = RedactSensitiveData::processor();
        if (is_array($message)) {
            $message = $processor->redactArray($message);
        } elseif ($message instanceof Arrayable) {
            $message = $processor->redactArray($message->toArray());
        }

        // The framework otherwise merges the original shared context after
        // sanitizing the call-specific context and emits that unsanitized copy
        // to MessageLogged listeners. Sanitize both before the parent sees them.
        $this->context = $processor->redactArray($this->context);
        $context = $processor->redactArray($context);
        $message = $processor->redactString($this->formatMessage($message));

        parent::writeLog($level, $message, $context);
    }
}
