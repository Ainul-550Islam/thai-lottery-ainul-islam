<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The browser half of the lane: the bridge is a real Echo client, it is in the bundle, the
 * pages give it exactly the facts it needs (and no secret), and every subscription it makes
 * matches a name the server publishes.
 */
final class EchoClientContractTest extends TestCase
{
    use RefreshDatabase;

    private function bridge(): string
    {
        return (string) file_get_contents(base_path('resources/js/echo.js'));
    }

    #[Test]
    public function the_bridge_is_a_real_echo_client_and_not_a_mock_bus(): void
    {
        $js = $this->bridge();

        $this->assertStringContainsString("import Echo from 'laravel-echo';", $js);
        $this->assertStringContainsString("import Pusher from 'pusher-js';", $js);
        $this->assertStringContainsString("broadcaster: 'reverb'", $js);
        $this->assertStringContainsString('echo.init()', $js, 'the bridge must start itself');
        $this->assertStringContainsString("meta('realtime-enabled')", $js, 'the switch is read from the page');
        $this->assertStringContainsString("meta('user-id')", $js, 'the viewer id is read from the page, never guessed');
        $this->assertStringContainsString('isLive = false', $js);
        $this->assertStringContainsString("'pagehide'", $js, 'a hidden tab must drop its socket');
        $this->assertStringContainsString('scheduleReconnect', $js, 'reconnects follow a backoff, not a tight loop');
        $this->assertStringContainsString('Math.pow(2, exponent)', $js);
        $this->assertStringNotContainsString('createChannelProxy', $js, 'the fake event bus is gone');
        $this->assertStringNotContainsString('Mock/Fallback', $js);
    }

    #[Test]
    public function the_bridge_subscribes_to_every_name_the_server_publishes_and_only_those(): void
    {
        preg_match_all("/listen\\('\\.([a-z0-9._]+)'/", $this->bridge(), $m);
        $listened = array_values(array_unique($m[1]));
        sort($listened);

        $this->assertSame([
            'bet.placed',
            'deposit.status.updated',
            'draw.result.published',
            'draw.settlement.completed',
            'draw.status.updated',
            'payout.completed',
            'wallet.balance.updated',
            'withdrawal.status.updated',
        ], $listened);

        $js = $this->bridge();

        $this->assertMatchesRegularExpression('/private\(`user\.\$\{userId\}`\)/', $js, 'money events are read from the private user channel');
        $this->assertStringContainsString("channel('draws')", $js);
        $this->assertStringContainsString("channel('draws.live')", $js);

        // Every name is re-emitted as a DOM event other modules already consume.
        foreach (['wallet:balance-changed', 'lottery:results-updated', 'lottery:wallet-updated', 'lottery:bet-placed', 'lottery:withdrawal-updated', 'lottery:deposit-updated', 'lottery:payout-completed', 'lottery:draw-result-published', 'lottery:draw-status-updated', 'lottery:draw-settlement-completed'] as $domEvent) {
            $this->assertTrue(
                str_contains($js, "'".$domEvent."'") || str_contains($js, '`${DOM_EVENT_PREFIX}'.substr($domEvent, strlen('lottery:')).'`'),
                'the bridge does not dispatch '.$domEvent,
            );
        }
    }

    #[Test]
    public function the_bridge_is_imported_by_the_app_entry_and_its_packages_are_declared_and_locked(): void
    {
        $this->assertStringContainsString("import './echo';", (string) file_get_contents(base_path('resources/js/app.js')));

        $package = json_decode((string) file_get_contents(base_path('package.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('laravel-echo', $package['dependencies']);
        $this->assertArrayHasKey('pusher-js', $package['dependencies']);

        $lock = json_decode((string) file_get_contents(base_path('package-lock.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('node_modules/laravel-echo', $lock['packages']);
        $this->assertArrayHasKey('node_modules/pusher-js', $lock['packages']);
        $this->assertArrayHasKey('laravel-echo', $lock['packages']['']['dependencies']);

        $vite = (string) file_get_contents(base_path('vite.config.js'));
        $this->assertStringContainsString('resources/js/app.js', $vite, 'app.js must be a Vite entry or the bridge never reaches a browser');
    }

    #[Test]
    public function a_guest_page_names_no_viewer_but_still_states_whether_realtime_is_on(): void
    {
        $response = $this->get('/bingo-lottery');
        $response->assertOk();

        $html = (string) $response->getContent();

        $this->assertStringNotContainsString('name="user-id"', $html);
        $this->assertStringContainsString('<meta name="realtime-enabled" content="0">', $html);
    }

    #[Test]
    public function an_authenticated_page_emits_exactly_one_viewer_id_and_the_public_client_block_without_the_secret(): void
    {
        $user = User::factory()->create();

        Config::set('realtime.enabled', true);
        Config::set('realtime.client.key', 'public-app-key');
        Config::set('realtime.client.host', 'ws.example.com');
        Config::set('realtime.client.port', 443);
        Config::set('realtime.client.scheme', 'https');
        Config::set('broadcasting.connections.reverb.secret', 'the-server-secret');

        $response = $this->actingAs($user)->get('/dashboard');
        $response->assertOk();

        $html = (string) $response->getContent();

        $this->assertSame(1, substr_count($html, '<meta name="user-id" content="'.$user->id.'">'));
        $this->assertStringContainsString('<meta name="realtime-enabled" content="1">', $html);
        $this->assertStringContainsString('<meta name="realtime-key" content="public-app-key">', $html);
        $this->assertStringContainsString('<meta name="realtime-host" content="ws.example.com">', $html);
        $this->assertStringContainsString('<meta name="realtime-auth-endpoint" content="/broadcasting/auth">', $html);
        $this->assertStringNotContainsString('the-server-secret', $html);

        $cache = (string) $response->headers->get('Cache-Control', '');
        $this->assertStringNotContainsString('public', $cache);
        $this->assertStringNotContainsString('s-maxage', $cache);
    }

    #[Test]
    public function with_realtime_off_the_authenticated_page_emits_no_client_block(): void
    {
        $user = User::factory()->create();

        Config::set('realtime.enabled', false);

        $html = (string) $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="realtime-enabled" content="0">', $html);
        $this->assertStringNotContainsString('name="realtime-key"', $html);
        $this->assertSame(1, substr_count($html, 'name="user-id"'));
    }
}
