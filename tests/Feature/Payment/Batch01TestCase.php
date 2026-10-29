<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Support\Facades\Artisan;

abstract class Batch01TestCase extends LaravelTestCase
{
    /** Original process and superglobal values, restored after each test. */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        $this->setEnvironment('APP_ENV', 'testing');
        $this->setEnvironment('APP_KEY', 'base64:'.base64_encode(str_repeat('a', 32)));
        $this->setEnvironment('CACHE_STORE', 'array');
        $this->setEnvironment('DB_CONNECTION', 'sqlite');
        $this->setEnvironment('DB_DATABASE', ':memory:');
        $this->setEnvironment('SESSION_DRIVER', 'array');
        $this->setEnvironment('QUEUE_CONNECTION', 'sync');
        $this->setEnvironment('REAL_MONEY_ENABLED', 'false');
        parent::setUp();
    }

    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        ob_start();
        try {
            $app->make(Kernel::class)->bootstrap();
        } finally {
            ob_end_clean();
        }

        return $app;
    }

    protected function migrateDatabase(): void
    {
        ob_start();
        try {
            self::assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]));
        } finally {
            ob_end_clean();
        }
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            foreach ($this->originalEnvironment as $key => $original) {
                if ($original['process'] === false) {
                    putenv($key);
                } else {
                    putenv($key.'='.$original['process']);
                }
                if ($original['env_present']) {
                    $_ENV[$key] = $original['env'];
                } else {
                    unset($_ENV[$key]);
                }
                if ($original['server_present']) {
                    $_SERVER[$key] = $original['server'];
                } else {
                    unset($_SERVER[$key]);
                }
            }
            $this->originalEnvironment = [];
        }
    }

    private function setEnvironment(string $key, string $value): void
    {
        if (! array_key_exists($key, $this->originalEnvironment)) {
            $this->originalEnvironment[$key] = [
                'process' => getenv($key),
                'env_present' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_present' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
            ];
        }
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
