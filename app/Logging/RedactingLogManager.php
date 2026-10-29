<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;

/**
 * Preserve Laravel's channel drivers while redacting before destinations and
 * log events, including the framework's emergency and on-demand paths.
 */
class RedactingLogManager extends LogManager
{
    /** Preserve custom creators and manager-wide context registered before us. */
    public static function replacing(LogManager $previous, $app): self
    {
        $manager = new self($app);
        $manager->customCreators = $previous->customCreators;
        $manager->sharedContext = $previous->sharedContext;
        $manager->dateFormat = $previous->dateFormat;

        // Re-resolve any early channels so future calls use the safe wrapper.
        // An already handed-out external logger cannot be retroactively wrapped.
        return $manager;
    }

    protected function tap($name, Logger $logger)
    {
        // Laravel creates this Logger immediately before tap(), before shared
        // context is applied. Apply configured taps to our wrapper, not after it.
        $logger = new RedactingLogger($logger->getLogger(), $logger->getEventDispatcher());
        $logger = parent::tap($name, $logger);

        // On-demand or custom Monolog channels may omit the configured tap.
        // Add the processor for extra fields injected later by framework context.
        $underlying = $logger->getLogger();
        if ((bool) config('logging.redaction.enabled', true) && method_exists($underlying, 'pushProcessor')) {
            $processors = method_exists($underlying, 'getProcessors') ? $underlying->getProcessors() : [];
            if (! collect($processors)->contains(static fn ($processor): bool => $processor instanceof RedactingProcessor)) {
                $underlying->pushProcessor(RedactSensitiveData::processor());
            }
        }

        return $logger;
    }

    protected function createEmergencyLogger()
    {
        $logger = parent::createEmergencyLogger();

        return new RedactingLogger($logger->getLogger(), $logger->getEventDispatcher());
    }

    public function stack(array $channels, $channel = null)
    {
        $logger = parent::stack($channels, $channel);

        return (new RedactingLogger($logger->getLogger(), $logger->getEventDispatcher()))->withContext($this->sharedContext);
    }
}
