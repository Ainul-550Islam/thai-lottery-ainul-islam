<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Bot\BotDetectionService;
use App\Services\Bot\BotSecurityEventRecorder;
use App\Services\Bot\DTOs\BotDecisionData;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Opt-in HTTP observation adapter. This revision does NOT implement CAPTCHA,
 * security blocking or timing-token render integration. Part 5 records eligible
 * observations in the existing SecurityEvent ledger, independently of detection.
 * Explicitly rejects unsupported enforcement rather than silently allowing it.
 */
final class BotDetectionMiddleware
{
    public function __construct(
        private readonly Repository $config,
        private readonly Container $container,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $enabled = $this->config->get('bot.http_observation.enabled', false);
        $detectorEnabled = $this->config->get('bot.enabled', true);
        if (! is_bool($enabled) || ! is_bool($detectorEnabled)) {
            return $this->configurationFailure($request);
        }
        if (! $enabled || ! $detectorEnabled) {
            $request->attributes->set('bot.observation_status', 'disabled');

            return $next($request);
        }
        if (! $request->isMethod('POST')) {
            $request->attributes->set('bot.observation_status', 'skipped_method');

            return $next($request);
        }
        if ($this->config->get('bot.mode', 'log') !== 'log') {
            return $this->configurationFailure($request);
        }

        $decision = null;
        try {
            $decision = $this->container->make(BotDetectionService::class)->detect($request);
            $request->attributes->set('bot.decision', $decision);
            $request->attributes->set('bot.observation_status',
                in_array('BOT_SIGNAL_UNAVAILABLE', $decision->reasons, true)
                || in_array('BOT_OBSERVABILITY_UNAVAILABLE', $decision->reasons, true)
                    ? 'partial' : 'observed',
            );
        } catch (Throwable) {
            // Observation availability must not change existing login/register outcomes.
            // Operators see a stable diagnostic, not submitted PII or SQL/credentials.
            $request->attributes->remove('bot.decision');
            $request->attributes->set('bot.observation_status', 'unavailable');
            $this->warn('bot.http_observation_unavailable');
        }

        if ($decision instanceof BotDecisionData) {
            $this->recordObservation($request, $decision);
        }

        // Never wrap the downstream authentication/controller in our catch block.
        return $next($request);
    }

    private function recordObservation(Request $request, BotDecisionData $decision): void
    {
        if ($this->config->get('bot.audit.enabled', true) === false) {
            $request->attributes->set('bot.audit_status', 'disabled');

            return;
        }
        try {
            $event = $this->container->make(BotSecurityEventRecorder::class)->record($request, $decision);
            $request->attributes->set('bot.audit_status', $event === null ? 'not_required' : 'recorded');
        } catch (Throwable) {
            // Never claim durable recording succeeded when the store is unavailable.
            // This is log-only: audit failure must not lock a user out of auth.
            $request->attributes->set('bot.audit_status', 'unavailable');
            $this->warn('bot.security_event_recording_unavailable');
        }
    }

    private function configurationFailure(Request $request): JsonResponse
    {
        $request->attributes->remove('bot.decision');
        $request->attributes->set('bot.observation_status', 'configuration_error');
        $this->warn('bot.http_observation_configuration_error');

        return new JsonResponse([
            'success' => false,
            'error' => [
                'code' => 'bot_observation_configuration_error',
                'message' => 'This service is temporarily unavailable.',
            ],
        ], 503, ['Cache-Control' => 'no-store']);
    }

    private function warn(string $message): void
    {
        try {
            $this->logger->warning($message);
        } catch (Throwable) {
            // No recursive logging and no user-facing leak on a broken log transport.
        }
    }
}
