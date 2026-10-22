<?php

declare(strict_types=1);

namespace App\DTOs\Security;

use App\Enums\BotDecision;

/**
 * Class BotDecisionData
 *
 * Immutable data transfer object containing composite bot analysis results.
 */
readonly class BotDecisionData
{
    /**
     * @param  int  $score  Bot probability score (0 = definitely human, 100 = definitely automated bot)
     * @param  BotDecision  $decision  Action to take (allow, challenge, throttle, review, block)
     * @param  array<int, string>  $reasonCodes  List of stable reason codes explaining the score
     * @param  string  $policyVersion  Policy configuration version evaluated
     * @param  string  $correlationId  Unique request trace identifier
     * @param  array<string, mixed>  $signalDetails  Granular scoring per signal
     */
    public function __construct(
        public int $score,
        public BotDecision $decision,
        public array $reasonCodes,
        public string $policyVersion,
        public string $correlationId,
        public array $signalDetails = []
    ) {}

    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'decision' => $this->decision->value,
            'reason_codes' => $this->reasonCodes,
            'policy_version' => $this->policyVersion,
            'correlation_id' => $this->correlationId,
            'signal_details' => $this->signalDetails,
        ];
    }
}
