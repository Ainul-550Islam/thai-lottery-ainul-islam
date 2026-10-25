<?php

declare(strict_types=1);

namespace App\Support\Ops;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\File;
use Pusher\Pusher;
use ReflectionClass;
use Throwable;

/**
 * The real-time lane checks, extracted from RealtimeCheckCommand so the
 * command, ProductionPreflight and the tests ask the same questions and get
 * the same answers.
 *
 * WHAT A CHECK MAY PRINT
 * Keys, secrets and passwords are never echoed — only whether they are
 * present, and what shape the configuration has. These lines end up in CI
 * transcripts, deploy logs and incident tickets.
 *
 * WHEN THE LANE IS DARK
 * With REALTIME_ENABLED off, the connection and reachability checks report
 * SKIP (the lane is deliberately dark) while the STATIC checks still run, so a
 * missing channel callback or a wildcard origin is found before the switch is
 * flipped.
 */
final class RealtimeChecks
{
    /**
     * @param  bool  $skipNetwork  do not open TCP/TLS connections
     * @return list<CheckResult>
     */
    public function run(bool $skipNetwork = false): array
    {
        $results = [];
        $enabled = (bool) config('realtime.enabled', false);
        $environment = (string) config('app.env', 'production');
        $production = in_array($environment, ['production', 'prod'], true);
        $connection = (string) config('broadcasting.default', 'log');
        $driver = (string) config('broadcasting.connections.'.$connection.'.driver', $connection);

        $results[] = $this->checkBroadcastConnection($enabled, $connection, $driver);
        $results[] = $this->checkDriverDependency($enabled, $driver);
        $results[] = $this->checkCredentials($enabled, $connection);
        $results[] = $this->checkAllowedOrigins($production);
        $results[] = $this->checkScaling();
        $results[] = $this->checkQueueConnection($production);
        $results[] = $this->checkChannelCallbacks();
        $results[] = $this->checkClientBlock();

        if ($enabled && ! $skipNetwork) {
            $results[] = $this->checkReachability($connection);
        } else {
            $results[] = CheckResult::skip(
                'reverb.reachable',
                $enabled ? 'network checks skipped by option' : 'realtime disabled (REALTIME_ENABLED=false)',
            );
        }

        return $results;
    }

    public function checkBroadcastConnection(bool $enabled, string $connection, string $driver): CheckResult
    {
        $live = in_array($driver, ['reverb', 'pusher'], true);

        if (! $enabled) {
            return CheckResult::pass('broadcast.connection', sprintf('realtime disabled; BROADCAST_CONNECTION=%s', $connection));
        }

        return $live
            ? CheckResult::pass('broadcast.connection', sprintf('BROADCAST_CONNECTION=%s (driver %s)', $connection, $driver))
            : CheckResult::fail('broadcast.connection', sprintf('BROADCAST_CONNECTION=%s delivers nothing to a browser; use reverb or pusher', $connection));
    }

    public function checkDriverDependency(bool $enabled, string $driver): CheckResult
    {
        if (! $enabled || ! in_array($driver, ['reverb', 'pusher'], true)) {
            return CheckResult::skip('broadcast.dependency', 'not required for the current driver');
        }

        $present = class_exists(Pusher::class);

        return $present
            ? CheckResult::pass('broadcast.dependency', 'pusher/pusher-php-server is installed')
            : CheckResult::fail('broadcast.dependency', 'pusher/pusher-php-server is not installed; the server cannot publish (composer require laravel/reverb)');
    }

    public function checkCredentials(bool $enabled, string $connection): CheckResult
    {
        if (! $enabled) {
            return CheckResult::skip('broadcast.credentials', 'realtime disabled');
        }

        $key = (string) config('broadcasting.connections.'.$connection.'.key', '');
        $secret = (string) config('broadcasting.connections.'.$connection.'.secret', '');
        $appId = (string) config('broadcasting.connections.'.$connection.'.app_id', '');
        $missing = [];

        if (trim($key) === '') {
            $missing[] = 'key';
        }
        if (trim($secret) === '') {
            $missing[] = 'secret';
        }
        if (trim($appId) === '') {
            $missing[] = 'app_id';
        }

        return $missing === []
            ? CheckResult::pass('broadcast.credentials', 'app id, key and secret are set (values not shown)')
            : CheckResult::fail('broadcast.credentials', 'missing: '.implode(', ', $missing));
    }

    public function checkAllowedOrigins(bool $production): CheckResult
    {
        $apps = config('reverb.apps.apps', []);
        $origins = [];

        if (is_array($apps)) {
            foreach ($apps as $app) {
                foreach ((array) ($app['allowed_origins'] ?? []) as $origin) {
                    $origins[] = (string) $origin;
                }
            }
        }

        $wildcard = in_array('*', $origins, true);

        if ($production) {
            $ok = ! $wildcard && $origins !== [];
            $detail = $wildcard
                ? 'allowed_origins contains * in production; set REVERB_ALLOWED_ORIGINS to the site origins'
                : ($origins === [] ? 'allowed_origins is empty; no browser can connect' : 'allowed origins: '.implode(', ', $origins));
        } else {
            $ok = true;
            $detail = $origins === [] ? 'allowed_origins is empty' : 'allowed origins: '.implode(', ', $origins);
        }

        return $ok
            ? CheckResult::pass('reverb.allowed_origins', $detail)
            : CheckResult::fail('reverb.allowed_origins', $detail);
    }

