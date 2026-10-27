<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Support\Facades\Artisan;

abstract class Batch01TestCase extends LaravelTestCase
{
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

    private function setEnvironment(string $key, string $value): void
    {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
