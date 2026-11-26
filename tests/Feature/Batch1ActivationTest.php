<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\Security\NewDeviceLoginDetected;
use App\Listeners\Security\RecordLoginEvent;
use App\Models\MemberBankAccount;
use App\Models\RewardClaim;
use App\Providers\RewardsServiceProvider;
use App\Services\Security\LoginHistory\GeoIp\GeoIpResolver;
use App\Services\Security\LoginHistory\GeoIp\GeoIpResolverManager;
use App\Services\Security\LoginHistory\GeoIp\NullGeoIpResolver;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Proves Batch 1 is not just present but ACTIVE: provider loaded, listeners wired,
 * scheduler entries registered, policies bound, all 15 routes live, GeoIP offline.
 */
final class Batch1ActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_is_loaded_and_geoip_defaults_to_the_offline_null_driver(): void
    {
        $this->assertArrayHasKey(RewardsServiceProvider::class, $this->app->getLoadedProviders());
        $resolver = $this->app->make(GeoIpResolver::class);
        $this->assertInstanceOf(GeoIpResolverManager::class, $resolver);
        $this->assertInstanceOf(NullGeoIpResolver::class, $resolver->driver());
    }

    public function test_login_and_new_device_listeners_are_registered(): void
    {
        $this->assertTrue(Event::hasListeners(Login::class));
        $this->assertTrue(Event::hasListeners(Failed::class));
        $this->assertTrue(Event::hasListeners(NewDeviceLoginDetected::class));
    }

    public function test_login_listener_is_bound_exactly_once_per_event(): void
    {
        foreach ([Login::class, Failed::class] as $event) {
            $matches = 0;
            foreach (Event::getFacadeRoot()->getListeners($event) as $registered) {
                if (! $registered instanceof \Closure) {
                    continue;
                }
                $bound = (new \ReflectionFunction($registered))->getStaticVariables()['listener'] ?? null;
                if ((is_string($bound) && str_starts_with($bound, RecordLoginEvent::class.'@'))
                    || (is_array($bound) && ($bound[0] ?? null) === RecordLoginEvent::class)) {
                    $matches++;
                }
            }
            $this->assertSame(1, $matches, "RecordLoginEvent must be bound exactly once for {$event}");
        }
    }

    public function test_scheduler_has_cashback_expiry_and_prune_entries(): void
    {
        $commands = collect($this->app->make(Schedule::class)->events())
            ->map(static fn ($event): string => (string) ($event->command ?? $event->description ?? ''))
            ->implode(' | ');

        $this->assertStringContainsString('cashback:accrue --period=daily', $commands);
        $this->assertStringContainsString('cashback:accrue --period=weekly', $commands);
        $this->assertStringContainsString('security:prune-login-events', $commands);
    }

    public function test_policies_are_bound(): void
    {
        $this->assertNotNull(Gate::getPolicyFor(MemberBankAccount::class));
        $this->assertNotNull(Gate::getPolicyFor(RewardClaim::class));
    }

    public function test_exactly_the_fifteen_batch1_routes_exist(): void
    {
        $expected = [
            'GET me/cashback', 'POST me/cashback/claim',
            'GET me/rewards/catalog', 'POST me/rewards/{item}/claim',
            'GET me/rewards/history', 'GET me/vip/progress',
            'GET me/security/login-history', 'GET me/security/login-summary',
            'GET me/bank-accounts', 'POST me/bank-accounts',
            'PATCH me/bank-accounts/{id}/default', 'DELETE me/bank-accounts/{id}',
            'GET me/referral-center', 'POST me/referral-center/payout-requests',
            'GET me/dashboard-summary',
        ];

        $actual = [];
        foreach (Route::getRoutes() as $route) {
            $uri = preg_replace('#^api/v1/#', '', $route->uri()) ?? '';
            foreach ($route->methods() as $method) {
                $actual[] = $method.' '.$uri;
            }
        }

        foreach ($expected as $signature) {
            $this->assertContains($signature, $actual, "Missing route: {$signature}");
        }

        // Exactness: nothing else may hide under the Batch 1 prefixes.
        $batch1Prefixes = '#^[A-Z]+ me/(cashback|rewards|vip|security/login|bank-accounts|referral-center|dashboard-summary)#';
        $batch1Actual = array_values(array_unique(array_filter(
            $actual,
            static fn (string $signature): bool => preg_match($batch1Prefixes, $signature) === 1
                && ! str_starts_with($signature, 'HEAD ')
        )));
        sort($batch1Actual);
        $sortedExpected = $expected;
        sort($sortedExpected);

        $this->assertSame($sortedExpected, $batch1Actual);
        $this->assertCount(15, $sortedExpected);
    }
}
