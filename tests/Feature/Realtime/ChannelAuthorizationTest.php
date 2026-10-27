<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

/**
 * routes/channels.php, exercised through the real /broadcasting/auth endpoint.
 *
 * The lane's whole security property is here: a private channel's name is its owner's id,
 * and only that owner - while active - may subscribe. The log broadcaster never answers an
 * auth request (it returns null), so the test registers a verifying broadcaster that runs the
 * framework's own channel matching against the callbacks the routes file defines; the
 * callbacks themselves are the production code.
 */
final class ChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Broadcast::extend('verifying', static fn ($app, array $config): Broadcaster => new class extends Broadcaster
        {
            use UsePusherChannelConventions;

            public function auth($request)
            {
                $name = (string) $request->input('channel_name', '');
                $channel = $this->normalizeChannelName($name);

                if ($name === '' || ($this->isGuardedChannel($name) && ! $this->retrieveUser($request, $channel))) {
                    throw new AccessDeniedHttpException;
                }

                return $this->verifyUserCanAccessChannel($request, $channel);
            }

            public function validAuthenticationResponse($request, $result)
            {
                return ['auth' => 'granted'];
            }

            public function broadcast(array $channels, $event, array $payload = []): void {}
        });

        Config::set('broadcasting.connections.verifying', ['driver' => 'verifying']);
        Config::set('broadcasting.default', 'verifying');
        Broadcast::purge('verifying');

        // The routes file registered its callbacks on the driver that was default at boot;
        // register them again on the verifying driver so the HTTP path reaches them.
        require base_path('routes/channels.php');
    }

    private function authorize(string $channel): TestResponse
    {
        return $this->postJson('/broadcasting/auth', [
            'channel_name' => $channel,
            'socket_id' => '1234.5678',
        ]);
    }

    #[Test]
    public function every_private_channel_the_events_use_has_a_callback(): void
    {
        $defined = array_keys(Broadcast::getChannels()->all());

        foreach (['App.Models.User.{id}', 'user.{id}', 'user.{id}.wallet'] as $pattern) {
            $this->assertContains($pattern, $defined, $pattern.' has no authorization callback');
        }
    }

    #[Test]
    public function the_owner_is_admitted_to_each_of_their_channels(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner);

        foreach (['private-user.'.$owner->id, 'private-user.'.$owner->id.'.wallet', 'private-App.Models.User.'.$owner->id] as $channel) {
            $this->authorize($channel)->assertOk()->assertJson(['auth' => 'granted']);
        }
    }

    #[Test]
    public function another_player_is_refused_every_channel_that_is_not_theirs(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($other);

        foreach (['private-user.'.$owner->id, 'private-user.'.$owner->id.'.wallet', 'private-App.Models.User.'.$owner->id] as $channel) {
            $this->authorize($channel)->assertForbidden();
        }
    }

    #[Test]
    public function a_guest_never_reaches_a_callback(): void
    {
        $owner = User::factory()->create();

        $response = $this->authorize('private-user.'.$owner->id);

        $this->assertContains($response->getStatusCode(), [401, 403], 'a guest must be refused, not served');
    }

    #[Test]
    public function a_suspended_owner_loses_the_socket_with_the_api(): void
    {
        $owner = User::factory()->suspended()->create();

        $this->actingAs($owner);

        $this->authorize('private-user.'.$owner->id)->assertForbidden();
        $this->authorize('private-user.'.$owner->id.'.wallet')->assertForbidden();
    }

    #[Test]
    public function a_malformed_id_is_a_refusal_not_an_exception(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner);

        $this->authorize('private-user.abc')->assertForbidden();
        $this->authorize('private-user.'.$owner->id.'1')->assertForbidden();

        // The callbacks themselves answer plain booleans for any input.
        foreach (Broadcast::getChannels()->all() as $pattern => $callback) {
            $this->assertFalse($callback($owner, 'not-a-number'), $pattern.' must refuse a non-numeric id');
            $this->assertFalse($callback($owner, (string) ($owner->id + 1000)), $pattern.' must refuse a foreign id');
            $this->assertTrue($callback($owner, (string) $owner->id), $pattern.' must admit the owner');
        }
    }
}
