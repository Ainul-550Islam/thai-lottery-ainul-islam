<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use Illuminate\Console\Command;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\File;
use Pusher\Pusher;
use ReflectionClass;
use Throwable;

/**
 * `php artisan realtime:check` — is the real-time lane actually wired, end to end?
 *
 * The lane has failed silently before at every layer (log driver in production,
 * a private channel with no callback, a wildcard origin, one Reverb node behind
 * two app nodes), and none of those failures shows up in a health probe. Each
 * check below is one line, PASS or FAIL, with the reason; the exit code is 1 on
 * any FAIL so a deploy script can gate on it. Nothing printed is a secret: keys,
 * secrets and passwords are never echoed, only whether they are present.
 *
 * When REALTIME_ENABLED is off the connection/reachability checks report SKIP
 * (the lane is deliberately dark) but the static checks still run, so a
 * missing channel callback is found before the switch is flipped.
 */
final class RealtimeCheckCommand extends Command
{
    protected $signature = 'realtime:check
        {--skip-network : Do not open TCP/TLS connections (static configuration checks only)}
        {--json : Emit the result list as JSON}';

    protected $description = 'Verify the real-time lane: broadcast driver, Reverb reachability, allowed origins, scaling, queue and private-channel authorization.';

    /** @var list<array{id: string, status: string, detail: string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->results = []; // the console kernel reuses the command instance across calls
        $enabled = (bool) config('realtime.enabled', false);
        $environment = (string) config('app.env', 'production');
        $production = in_array($environment, ['production', 'prod'], true);
        $connection = (string) config('broadcasting.default', 'log');
        $driver = (string) config('broadcasting.connections.'.$connection.'.driver', $connection);

        $this->checkBroadcastConnection($enabled, $connection, $driver);
        $this->checkDriverDependency($enabled, $driver);
        $this->checkCredentials($enabled, $connection);
        $this->checkAllowedOrigins($production);
        $this->checkScaling();
        $this->checkQueueConnection($production);
        $this->checkChannelCallbacks();
        $this->checkClientBlock();

        if ($enabled && ! $this->option('skip-network')) {
            $this->checkReachability($connection);
        } else {
            $this->skip('reverb.reachable', $enabled ? 'network checks skipped by option' : 'realtime disabled (REALTIME_ENABLED=false)');
        }

        $failed = count(array_filter($this->results, static fn (array $r): bool => $r['status'] === 'FAIL'));

        if ($this->option('json')) {
            $json = (string) json_encode([
                'status' => $failed === 0 ? 'PASS' : 'FAIL',
                'checks' => $this->results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            foreach (explode("\n", $json) as $line) {
                $this->line($line);
            }
        } else {
            foreach ($this->results as $result) {
                $this->line(sprintf('%s  %-28s %s', $result['status'], $result['id'], $result['detail']));
            }

            $this->line(sprintf('%d check(s), %d failed', count($this->results), $failed));
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function checkBroadcastConnection(bool $enabled, string $connection, string $driver): void
    {
        $live = in_array($driver, ['reverb', 'pusher'], true);

        if (! $enabled) {
            $this->record('broadcast.connection', true, sprintf('realtime disabled; BROADCAST_CONNECTION=%s', $connection));

            return;
        }

        $this->record(
            'broadcast.connection',
            $live,
            $live
                ? sprintf('BROADCAST_CONNECTION=%s (driver %s)', $connection, $driver)
                : sprintf('BROADCAST_CONNECTION=%s delivers nothing to a browser; use reverb or pusher', $connection),
        );
    }

    private function checkDriverDependency(bool $enabled, string $driver): void
    {
        if (! $enabled || ! in_array($driver, ['reverb', 'pusher'], true)) {
            $this->skip('broadcast.dependency', 'not required for the current driver');

            return;
        }

        $present = class_exists(Pusher::class);

        $this->record(
            'broadcast.dependency',
            $present,
            $present ? 'pusher/pusher-php-server is installed' : 'pusher/pusher-php-server is not installed; the server cannot publish (composer require laravel/reverb)',
        );
    }

    private function checkCredentials(bool $enabled, string $connection): void
    {
        if (! $enabled) {
            $this->skip('broadcast.credentials', 'realtime disabled');

            return;
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

        $this->record(
            'broadcast.credentials',
            $missing === [],
            $missing === [] ? 'app id, key and secret are set (values not shown)' : 'missing: '.implode(', ', $missing),
        );
    }

    private function checkAllowedOrigins(bool $production): void
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

        $this->record('reverb.allowed_origins', $ok, $detail);
    }

    private function checkScaling(): void
    {
        $nodes = max(1, (int) config('realtime.app_nodes', 1));
        $scaling = (bool) config('reverb.servers.reverb.scaling.enabled', false);

        if ($nodes <= 1) {
            $this->record('reverb.scaling', true, sprintf('single node (REALTIME_APP_NODES=%d); scaling %s', $nodes, $scaling ? 'enabled' : 'not required'));

            return;
        }

        $this->record(
            'reverb.scaling',
            $scaling,
            $scaling
                ? sprintf('%d nodes with Redis scaling enabled', $nodes)
                : sprintf('%d nodes but REVERB_SCALING_ENABLED is off; a subscriber on one node never hears a publish from another', $nodes),
        );
    }

    private function checkQueueConnection(bool $production): void
    {
        $queue = (string) config('queue.default', 'sync');
        $ok = $queue !== 'sync' || ! $production;

        $this->record(
            'queue.connection',
            $ok,
            $queue === 'sync'
                ? ($production ? 'QUEUE_CONNECTION=sync; broadcasts would run inside web requests' : 'QUEUE_CONNECTION=sync (acceptable outside production)')
                : sprintf('QUEUE_CONNECTION=%s', $queue),
        );
    }

    /**
     * Every PrivateChannel/PresenceChannel any ShouldBroadcast event constructs must have a
     * callback in routes/channels.php, keyed on the same prefix with a {wildcard}.
     */
    private function checkChannelCallbacks(): void
    {
        $channelsFile = base_path('routes/channels.php');
        $defined = [];

        if (File::exists($channelsFile)) {
            preg_match_all("/Broadcast::channel\\('([^']+)'/", (string) File::get($channelsFile), $m);
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
            preg_match_all("/new (PrivateChannel|PresenceChannel)\\('([^']+)'/", $src, $cm, PREG_SET_ORDER);

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

        $this->record(
            'channels.authorized',
            $missing === [],
            $missing === []
                ? sprintf('%d private channel reference(s) have authorization callbacks', $checked)
                : 'no callback for: '.implode('; ', $missing),
        );
    }

    private function patternCovers(string $pattern, string $prefix): bool
    {
        $patternPrefix = explode('{', $pattern)[0];

        return $patternPrefix !== '' && $patternPrefix === $prefix;
    }

    private function checkClientBlock(): void
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

        $this->record(
            'client.block',
            $leaks === [],
            $leaks === [] ? 'browser client block carries no secret' : 'secret-shaped values in realtime.client: '.implode(', ', $leaks),
        );
    }

