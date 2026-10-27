<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Process\Process;

/** Owns only a fresh private socket/AOF instance; never connects to an operator Redis. */
trait OwnsPrivateRedis
{
    private string $redisDirectory;

    private string $redisSocket;

    private Process $redisServer;

    private function createPrivateRedis(): void
    {
        $this->assertTrue(extension_loaded('redis') && function_exists('posix_kill'));
        $this->redisDirectory = base_path('../.cache/part25-redis/').bin2hex(random_bytes(8));
        mkdir($this->redisDirectory, 0700, true);
        $this->redisDirectory = realpath($this->redisDirectory);
        $this->redisSocket = $this->redisDirectory.'/db.sock';
        file_put_contents($this->redisDirectory.'/redis.conf', "port 0\nprotected-mode yes\nunixsocket {$this->redisSocket}\nunixsocketperm 700\ndir {$this->redisDirectory}\nappendonly yes\nappendfsync always\nsave \"\"\nmaxmemory-policy noeviction\ndatabases 2\nlogfile {$this->redisDirectory}/server.log\n");
        $this->bootPrivateRedis();
    }

    private function bootPrivateRedis(): void
    {
        $this->redisServer = new Process(['redis-server', $this->redisDirectory.'/redis.conf']);
        $this->redisServer->setTimeout(null);
        $this->redisServer->start();
        $deadline = microtime(true) + 8;
        do {
            try {
                $client = $this->privateRedis();
                $this->assertSame(1, (int) $client->info('persistence')['aof_enabled']);
                $this->assertSame('always', $client->config('GET', 'appendfsync')['appendfsync']);
                $this->assertSame('noeviction', $client->config('GET', 'maxmemory-policy')['maxmemory-policy']);
                $client->close();

                return;
            } catch (\RedisException) {
                usleep(50000);
            }
        } while ($this->redisServer->isRunning() && microtime(true) < $deadline);
        $this->fail('Private Redis instance did not become ready.');
    }

    private function privateRedis(int $database = 0): \Redis
    {
        $client = new \Redis;
        $client->connect($this->redisSocket, 0, 1.0);
        $client->select($database);

        return $client;
    }

    private function restartPrivateRedis(?callable $whileDown = null): array
    {
        $client = $this->privateRedis();
        $before = $client->info('server')['run_id'];
        $client->set('fixture:durability', 'synthetic');
        $client->close();
        $this->redisServer->signal(SIGKILL);
        $this->redisServer->wait();
        $this->assertSame(SIGKILL, $this->redisServer->getTermSignal());
        try {
            if ($whileDown !== null) {
                $whileDown();
            }
        } finally {
            $this->bootPrivateRedis();
        }
        $client = $this->privateRedis();
        $after = $client->info('server')['run_id'];
        $this->assertNotSame($before, $after);
        $this->assertSame('synthetic', $client->get('fixture:durability'));
        $version = $client->info('server')['redis_version'];
        $client->close();
        // Refresh parent connections only. Paused workers keep genuinely stale clients.
        app('redis')->purge('default');
        app('redis')->purge('cache');

        return ['before' => $before, 'after' => $after, 'version' => $version, 'signal' => SIGKILL];
    }

    private function destroyPrivateRedis(): void
    {
        if (isset($this->redisServer) && $this->redisServer->isRunning()) {
            $this->redisServer->stop(1);
        }
        if (isset($this->redisDirectory) && is_dir($this->redisDirectory)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->redisDirectory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->redisDirectory);
        }
    }
}
