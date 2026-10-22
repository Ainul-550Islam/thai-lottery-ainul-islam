<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DTOs\Security\BotDecisionData as SecurityBotDecisionData;
use App\Enums\BotDecision as SecurityBotDecision;
use App\Events\BotBlocked;
use App\Events\BotSuspected;
use App\Services\Bot\BotDetectionService;
use App\Services\Bot\BotSecurityEventRecorder;
use App\Services\Bot\DTOs\BotDecisionData;
use App\Services\Bot\Enums\BotDecision;
use App\Services\Network\TrustedClientIpResolver;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Opt-in HTTP observation & direct policy evaluation middleware.
 */
final class BotDetectionMiddleware
{
    private readonly Repository $config;

    private readonly Container $container;

    private readonly LoggerInterface $logger;

    private readonly ?BotDetectionService $directService;

    private readonly ?TrustedClientIpResolver $ipResolver;

    public function __construct(
        mixed $config = null,
        mixed $container = null,
        ?LoggerInterface $logger = null,
    ) {
        if ($config instanceof BotDetectionService) {
            if (! $container instanceof TrustedClientIpResolver) {
                throw new InvalidArgumentException('TrustedClientIpResolver is required when passing BotDetectionService.');
            }
            $this->directService = $config;
            $this->ipResolver = $container;
            $this->config = app('config');
            $this->container = app();
            $this->logger = $logger ?? app('log');
        } else {
            $resolvedConfig = $config ?? app('config');
            $resolvedContainer = $container ?? app();
            $resolvedLogger = $logger ?? app(LoggerInterface::class);
            if (! $resolvedConfig instanceof Repository || ! $resolvedContainer instanceof Container) {
                throw new InvalidArgumentException('Repository and Container are required.');
            }
            $this->config = $resolvedConfig;
            $this->container = $resolvedContainer;
            $this->logger = $resolvedLogger;
            $this->directService = null;
            $this->ipResolver = null;
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->directService !== null && $this->ipResolver !== null) {
            return $this->handleDirectPolicy($request, $next);
        }

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

    private function handleDirectPolicy(Request $request, Closure $next): Response
    {
        $ip = $this->ipResolver->resolve($request);
        $userId = $request->user()?->getAuthIdentifier() !== null ? (string) $request->user()->getAuthIdentifier() : null;
        $decision = $this->directService->detect($request);

        $secDecision = match ($decision->decision) {
            BotDecision::Block => SecurityBotDecision::BLOCK,
            BotDecision::Challenge => SecurityBotDecision::CHALLENGE,
            default => SecurityBotDecision::ALLOW,
        };
        $dto = new SecurityBotDecisionData(
            score: $decision->score,
            decision: $secDecision,
            reasonCodes: $decision->reasons,
            policyVersion: 'v1',
            correlationId: (string) ($request->headers->get('X-Correlation-ID') ?? ''),
        );

        if ($decision->mode === 'enforce' && $decision->decision === BotDecision::Block) {
            event(new BotBlocked($ip, $userId, $request->getPathInfo(), $dto));

            return new JsonResponse([
                'success' => false,
                'error_code' => 'AUTOMATED_REQUEST_BLOCKED',
                'message' => 'Automated request blocked.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($decision->score > 0) {
            event(new BotSuspected($ip, $userId, $request->getPathInfo(), $dto));
        }

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
