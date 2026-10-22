<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Bot\BotDetectionService;
use App\Services\Bot\BotScoreCalculator;
use App\Services\Bot\Contracts\BotSignal;
use App\Services\Bot\DeviceFingerprintService;
use App\Services\Bot\Signals\DeviceReuseSignal;
use App\Services\Bot\Signals\FailedAuthenticationSignal;
use App\Services\Bot\Signals\FormTimingSignal;
use App\Services\Bot\Signals\HeaderConsistencySignal;
use App\Services\Bot\Signals\RequestRateSignal;
use App\Services\Bot\Signals\RequestVelocitySignal;
use App\Services\Bot\Signals\UserAgentSignal;
use App\Services\Velocity\VelocityWindow;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Bot-detection wiring.
 *
 * LAZY BY DESIGN
 * Registration must not resolve the database, the cache, or any signal store:
 * this provider is registered on EVERY boot, including console commands,
 * migrations and the unit suite running with a deliberately poisoned `db` /
 * `cache` binding. Everything below is a closure; nothing is built until a
 * caller actually asks the container for BotDetectionService.
 *
 * SCOPED, NOT SINGLETON
 * The engine memoises one decision per Request object (a WeakMap), so its
 * lifetime must follow the request/job scope. `scoped()` gives exactly that:
 * reused within a scope, discarded by forgetScopedInstances() between
 * requests or jobs, never leaked across tenants of a long-running worker.
 *
 * A DISABLED ENGINE RESOLVES NOTHING STATEFUL
 * When `bot.enabled` is false the engine returns an empty Allow decision
 * without consulting a signal, so the provider does not even construct the
 * signals that need the database (device reuse) or the cache (request rate).
 * A switched-off control must not become a boot-time dependency.
 */
final class BotServiceProvider extends ServiceProvider
{
    /**
     * Signal registry: config key => implementation class.
     *
     * @var array<string, class-string>
     */
    private const SIGNALS = [
        'user_agent' => UserAgentSignal::class,
        'header_consistency' => HeaderConsistencySignal::class,
        'form_timing' => FormTimingSignal::class,
        'request_rate' => RequestRateSignal::class,
        'device_reuse' => DeviceReuseSignal::class,
        'failed_auth' => FailedAuthenticationSignal::class,
        'request_velocity' => RequestVelocitySignal::class,
    ];

    public function register(): void
    {
        $this->app->scoped(BotDetectionService::class, function ($app): BotDetectionService {
            $config = $this->config($app);

            return new BotDetectionService(
                $config,
                new BotScoreCalculator($config),
                $app->make(LoggerInterface::class),
                $this->signals($app, $config),
            );
        });
    }

    /**
     * Build only the signals that are switched on. An enabled signal with no
     * entry in the registry is left absent on purpose: BotDetectionService
     * refuses it loudly ("An enabled bot signal has no implementation") rather
     * than this provider silently substituting something else.
     *
     * @return array<string, BotSignal>
     */
    private function signals($app, Repository $config): array
    {
        if ($config->get('bot.enabled', true) === false) {
            return [];
        }

        $configured = $config->get('bot.signals');
        if (! is_array($configured)) {
            return [];
        }

        $signals = [];

        foreach ($configured as $name => $settings) {
            if (! is_string($name) || ! is_array($settings) || ($settings['enabled'] ?? false) !== true) {
                continue;
            }

            $signal = match ($name) {
                'user_agent' => new UserAgentSignal,
                'header_consistency' => new HeaderConsistencySignal,
                'form_timing' => new FormTimingSignal($config),
                'request_rate' => new RequestRateSignal(
                    $config,
                    $app->make(CacheRepository::class),
                    new DeviceFingerprintService($config),
                    $app->make(VelocityWindow::class),
                ),
                'device_reuse' => new DeviceReuseSignal(
                    $config,
                    $app->make(ConnectionInterface::class),
                    new DeviceFingerprintService($config),
                ),
                'failed_auth' => new FailedAuthenticationSignal,
                'request_velocity' => new RequestVelocitySignal,
                default => null,
            };

            if ($signal !== null) {
                $signals[$name] = $signal;
            }
        }

        return $signals;
    }

    private function config($app): Repository
    {
        $config = $app->bound('config') ? $app->make('config') : $app->make(ConfigRepository::class);

        return $config instanceof Repository ? $config : new Repository($config->all());
    }
}