    public function checkScaling(): CheckResult
    {
        $nodes = max(1, (int) config('realtime.app_nodes', 1));
        $scaling = (bool) config('reverb.servers.reverb.scaling.enabled', false);

        if ($nodes <= 1) {
            return CheckResult::pass('reverb.scaling', sprintf('single node (REALTIME_APP_NODES=%d); scaling %s', $nodes, $scaling ? 'enabled' : 'not required'));
        }

        return $scaling
            ? CheckResult::pass('reverb.scaling', sprintf('%d nodes with Redis scaling enabled', $nodes))
            : CheckResult::fail('reverb.scaling', sprintf('%d nodes but REVERB_SCALING_ENABLED is off; a subscriber on one node never hears a publish from another', $nodes));
    }

    public function checkQueueConnection(bool $production): CheckResult
    {
        $queue = (string) config('queue.default', 'sync');
        $ok = $queue !== 'sync' || ! $production;

        $detail = $queue === 'sync'
            ? ($production ? 'QUEUE_CONNECTION=sync; broadcasts would run inside web requests' : 'QUEUE_CONNECTION=sync (acceptable outside production)')
            : sprintf('QUEUE_CONNECTION=%s', $queue);

        return $ok
            ? CheckResult::pass('queue.connection', $detail)
            : CheckResult::fail('queue.connection', $detail);
    }

    /**
     * Every PrivateChannel/PresenceChannel any ShouldBroadcast event constructs
     * must have a callback in routes/channels.php, keyed on the same prefix
     * with a {wildcard}.
     */
    public function checkChannelCallbacks(): CheckResult
    {
        $channelsFile = base_path('routes/channels.php');
        $defined = [];

        if (File::exists($channelsFile)) {
            preg_match_all("/Broadcast::channel\('([^']+)'/", (string) File::get($channelsFile), $m);
            $defined = $m[1] ?? [];
        }

        $missing = [];
        $checked = 0;

        foreach (glob(app_path('Events/*.php')) ?: [] as $file) {
            $fqcn = 'App\\Events\\'.basename($file, '.php');

            if (! class_exists($fqcn)) {
                continue;
            }

            $rc = new ReflectionClass($fqcn);

            if ($rc->isAbstract() || ! $rc->implementsInterface(ShouldBroadcast::class)) {
                continue;
            }

            $src = (string) File::get($file);
            preg_match_all("/new (PrivateChannel|PresenceChannel)\('([^']+)'/", $src, $cm, PREG_SET_ORDER);

            foreach ($cm as $c) {
                $checked++;
                $prefix = explode("'+", $c[2])[0];
                $candidates = [$c[2], $prefix.'{id}', rtrim($prefix, '.').'.{id}'];

                $found = false;

                foreach ($defined as $pattern) {
                    if (in_array($pattern, $candidates, true) || $this->patternCovers($pattern, $prefix)) {
                        $found = true;
                        break;
                    }
                }

                if (! $found) {
                    $missing[] = basename($file, '.php').' -> '.$c[2];
                }
            }
        }

        return $missing === []
            ? CheckResult::pass('channels.authorized', sprintf('%d private channel reference(s) have authorization callbacks', $checked))
            : CheckResult::fail('channels.authorized', 'no callback for: '.implode('; ', $missing));
    }

    public function checkClientBlock(): CheckResult
    {
        $client = config('realtime.client', []);
        $leaks = [];

        if (is_array($client)) {
            $secret = (string) config('broadcasting.connections.reverb.secret', '');

            foreach ($client as $name => $value) {
                if (is_string($value) && $secret !== '' && $value === $secret) {
                    $leaks[] = (string) $name;
                }

                if (preg_match('/secret|password/i', (string) $name) === 1) {
                    $leaks[] = (string) $name;
                }
            }
        }

        return $leaks === []
            ? CheckResult::pass('client.block', 'browser client block carries no secret')
            : CheckResult::fail('client.block', 'secret-shaped values in realtime.client: '.implode(', ', $leaks));
    }

    public function checkReachability(string $connection): CheckResult
    {
        $options = (array) config('broadcasting.connections.'.$connection.'.options', []);
        $host = (string) ($options['host'] ?? '');
        $port = (int) ($options['port'] ?? 0);
        $scheme = strtolower((string) ($options['scheme'] ?? 'http'));
        $tls = $scheme === 'https' || (bool) ($options['useTLS'] ?? false);
        $timeout = max(1, (int) config('realtime.check.connect_timeout_seconds', 3));

        if ($host === '' || $port <= 0) {
            return CheckResult::fail('reverb.reachable', 'host/port not configured');
        }

        $target = ($tls ? 'tls' : 'tcp').'://'.$host.':'.$port;
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);

        try {
            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

            if (! is_resource($socket)) {
                return CheckResult::fail('reverb.reachable', sprintf('%s:%d (%s) refused: %s', $host, $port, $tls ? 'tls' : 'tcp', $errstr !== '' ? $errstr : 'errno '.$errno));
            }

            fclose($socket);

            return CheckResult::pass('reverb.reachable', sprintf('%s:%d answered (%s handshake in <= %ds)', $host, $port, $tls ? 'tls' : 'tcp', $timeout));
        } catch (Throwable $e) {
            return CheckResult::fail('reverb.reachable', sprintf('%s:%d: %s', $host, $port, $e::class));
        }
    }

    private function patternCovers(string $pattern, string $prefix): bool
    {
        $patternPrefix = explode('{', $pattern)[0];

        return $patternPrefix !== '' && $patternPrefix === $prefix;
    }
}
