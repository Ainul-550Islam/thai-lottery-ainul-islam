<?php

declare(strict_types=1);

namespace App\Services\Bot\DTOs;

use App\Services\Bot\Enums\BotDecision;
use InvalidArgumentException;

/** Observed risk and permitted enforcement are deliberately separate. */
final readonly class BotDecisionData
{
    /** @param list<string> $reasons */
    public function __construct(
        public int $score,
        public BotDecision $decision,
        public array $reasons,
        public BotDecision $assessedDecision,
        public int $enforcementScore,
        public string $mode,
    ) {
        if ($score < 0 || $score > 100 || $enforcementScore < 0 || $enforcementScore > $score) {
            throw new InvalidArgumentException('Invalid bot decision score.');
        }

        if (! in_array($mode, ['log', 'enforce'], true)) {
            throw new InvalidArgumentException('Unknown bot mode.');
        }

        if ($mode === 'log' && $decision !== BotDecision::Allow) {
            throw new InvalidArgumentException('Log mode must not enforce a decision.');
        }

        if (! array_is_list($reasons)) {
            throw new InvalidArgumentException('Decision reasons must be a list.');
        }

        foreach ($reasons as $reason) {
            if (! is_string($reason) || ! preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $reason)) {
                throw new InvalidArgumentException('Decision reasons must be stable, non-PII codes.');
            }
        }
    }

    /** @return array<string, int|string|list<string>> */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'decision' => $this->decision->value,
            'reasons' => $this->reasons,
            'assessed_decision' => $this->assessedDecision->value,
            'enforcement_score' => $this->enforcementScore,
            'mode' => $this->mode,
        ];
    }
}
