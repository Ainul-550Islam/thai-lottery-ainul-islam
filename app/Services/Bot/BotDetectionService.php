<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\DTOs\Security\BotDecisionData;
use App\Events\BotBlocked;
use App\Events\BotSuspected;
use App\Services\Bot\Contracts\BotSignal;
use App\Services\Bot\Signals\DeviceReuseSignal;
use App\Services\Bot\Signals\FailedAuthenticationSignal;
use App\Services\Bot\Signals\FormTimingSignal;
use App\Services\Bot\Signals\HeaderConsistencySignal;
use App\Services\Bot\Signals\RequestVelocitySignal;
use App\Services\Bot\Signals\UserAgentSignal;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Class BotDetectionService
 *
 * Orchestrator service evaluating composable signals to make canonical bot abuse decisions.
 */
class BotDetectionService
{
    /** @var array<int, BotSignal> */
    private array $signals;

    public function __construct(
        private readonly BotScoreCalculator $scoreCalculator,
        private readonly DeviceFingerprintService $fingerprintService
    ) {
        $this->signals = [
            new UserAgentSignal(),
            new HeaderConsistencySignal(),
            new RequestVelocitySignal(),
            new FormTimingSignal(),
            new FailedAuthenticationSignal(),
            new DeviceReuseSignal(),
        ];
    }

    /**
     * Analyze request and return an explainable BotDecisionData object.
     */
    public function analyze(Request $request, array $context = []): BotDecisionData
    {
        $correlationId = (string) Str::uuid();
        $deviceToken = $this->fingerprintService->generateToken($request);
        $context['device_token'] = $deviceToken;

        $signalResults = [];
        foreach ($this->signals as $signal) {
            $eval = $signal->evaluate($request, $context);
            $signalResults[$signal->getKey()] = [
                'score' => $eval['score'],
                'weight' => $signal->getWeight(),
                'reason_code' => $eval['reason_code'],
                'metadata' => $eval['metadata'],
            ];
        }

        $calculated = $this->scoreCalculator->calculate($signalResults);

        $decisionData = new BotDecisionData(
            score: $calculated['score'],
            decision: $calculated['decision'],
            reasonCodes: $calculated['reason_codes'],
            policyVersion: (string) config('bot.policy_version', '1.0.0'),
            correlationId: $correlationId,
            signalDetails: $signalResults
        );

        $clientIp = $request->ip() ?? '127.0.0.1';
        $userId = $request->user()?->id ? (string) $request->user()->id : null;

        if ($decisionData->decision->isBlocked()) {
            event(new BotBlocked($clientIp, $userId, $request->path(), $decisionData));
        } elseif ($decisionData->score >= 45) {
            event(new BotSuspected($clientIp, $userId, $request->path(), $decisionData));
        }

        return $decisionData;
    }
}
