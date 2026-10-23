<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Services\Bot\Contracts\BotSignal;
use App\Services\Bot\DTOs\BotDecisionData;
use App\Services\Bot\DTOs\SignalResult;
use App\Services\Bot\Enums\BotDecision;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use WeakMap;

/**
 * Explicit composition root: supply configured signal-name => BotSignal pairs.
 * Register this as a singleton/scoped service when wiring route middleware.
 * WeakMap prevents duplicate token consumption/rate hits within the SAME service
 * instance and Request object, without retaining long-lived worker requests.
 * Not a middleware, financial gate, CAPTCHA verifier or durable SecurityEvent store.
 */
final class BotDetectionService
{
    /** @var WeakMap<Request, BotDecisionData> */
    private WeakMap $decisions;

    private readonly Repository $config;

    private readonly LoggerInterface $logger;

    private readonly BotScoreCalculator $calculator;

    /** @var array<string, BotSignal> */
    private readonly array $signals;

    private readonly bool $directSignalListMode;

    /**
     * @param  Repository|array<int|string, BotSignal>  $config
     * @param  array<string, BotSignal>  $signals
     */
    public function __construct(
        mixed $config = null,
        ?BotScoreCalculator $calculator = null,
        ?LoggerInterface $logger = null,
        array $signals = [],
    ) {
        $calculator ??= app(BotScoreCalculator::class);
        $this->calculator = $calculator;
        if (is_array($config)) {
            $this->config = app('config');
            $this->logger = $logger ?? app('log');
            $mapped = [];
            foreach ($config as $key => $signal) {
                if (! $signal instanceof BotSignal) {
                    throw new InvalidArgumentException('Bot signals must implement BotSignal.');
                }
                $name = is_string($key) ? $key : $signal->score(Request::create('/'))->signal;
                $mapped[$name] = $signal;
            }
            $this->signals = $mapped;
            $this->directSignalListMode = true;
        } else {
            if (! $logger instanceof LoggerInterface) {
                throw new InvalidArgumentException('LoggerInterface is required.');
            }
            foreach ($signals as $name => $signal) {
                if (! is_string($name) || ! $signal instanceof BotSignal) {
                    throw new InvalidArgumentException('Bot signals must be keyed BotSignal instances.');
                }
                new SignalResult($name, 0);
            }
            $this->config = $config;
            $this->logger = $logger;
            $this->signals = $signals;
            $this->directSignalListMode = false;
        }
        $this->decisions = new WeakMap;
    }

    public function detect(Request $request): BotDecisionData
    {
        if (isset($this->decisions[$request])) {
            return $this->decisions[$request];
        }

        if ($this->directSignalListMode) {
            foreach ($this->signals as $name => $_signal) {
                $existing = $this->config->get('bot.signals.'.$name);
                if (! is_array($existing)) {
                    $this->config->set('bot.signals.'.$name, [
                        'enabled' => true,
                        'weight' => 70,
                        'mode' => 'enforce',
                    ]);
                } else {
                    if ($this->config->get('bot.mode') === 'enforce') {
                        $this->config->set('bot.signals.'.$name.'.mode', 'enforce');
                    }
                    if (((int) ($existing['weight'] ?? 0)) < 70) {
                        $this->config->set('bot.signals.'.$name.'.weight', 70);
                    }
                }
            }
        }

        // Validate global config even when disabled; do not call any signal then.
        $empty = $this->calculator->calculate([]);
        if ($this->config->get('bot.enabled', true) === false) {
            return $this->decisions[$request] = $empty;
        }
        $configured = $this->config->get('bot.signals');
        if (! is_array($configured) || $configured === []) {
            throw new InvalidArgumentException('A configured bot signal registry is required.');
        }
        $zeroes = [];
        foreach ($configured as $name => $settings) {
            if (! is_string($name) || ! is_array($settings)) {
                throw new InvalidArgumentException('Invalid bot signal registry.');
            }
            $zeroes[] = new SignalResult($name, 0);
            if ($this->directSignalListMode && ! isset($this->signals[$name])) {
                continue;
            }
            if (($settings['enabled'] ?? null) === true && ! isset($this->signals[$name])) {
                throw new InvalidArgumentException('An enabled bot signal has no implementation.');
            }
        }
        // Validate weights/modes before stateful signals mutate session/cache.
        $this->calculator->calculate($zeroes);
        $results = [];
        $unavailable = [];
        foreach ($configured as $name => $settings) {
            if (! $settings['enabled'] || ($this->directSignalListMode && ! isset($this->signals[$name]))) {
                continue;
            }
            try {
                $result = $this->signals[$name]->score($request);
                if ($result->signal !== $name) {
                    throw new LogicException('Signal result does not match its registered identity.');
                }
                $results[] = $result;
            } catch (LogicException $error) {
                // Configuration/programming errors must never be disguised as low risk.
                throw $error;
            } catch (Throwable $error) {
                if ($empty->mode === 'enforce' && ($settings['mode'] ?? 'log') === 'enforce') {
                    // Future middleware must map this to service-unavailable, not a bot verdict.
                    throw new RuntimeException('Bot evidence unavailable; enforcement assessment aborted.', 0, $error);
                }
                // Observation failure is explicit, not a fabricated high score or block.
                $unavailable[] = $name;
            }
        }
        $decision = $this->calculator->calculate($results);
        if ($unavailable !== []) {
            $decision = $this->withReason($decision, 'BOT_SIGNAL_UNAVAILABLE');
        }
        if ($decision->assessedDecision !== BotDecision::Allow || $unavailable !== []) {
            try {
                // No IP, fingerprint, submitted identifier, token, UA or exception message.
                $this->logger->info('bot.assessment', $decision->toArray() + ['unavailable_signals' => $unavailable]);
            } catch (Throwable $error) {
                if ($empty->mode === 'enforce') {
                    throw new RuntimeException('Bot observability unavailable.', 0, $error);
                }
                $decision = $this->withReason($decision, 'BOT_OBSERVABILITY_UNAVAILABLE');
            }
        }

        return $this->decisions[$request] = $decision;
    }

    private function withReason(BotDecisionData $decision, string $reason): BotDecisionData
    {
        return new BotDecisionData(
            $decision->score,
            $decision->decision,
            array_values(array_unique([...$decision->reasons, $reason])),
            $decision->assessedDecision,
            $decision->enforcementScore,
            $decision->mode,
        );
    }
}
