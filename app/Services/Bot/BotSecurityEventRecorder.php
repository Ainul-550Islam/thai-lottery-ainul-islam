<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\DTOs\Security\SecurityEventData;
use App\Enums\SecurityEventType;
use App\Enums\SecurityRiskLevel;
use App\Models\SecurityEvent;
use App\Services\Bot\DTOs\BotDecisionData;
use App\Services\Bot\Enums\BotDecision;
use App\Services\Security\SecurityEventService;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;
use WeakMap;

/**
 * Adapter to the existing security ledger, not a second event persistence engine.
 * One immutable observation per Request/scoped recorder. Distinct HTTP attempts
 * receive distinct server-generated occurrence IDs, even within the same second.
 * Sequential retry is tested; cross-process exactly-once delivery is not claimed.
 */
final class BotSecurityEventRecorder
{
    /** @var WeakMap<Request, array{data: SecurityEventData, assessment: array}> */
    private WeakMap $observations;

    public function __construct(
        private readonly Repository $config,
        private readonly SecurityEventService $events,
    ) {
        $this->observations = new WeakMap;
    }

    public function record(Request $request, BotDecisionData $decision): ?SecurityEvent
    {
        $enabled = $this->config->get('bot.audit.enabled', true);
        $minimum = $this->config->get('bot.audit.min_score', 40);
        if (! is_bool($enabled) || ! is_int($minimum) || $minimum < 1 || $minimum > 100) {
            throw new InvalidArgumentException('Invalid bot audit configuration.');
        }
        if (! $enabled) {
            return null;
        }
        if ($decision->mode !== 'log' || $decision->decision !== BotDecision::Allow) {
            throw new LogicException('Observation recorder cannot attest to enforced actions.');
        }
        $assessment = $decision->toArray();
        if (isset($this->observations[$request]) && $this->observations[$request]['assessment'] !== $assessment) {
            throw new LogicException('A request cannot rewrite its original bot observation.');
        }
        if ($decision->score < $minimum) {
            return null;
        }

        if (! isset($this->observations[$request])) {
            // Resolve identity only from the authenticated user, never request user_id/email.
            $identifier = $request->user()?->getAuthIdentifier();
            $userId = filter_var($identifier, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $routeName = $request->route()?->getName();
            $routeName = is_string($routeName) && preg_match('/^[a-zA-Z0-9_.:-]{1,96}$/D', $routeName) ? $routeName : null;
            $method = $request->getMethod();
            $method = in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true) ? $method : 'OTHER';
            $risk = SecurityRiskLevel::fromScore($decision->score);
            $floor = SecurityEventType::BotSuspected->defaultFloor();
            if ($floor->isMoreSevereThan($risk)) {
                $risk = $floor;
            }
            $safeAssessment = $assessment;
            $safeAssessment['reasons'] = array_slice($decision->reasons, 0, 64);
            $data = new SecurityEventData(
                eventType: SecurityEventType::BotSuspected,
                userId: $userId,
                riskLevel: $risk,
                ipAddress: null,
                deviceFingerprint: null,
                sessionFingerprint: null,
                payload: [
                    'schema' => 'bot-observation-v1',
                    'occurrence' => bin2hex(random_bytes(16)),
                    'route' => $routeName,
                    'method' => $method,
                    'assessment' => $safeAssessment,
                    'reason_count' => count($decision->reasons),
                ],
                occurredAt: Carbon::now()->toImmutable(),
            );
            // Retain the same occurrence for a local retry if publication fails.
            $this->observations[$request] = ['data' => $data, 'assessment' => $assessment];
        }

        return $this->events->publish($this->observations[$request]['data'])['event'];
    }
}
