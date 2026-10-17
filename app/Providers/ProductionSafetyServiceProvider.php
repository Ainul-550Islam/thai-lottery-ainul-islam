<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Class ProductionSafetyServiceProvider
 *
 * Hardened production circuit-breaker that refuses to boot the application
 * if critical financial security or infrastructure settings are misconfigured.
 */
class ProductionSafetyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (!App::environment('production')) {
            return;
        }

        // 1. Refuse to boot if Debug Mode is enabled
        if (Config::get('app.debug') === true) {
            throw new RuntimeException('FATAL SECURITY VIOLATION: APP_DEBUG must never be TRUE in production.');
        }

        // 2. Refuse to boot if Queue Driver is sync (would block HTTP threads during settlement/deduction)
        if (Config::get('queue.default') === 'sync') {
            throw new RuntimeException('FATAL ARCHITECTURAL VIOLATION: QUEUE_CONNECTION must not be "sync" in production. Use Redis/Horizon.');
        }

        // 3. Refuse to boot if Cache Store is file (breaks multi-node distributed locks & rate limiters)
        if (Config::get('cache.default') === 'file') {
            throw new RuntimeException('FATAL CLUSTER VIOLATION: CACHE_STORE must not be "file". Use Redis Cluster.');
        }

        // 4. Refuse to boot if Session Driver is file
        if (Config::get('session.driver') === 'file') {
            throw new RuntimeException('FATAL CLUSTER VIOLATION: SESSION_DRIVER must be "redis" or "cookie" for multi-node deployments.');
        }

        // 5. Enforce Strong Application Encryption Key
        $appKey = (string) Config::get('app.key');
        if (empty($appKey) || !str_starts_with($appKey, 'base64:')) {
            throw new RuntimeException('FATAL CRYPTO VIOLATION: Valid base64 APP_KEY is required for HMAC and token security.');
        }
    }
}
