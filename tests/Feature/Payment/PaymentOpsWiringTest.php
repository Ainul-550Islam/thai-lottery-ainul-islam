<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Exceptions\FinancialException;
use App\Providers\PaymentOpsServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PaymentOpsWiringTest extends TestCase
{
    public function test_the_existing_payment_ops_provider_is_registered_and_exposes_its_guarded_routes(): void
    {
        self::assertNotNull(app()->getProvider(PaymentOpsServiceProvider::class), 'A class that is not registered cannot enforce the production boot refusal.');
        self::assertTrue(Gate::has('manage-payment-disbursements'));
        self::assertTrue(Route::has('payment_ops.readiness'));
        self::assertTrue(Route::has('payment_ops.manual-disbursements.complete'));
        self::assertArrayHasKey('payments:readiness', Artisan::all());
    }

    public function test_route_introspection_loads_all_controllers_and_the_payment_ops_routes(): void
    {
        self::assertSame(0, Artisan::call('route:list', ['--json' => true]));
        $routes = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        self::assertGreaterThan(100, count($routes));
        self::assertNotEmpty(array_filter($routes, static fn (array $route): bool => ($route['name'] ?? null) === 'payment_ops.manual-disbursements.complete'));
        self::assertNotEmpty(array_filter($routes, static fn (array $route): bool => str_contains((string) ($route['action'] ?? ''), 'ChatOperatorController')));
    }

    public function test_fresh_production_process_mounts_ops_when_disabled_but_refuses_unready_real_money(): void
    {
        // Synthetic local settings test boot wiring only, not production readiness,
        // legal authority, real credentials or provider connectivity.
        $env = [
            'APP_BASE_PATH' => base_path(), 'APP_ENV' => 'production', 'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'APP_CONFIG_CACHE' => sys_get_temp_dir().'/arena-nonexistent-payment-ops-config.php',
            'APP_ROUTES_CACHE' => sys_get_temp_dir().'/arena-nonexistent-payment-ops-routes.php',
            'SESSION_SECURE_COOKIE' => 'true', 'SESSION_ENCRYPT' => 'true', 'SESSION_DRIVER' => 'redis',
            'CACHE_STORE' => 'redis', 'QUEUE_CONNECTION' => 'redis', 'SANCTUM_TOKEN_EXPIRATION' => '1440',
            'GLO_OFFICIAL_SOURCE_MODE' => 'official', 'GLO_SOURCE_PRIORITY' => 'official',
            'GLO_FALL_THROUGH_TO_FIXTURE' => 'false', 'NATIONAL_LOTTERY_FIXTURE_ENABLED' => 'false',
            'WEEKLY_LOTTERY_FIXTURE_ENABLED' => 'false', 'BINGO_LOTTERY_FIXTURE_ENABLED' => 'false',
            'PCSO_LOTTERY_FIXTURE_ENABLED' => 'false', 'TRUEMONEY_ENABLED' => 'true',
            'REAL_MONEY_REQUIRE_READINESS_PASS' => 'true', 'PAYMENT_OPS_FAIL_BOOT_WHEN_UNREADY' => 'true',
            'TRUEMONEY_ACKNOWLEDGED_UNOFFICIAL_API' => 'false',
        ];
        $command = [PHP_BINARY, base_path('artisan'), 'route:list', '--name=payment_ops', '--no-ansi'];
        $disabled = new Process($command, base_path(), $env + ['REAL_MONEY_ENABLED' => 'false'], null, 30);
        $disabled->run();
        self::assertSame(0, $disabled->getExitCode(), $disabled->getOutput().$disabled->getErrorOutput());
        self::assertStringContainsString('payment_ops.readiness', $disabled->getOutput());
        $unready = new Process($command, base_path(), $env + ['REAL_MONEY_ENABLED' => 'true'], null, 30);
        $unready->run();
        self::assertNotSame(0, $unready->getExitCode());
        self::assertStringContainsString('Real-money operation is blocked because payment readiness checks failed', $unready->getOutput().$unready->getErrorOutput());
        self::assertStringContainsString('PaymentOpsServiceProvider.php', $unready->getOutput().$unready->getErrorOutput());
    }

    public function test_a_blocking_preflight_failure_refuses_real_money_production_boot(): void
    {
        $app = app();
        $previous = $app['env'];
        $app['env'] = 'production';
        config([
            'real_money.enabled' => true,
            'real_money.require_readiness_pass' => true,
            'payment_ops.readiness.fail_boot_when_real_money_unready' => true,
            'truemoney.enabled' => true,
            'truemoney.acknowledged_unofficial_api' => false,
        ]);
        try {
            (new PaymentOpsServiceProvider($app))->boot();
            self::fail('An enabled, unready production payment rail must stop boot.');
        } catch (FinancialException $exception) {
            self::assertSame('real_money_payment_readiness_failed', $exception->errorCode());
            self::assertNotEmpty($exception->context()['checks'] ?? []);
        } finally {
            $app['env'] = $previous;
        }
    }
}