    private function checkReachability(string $connection): void
    {
        $options = (array) config('broadcasting.connections.'.$connection.'.options', []);
        $host = (string) ($options['host'] ?? '');
        $port = (int) ($options['port'] ?? 0);
        $scheme = strtolower((string) ($options['scheme'] ?? 'http'));
        $tls = $scheme === 'https' || (bool) ($options['useTLS'] ?? false);
        $timeout = max(1, (int) config('realtime.check.connect_timeout_seconds', 3));

        if ($host === '' || $port <= 0) {
            $this->record('reverb.reachable', false, 'host/port not configured');

            return;
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
                $this->record('reverb.reachable', false, sprintf('%s:%d (%s) refused: %s', $host, $port, $tls ? 'tls' : 'tcp', $errstr !== '' ? $errstr : 'errno '.$errno));

                return;
            }

            fclose($socket);
            $this->record('reverb.reachable', true, sprintf('%s:%d answered (%s handshake in <= %ds)', $host, $port, $tls ? 'tls' : 'tcp', $timeout));
        } catch (Throwable $e) {
            $this->record('reverb.reachable', false, sprintf('%s:%d: %s', $host, $port, $e::class));
        }
    }

    private function record(string $id, bool $ok, string $detail): void
    {
        $this->results[] = ['id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
    }

    private function skip(string $id, string $detail): void
    {
        $this->results[] = ['id' => $id, 'status' => 'SKIP', 'detail' => $detail];
    }
}
