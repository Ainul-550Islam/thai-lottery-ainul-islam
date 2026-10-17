<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Services\Bot\DTOs\BotDecisionData;
use App\Services\Bot\DTOs\SignalResult;
use App\Services\Bot\Enums\BotDecision;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/** No request, database, cache, log, CAPTCHA or money side effects. */
final class BotScoreCalculator
{
    public function __construct(private readonly Repository $config) {}

    /** @param iterable<SignalResult> $signals */
    public function calculate(iterable $signals): BotDecisionData
    {
        $mode = $this->mode($this->config->get('bot.mode', 'log'));
        $enabled = $this->config->get('bot.enabled', true);
        if (! is_bool($enabled)) {
            throw new InvalidArgumentException('bot.enabled must be boolean.');
        }

        $challenge = $this->integer($this->config->get('bot.thresholds.challenge', 40), 1, 100);
        $block = $this->integer($this->config->get('bot.thresholds.block', 80), 1, 100);
        if ($challenge >= $block) {
            throw new InvalidArgumentException('Challenge threshold must be below block threshold.');
        }

        if (! $enabled) {
            return new BotDecisionData(0, BotDecision::Allow, [], BotDecision::Allow, 0, $mode);
        }

        $score = 0;
        $enforcementScore = 0;
        $reasons = [];
        $seen = [];
        foreach ($signals as $result) {
            if (! $result instanceof SignalResult) {
                throw new InvalidArgumentException('Expected a SignalResult.');
            }
            if (isset($seen[$result->signal])) {
                throw new InvalidArgumentException('A signal cannot be counted twice.');
            }
            $seen[$result->signal] = true;

            $settings = $this->config->get('bot.signals.'.$result->signal);
            if (! is_array($settings)) {
                throw new InvalidArgumentException('Bot signal is not configured.');
            }
            $signalMode = $this->mode($settings['mode'] ?? 'log');
            if (! is_bool($settings['enabled'] ?? null)) {
                throw new InvalidArgumentException('Signal enabled flag must be boolean.');
            }
            $weight = $this->integer($settings['weight'] ?? null, 0, 100);
            if (! $settings['enabled']) {
                continue;
            }

            // Integer half-up rounding; strength=100 contributes the configured weight.
            $points = intdiv($weight * $result->strength + 50, 100);
            if ($points === 0) {
                continue;
            }
            $score = min(100, $score + $points);
            if ($signalMode === 'enforce') {
                $enforcementScore = min(100, $enforcementScore + $points);
            }
            foreach ($result->reasons as $reason) {
                $reasons[$reason] = true;
            }
        }

        return new BotDecisionData(
            $score,
            $mode === 'enforce' ? $this->decision($enforcementScore, $challenge, $block) : BotDecision::Allow,
            array_keys($reasons),
            $this->decision($score, $challenge, $block),
            $enforcementScore,
            $mode,
        );
    }

    private function decision(int $score, int $challenge, int $block): BotDecision
    {
        return match (true) {
            $score >= $block => BotDecision::Block,
            $score >= $challenge => BotDecision::Challenge,
            default => BotDecision::Allow,
        };
    }

    private function mode(mixed $mode): string
    {
        if (! is_string($mode) || ! in_array($mode, ['log', 'enforce'], true)) {
            throw new InvalidArgumentException('Bot mode must be log or enforce.');
        }

        return $mode;
    }

    private function integer(mixed $value, int $minimum, int $maximum): int
    {
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException('Invalid bot weight or threshold.');
        }

        return $value;
    }
}
