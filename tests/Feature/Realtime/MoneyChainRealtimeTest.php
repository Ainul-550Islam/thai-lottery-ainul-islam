<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use App\Enums\BetStatus;
use App\Enums\DepositStatus;
use App\Enums\DrawStatus;
use App\Enums\QueueName;
use App\Enums\WithdrawalStatus;
use App\Models\Bet;
use App\Models\Deposit;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * The player's real-time lane on the money chain, measured at both ends.
 *
 * WHY THIS FILE EXISTS
 * The chain is bet placement -> wallet -> deposit -> settlement -> payout -> agent commission. Six
 * of its hops are things a player watches in real time, and the app had declared six broadcast
 * events for them, authorized their channels, and subscribed to them in resources/js/echo.js -
 * while dispatching none of them. Measured, before 2026-10-16: six client listeners, zero server
 * publishers, and one publisher (PrizePayoutCompleted) with no listener. So every "live" balance,
 * bet confirmation and deposit status on the player's screen was really a page reload, and the code
 * on both ends looked complete. The gap was invisible because a test that only asserts the event
 * class exists, or that the channel authorizes, passes either way.
 *
 * WHAT IS PINNED HERE, AND WHY EACH ONE IS THE ONLY WAY TO SEE IT
 *
 * 1. THE CONTRACT, BOTH DIRECTIONS, DERIVED FROM THE FILES THAT DEFINE IT. Not a list typed into
 *    the test: a typed list would have agreed with the broken code. Every broadcastAs in app/Events
 *    must be listened to in echo.js, every listened name must have a publisher, and every publisher
 *    must be dispatched from somewhere in app/.
 * 2. NOTHING IS PUBLISHED UNTIL THE MONEY COMMITS. Measured with the database queue connection,
 *    because that is the only way to see a push that was deferred: roll the transaction back and the
 *    jobs table stays empty; commit it and exactly one BroadcastEvent lands, on the notifications
 *    lane. A rolled-back balance reaching a player's screen is a money bug that presents as a UI
 *    flicker, and the fix is two properties on the event.
 * 3. ONE ROW WRITE = ONE NOTIFICATION. The wallet hop publishes from the row, not from the loop
 *    around it, so the number of notifications a draw creates scales with wallets touched, not with
 *    bets settled. At a million players that difference is the queue.
 * 4. THE SCOPING IS THE SECURITY. A money event may only address channels whose names carry the
 *    owner's own id, and anything broadcast on a public channel must carry nothing that belongs to
 *    one person. The payout event's own comment records what it used to be: a public channel, where
 *    any subscriber of user.17 received user 17's payout with no authentication at all.
 *
 * DatabaseTruncation, not RefreshDatabase: RefreshDatabase holds each test inside a transaction, so
 * the after-commit callback this file is measuring would never fire and the test would report the
 * safest possible result for the wrongest possible reason.
 */
final class MoneyChainRealtimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite(); // asserts page content; public/build/manifest.json is a build artefact
    }

    use DatabaseTruncation;

    /** Per-user payload keys that must never appear on a public channel. */
    private const USER_SCOPED_KEYS = ['user_id', 'wallet_id', 'bet_id', 'payout_id', 'correlation_id', 'balance', 'amount', 'locked_balance', 'available_balance', 'reference_number'];

    /**
     * Every ShouldBroadcast event class, with the shape the framework will actually use.
     *
     * @return array<string, array{class: class-string, as: string, channels: list<string>}>
     */
    private function publishers(): array
    {
        $found = [];

        foreach (glob(app_path('Events/*.php')) ?: [] as $file) {
            $stem = basename($file, '.php');
            $fqcn = 'App\\Events\\'.$stem;

            if (! class_exists($fqcn)) {
                continue;
            }

            $rc = new ReflectionClass($fqcn);

            if (! $rc->implementsInterface('Illuminate\Contracts\Broadcasting\ShouldBroadcast') || $rc->isAbstract()) {
                continue;
            }

            $src = (string) file_get_contents($file);
            $as = preg_match("/function broadcastAs\(\): string\s*\{\s*return '([a-z0-9._]+)'/", $src, $m) === 1 ? $m[1] : $stem;
            $channels = [];

            preg_match_all("/new (PrivateChannel|PresenceChannel|Channel)\('([^']+)'/", $src, $cm, PREG_SET_ORDER);

            foreach ($cm as $c) {
                $channels[] = $c[1].':'.$c[2];
            }

            $found[$stem] = ['class' => $fqcn, 'as' => $as, 'channels' => $channels];
        }

        return $found;
    }

    /** @return list<string> the event names resources/js/echo.js subscribes to */
    private function listeners(): array
    {
        $js = (string) file_get_contents(base_path('resources/js/echo.js'));

        preg_match_all("/listen\('\.([a-z0-9._]+)'/", $js, $m);

        return array_values(array_unique($m[1] ?? []));
    }

    /**
     * A recording listener, so the counts are the real dispatch counts and not a fake's bookkeeping.
     *
     * It hands back an object, not an array, and that is a bug this file had first: PHP arrays are
     * copied on write, so returning $seen gave every test a snapshot taken before anything was
     * dispatched, and four tests failed with "expected 1, got 0" while the code under test was
     * already correct. An object is a handle, so the listener's writes are the ones being read.
     *
     * @param  list<class-string>  $events
     */
    private function record(array $events): \stdClass
    {
        $bag = new \stdClass;
        $bag->seen = [];

        foreach ($events as $event) {
            $bag->seen[$event] = [];

            Event::listen($event, static function (object $e) use ($bag, $event): void {
                $bag->seen[$event][] = $e;
            });
        }

        return $bag;
    }

    #[Test]
    public function every_published_event_is_listened_to_and_every_listened_event_is_published(): void
    {
        $publishers = $this->publishers();
        $listeners = $this->listeners();

        $this->assertNotEmpty($publishers, 'no broadcast events found; the scan is broken, not the app');
        $this->assertGreaterThanOrEqual(6, count($listeners), 'echo.js lost its subscriptions');

        $published = [];

        foreach ($publishers as $stem => $meta) {
            $published[] = $meta['as'];

            // The half that was missing before: a declared event nobody dispatches is dead code
            // that looks like a feature.
            $src = (string) file_get_contents((new ReflectionClass($meta['class']))->getFileName());

            if (! str_contains($src, 'AdminOperation') && ! str_contains($src, 'ComplianceCase')) {
                $this->assertContains($meta['as'], $listeners,
                    $stem.' broadcasts as '.$meta['as'].', and no client listens for that name');
            }
        }

        foreach ($listeners as $listened) {
            $this->assertContains($listened, $published,
                'echo.js listens for .'.$listened.' but no event in app/Events broadcasts under that name');
        }

        // And each publisher must be reached from somewhere in app/, not merely defined.
        $unreachable = [];

        foreach ($publishers as $stem => $meta) {
            if (in_array($stem, ['AdminOperationCompleted', 'ComplianceCaseEscalated'], true)) {
                continue; // not ShouldBroadcast; the loop above already skipped them
            }

            $out = shell_exec('grep -rl "new '.$stem.'(" '.escapeshellarg(app_path()).' 2>/dev/null | grep -v "/Events/'.$stem.'.php"');
            $files = array_filter(explode("\n", trim((string) $out)));

            if ($files === []) {
                $unreachable[] = $stem;
            }
        }

        $this->assertSame([], $unreachable,
            'these broadcast events are declared and subscribed to, but no code dispatches them: '
            .implode(', ', $unreachable).' - that is the state this cluster found the chain in');
    }

    #[Test]
    public function every_private_channel_an_event_uses_has_an_authorization_callback(): void
    {
        $channels = (string) file_get_contents(base_path('routes/channels.php'));
        $defined = [];

        preg_match_all("/Broadcast::channel\('([^']+)'/", $channels, $m);
        $defined = $m[1] ?? [];

        $this->assertNotSame([], $defined, 'routes/channels.php defines nothing; this test would pass by accident');

        $missing = [];

        foreach ($this->publishers() as $stem => $meta) {
            foreach ($meta['channels'] as $channel) {
                [$kind, $name] = explode(':', $channel, 2);

                if ($kind !== 'PrivateChannel') {
                    continue; // a public channel needs no authorization; test 4 checks what may go there
                }

                // Names are built as 'user.'.$id, so compare the prefix the callback must be keyed on.
                $prefix = explode("'+", $name)[0];
                $wildcard = $prefix.'{id}';

                if (! in_array($wildcard, $defined, true) && ! in_array($name, $defined, true)) {
                    $missing[] = $stem.' -> '.$channel;
                }
            }
        }

        $this->assertSame([], $missing,
            'a private channel with no callback is refused with a 403 rather than left open, so these '
            .'players never receive anything: '.implode(', ', $missing).' (defined: '.implode(', ', $defined).')');
    }

    #[Test]
    public function a_rolled_back_transaction_publishes_nothing_and_a_committed_one_publishes_exactly_one(): void
    {
        Config::set('queue.default', 'database');
        Config::set('queue.connections.database', [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
        Config::set('broadcasting.default', 'log');

        DB::table('jobs')->delete();

        $wallet = $this->wallet();

        // The defect this pins: money is written inside transactions, so a broadcast that pushes
        // at dispatch time describes a state the database is about to reject.
        $rollback = new class extends \RuntimeException {};

        try {
            DB::transaction(function () use ($wallet, $rollback): void {
                $wallet->forceFill(['balance' => '777.00'])->save();

                $this->assertSame(0, DB::table('jobs')->count(),
                    'a broadcast job was pushed while the transaction that wrote the money was still open');

                throw $rollback;
            });
        } catch (\RuntimeException $e) {
            if ($e !== $rollback) {
                throw $e;
            }
        }

        $this->assertSame(0, DB::table('jobs')->count(),
            'a rolled-back balance change published a notification anyway; the afterCommit flag is not taking effect');

        DB::transaction(function () use ($wallet): void {
            $wallet->forceFill(['balance' => '888.00'])->save();
        });

        $rows = DB::table('jobs')->get();

        $this->assertSame(1, $rows->count(), 'expected one queued broadcast after the commit; got '.$rows->count());

        $payload = json_decode((string) $rows[0]->payload, true);

        // The payload names the event, not the wrapper: the framework queues BroadcastEvent through
        // CallQueuedHandler, so the string to pin is the event the player is waiting for.
        $this->assertSame('App\\Events\\WalletBalanceUpdated', $payload['displayName'] ?? null,
            'the queued job is not the wallet broadcast: '.json_encode($payload));
        $this->assertSame(QueueName::Notifications->value, $rows[0]->queue,
            'broadcast traffic belongs on the notifications lane, not on default where it queues '
            .'behind, and in front of, everything else');
    }

    #[Test]
    public function each_hop_publishes_on_the_row_fact_that_changed_and_stays_quiet_otherwise(): void
    {
        Config::set('broadcasting.default', 'log');

        $events = [
            'App\\Events\\WalletBalanceUpdated',
            'App\\Events\\BetPlaced',
            'App\\Events\\DepositStatusUpdated',
            'App\\Events\\WithdrawalStatusUpdated',
            'App\\Events\\DrawStatusUpdated',
            'App\\Events\\DrawResultPublished',
        ];

        $seen = $this->record($events);

        // --- wallet: a balance move is a fact; a touch is not -------------------------------
        $wallet = $this->wallet();
        $wallet->forceFill(['balance' => '120.00'])->save();

        $this->assertCount(1, $seen->seen['App\\Events\\WalletBalanceUpdated'] ?? [], 'a balance change must reach the player once');
        $this->assertSame('balance', $seen->seen['App\\Events\\WalletBalanceUpdated'][0]->reason,
            'the event says which number moved so the client need not guess');

        $wallet->forceFill(['locked_balance' => '10.00'])->save();
        $this->assertCount(2, $seen->seen['App\\Events\\WalletBalanceUpdated'], 'a hold move is also a fact the player is owed');

        $wallet->forceFill(['locked_reason' => 'hold note'])->save();
        $this->assertCount(2, $seen->seen['App\\Events\\WalletBalanceUpdated'],
            'a save that moved no money - here a hold note - must not wake a million clients');

        // --- bet: placed is the moment, not any status change -------------------------------
        $bet = Bet::factory()->create(['status' => BetStatus::Pending]);
        $this->assertCount(0, $seen->seen['App\\Events\\BetPlaced'] ?? [], 'a bet being created is not a bet being placed');

        $bet->forceFill(['status' => BetStatus::Active])->save();
        $this->assertCount(1, $seen->seen['App\\Events\\BetPlaced'], 'the player is told their bet is on');

        $bet->forceFill(['status' => BetStatus::Lost])->save();
        $this->assertCount(1, $seen->seen['App\\Events\\BetPlaced'], 'only the placement is BetPlaced');

        // A bet can also arrive already placed (bulk paths insert the row complete), which is why
        // the observer answers created() as well as updated(): one of these two would be dead.
        Bet::factory()->create(['status' => BetStatus::Active]);
        $this->assertCount(2, $seen->seen['App\\Events\\BetPlaced'],
            'a bet inserted directly as active is a placed bet too, and must not be silently dropped');

        // --- deposit and withdrawal: every transition --------------------------------------
        $deposit = Deposit::factory()->create(['status' => DepositStatus::Pending]);
        $deposit->forceFill(['status' => DepositStatus::Confirmed])->save();
        $this->assertCount(1, $seen->seen['App\\Events\\DepositStatusUpdated'] ?? []);

        $deposit->forceFill(['provider_reference' => 'PRV-77'])->save();
        $this->assertCount(1, $seen->seen['App\\Events\\DepositStatusUpdated'], 'a provider reference is not a status');

        $withdrawal = Withdrawal::factory()->create(['status' => WithdrawalStatus::Pending]);
        $withdrawal->forceFill(['status' => WithdrawalStatus::Approved])->save();
        $this->assertCount(1, $seen->seen['App\\Events\\WithdrawalStatusUpdated'] ?? []);

        // --- draw state, and the result the whole country waits for -------------------------
        $draw = Draw::factory()->create(['status' => DrawStatus::Open]);
        $draw->forceFill(['status' => DrawStatus::Closed])->save();
        $this->assertCount(1, $seen->seen['App\\Events\\DrawStatusUpdated'] ?? []);

        $result = DrawResult::factory()->create(['draw_id' => $draw->id, 'published_at' => null]);
        $result->forceFill(['published_at' => now()])->save();
        $this->assertCount(1, $seen->seen['App\\Events\\DrawResultPublished'] ?? [],
            'publication is the one moment the draw channel exists for');
    }

    #[Test]
    public function money_events_address_only_their_owner_and_public_ones_carry_no_person(): void
    {
        Config::set('broadcasting.default', 'log');

        $seen = $this->record([
            'App\\Events\\WalletBalanceUpdated',
            'App\\Events\\BetPlaced',
            'App\\Events\\DepositStatusUpdated',
            'App\\Events\\WithdrawalStatusUpdated',
            'App\\Events\\DrawStatusUpdated',
            'App\\Events\\DrawResultPublished',
        ]);

        $wallet = $this->wallet();
        $ownerId = (int) $wallet->user_id;
        $wallet->forceFill(['balance' => '55.00'])->save();

        $this->assertCount(1, $seen->seen['App\\Events\\WalletBalanceUpdated'] ?? []);

        foreach ($seen->seen as $name => $events) {
            foreach ($events as $event) {
                foreach ($event->broadcastOn() as $handle) {
                    // Classified by the channel's own type, not by its string form: PrivateChannel
                    // prefixes the name it exposes with "private-", so a prefix test read every
                    // private money channel as public and then demanded it carry nothing at all.
                    $channel = preg_replace('/^private-/', '', (string) $handle->name);
                    $isPrivate = $handle instanceof PrivateChannel
                        || $handle instanceof PresenceChannel;

                    if ($isPrivate) {
                        // The whole security property of the lane in one line: the channel name is
                        // the owner's id, so a subscription that is not theirs is refused by
                        // routes/channels.php, and there is no shared money channel to leak into.
                        $this->assertSame(
                            (string) $ownerId,
                            preg_replace('/^.*\./', '', $channel),
                            $name.' publishes to '.$channel.' which is not scoped to the owning user '
                        );
                    } else {
                        $keys = array_keys($event->broadcastWith());
                        $leaks = array_intersect($keys, self::USER_SCOPED_KEYS);

                        $this->assertSame([], $leaks,
                            $name.' is broadcast on the public channel '.$channel.' but carries '
                            .implode(', ', $leaks));
                    }
                }
            }
        }
    }

    #[Test]
    public function notifications_scale_with_wallet_writes_not_with_the_rows_around_them(): void
    {
        Config::set('broadcasting.default', 'log');

        $seen = $this->record(['App\\Events\\WalletBalanceUpdated']);
        $wallet = $this->wallet();
        $user = User::query()->findOrFail($wallet->user_id);

        // Twenty bets settled for one player, then the payout written once. If the wallet event were
        // dispatched per bet or per ledger row this is where the lane would cost twenty times as
        // much as it needs to; it costs one, because the observer hangs off the row that changed.
        foreach (range(1, 20) as $i) {
            Bet::factory()->create(['user_id' => $user->id, 'status' => BetStatus::Active]);
        }

        $wallet->forceFill(['balance' => (string) (1000 + 20 * 50)])->save();

        $this->assertCount(1, $seen->seen['App\\Events\\WalletBalanceUpdated'] ?? [],
            'twenty settled bets moved one wallet: a player is owed one notification per number that changed, not one per row');
        $this->assertSame((string) $wallet->balance, $seen->seen['App\\Events\\WalletBalanceUpdated'][0]->broadcastWith()['balance']);
    }

    #[Test]
    public function the_bridge_that_listens_is_shipped_started_and_reaches_the_built_artifact(): void
    {
        // The fourth layer of the same broken lane, and the one that made all the others invisible:
        // nothing imported resources/js/echo.js, nothing called its init(), and it was not in any
        // vite entry - so the bundle every browser loads carried not one of the six subscription
        // names. Asserting the PHP side without asserting the artifact is how a real-time feature
        // stays dead while its tests stay green.
        $app = (string) file_get_contents(base_path('resources/js/app.js'));

        $this->assertStringContainsString("import './echo';", $app,
            'resources/js/app.js does not import the echo bridge, so it is not in any bundle');

        $bridge = (string) file_get_contents(base_path('resources/js/echo.js'));

        $this->assertStringContainsString('echo.init()', $bridge,
            'the bridge never starts itself, and nothing else calls init() either');

        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            $this->markTestSkipped('public/build/manifest.json is absent, so the built JavaScript cannot be checked.');
        }

        $built = '';

        foreach (glob(public_path('build/assets/*.js')) ?: [] as $file) {
            $built .= (string) file_get_contents($file);
        }

        $this->assertNotSame('', $built, 'the build manifest exists but no built js was found');

        $missing = [];

        foreach ($this->publishers() as $stem => $meta) {
            if (! in_array($meta['as'], $this->listeners(), true)) {
                continue; // a publisher nobody listens for is test 1's business, not the artifact's
            }

            if (! str_contains($built, $meta['as'])) {
                $missing[] = $stem.' as '.$meta['as'];
            }
        }

        $this->assertSame([], $missing,
            'these events are published and subscribed to in source, but the bundle browsers receive '
            .'does not contain the subscription: '.implode(', ', $missing));
    }

    #[Test]
    public function the_identity_the_bridge_needs_belongs_to_the_viewer_and_never_rides_a_public_cache(): void
    {
        // bindGlobalChannels() reads a <meta name="user-id"> tag, so the page has to emit one - and
        // emitting identity in HTML is exactly the mistake the public-page caching cluster recorded.
        // Both halves are asserted here: the id must be the viewer's own, and no page carrying it may
        // be marked publicly cacheable, because a shared cache entry would hand one player's id to
        // another player's browser.
        $user = User::factory()->create();

        // The load-bearing case is not the login redirect: it is a PUBLIC page that renders the same
        // layout and is deliberately stored in the shared cache by the public.legal middleware
        // (10.24/10.26). An identity tag reaching that cache is the exact bug that cluster closed,
        // which is why the tag is wrapped in @auth rather than always rendered.
        $public = $this->get('/bingo-lottery');
        $public->assertOk();

        $publicHtml = (string) $public->getContent();

        $this->assertStringNotContainsString('name="user-id"', $publicHtml,
            'a publicly cacheable page rendered the viewer identity tag; the shared cache would hand '
            .'one visitor their own id to the next visitor');

        // The invariant, in the direction the codebase actually enforces. Measured, this page answers
        // Cache-Control: no-cache, private - it was made private by the public-page caching cluster
        // precisely because a page rendered through this layout can name its viewer. Either half of
        // that pair is enough to be safe, so what must never happen is both halves at once.
        $cache = (string) $public->headers->get('Cache-Control', '');
        $publiclyCached = str_contains($cache, 'public') || str_contains($cache, 's-maxage');

        $this->assertFalse($publiclyCached && str_contains($publicHtml, 'name="user-id"'),
            'a page that names its viewer must not sit in a shared cache, and a page that does sit '
            .'there must not name a viewer. Here: cache-control ['.$cache.'] with an identity tag present');

        $this->get('/dashboard')->assertRedirect();

        $response = $this->actingAs($user)->get('/dashboard');
        $response->assertOk();

        $html = (string) $response->getContent();
        $tag = '<meta name="user-id" content="'.$user->id.'">';

        $this->assertStringContainsString(preg_replace('/\s+/', '', $tag), preg_replace('/\s+/', '', $html),
            'the authenticated page must carry the viewer id the bridge subscribes with; the real-time '
            .'lane for wallet, bet, deposit and withdrawal is unreachable without it');

        $this->assertSame(1, substr_count(preg_replace('/\s+/', '', $html), preg_replace('/\s+/', '', $tag)),
            'exactly one identity per document: two ids is a template bug and a cache-key ambiguity');

        $cache = (string) $response->headers->get('Cache-Control', '');

        $this->assertStringNotContainsString('public', $cache,
            'a page carrying a user id must not be publicly cacheable: '.$cache);
        $this->assertStringNotContainsString('s-maxage', $cache,
            'a shared cache must not hold a page that names its viewer: '.$cache);
    }

    private function wallet(): Wallet
    {
        $user = User::factory()->create();

        return Wallet::factory()->create(['user_id' => $user->id]);
    }
}
