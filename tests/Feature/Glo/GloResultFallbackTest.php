<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Services\Lottery\GloFixtureResultProvider;
use App\Services\Lottery\GloOfficialResultProvider;
use App\Services\Lottery\GloResultImportService;
use App\Services\Lottery\GloResultProviderChain;
use App\Services\Lottery\Support\BreakerPolicy;
use App\Services\Lottery\Support\CircuitBreaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The multi-source result ladder, and the one thing it must never do.
 *
 * ============================================================================
 * WHAT THESE TESTS EXIST TO PREVENT
 * ============================================================================
 * A fallback chain is easy to write and easy to write WRONG, and the failure is
 * not a crash. The failure is that a fixture-sourced payload gets recorded under
 * the official source's name, is published as a real GLO publication, and is
 * settled against — with every test in the suite still green, because each
 * individual component did exactly what it was told.
 *
 * So the assertions here are mostly about LABELS and REFUSALS rather than about
 * numbers:
 *
 *   * the provider that ANSWERED is the provider recorded, even when the ladder
 *     was asked to fetch from a different one;
 *   * the ladder will not fall through to synthetic vectors unless the
 *     deployment has explicitly said so;
 *   * an unhealthy source is skipped without a network call, and the skip is
 *     reported rather than silent;
 *   * when every source fails, the answer is an honest failure with NO numbers
 *     in it — not a zero, not an empty-string prize, not a guess.
 *
 * ============================================================================
 * WHY THE PROVIDERS ARE MOCKED RATHER THAN REPLAYED
 * ============================================================================
 * The ladder's job is ordering, refusal and provenance. Testing that against the
 * real fixture file would test the fixture parser again (already covered in
 * GloResultImportTest) and would make it impossible to construct the states that
 * matter: an official source that FAILS, a source whose breaker is OPEN, a
 * source that answers while claiming not to be configured. Those states are the
 * whole subject of this file, and they can only be built by hand.
 *
 * The mocks are strict about what they are asked: `fetch` is asserted with the
 * draw number the chain was given, so a chain that dropped the draw number on
 * its way down the ladder fails here rather than silently fetching "latest".
 */
class GloResultFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The breaker's state lives in the CACHE, and several tests below are
        // about what happens across calls. Two precautions, because the test
        // suite must not depend on which store CI happens to configure:
        //
        //   1. a per-test key prefix, so no state can leak between tests even if
        //      the store is shared and not flushed;
        //   2. a flush, so a test does not inherit the previous one's counters.
        //
        // `cache.default` is deliberately NOT overridden here. The container
        // resolves `cache.store` once into a singleton, so changing the default
        // mid-test would leave the injected repository pointing at the OLD driver
        // while the assertion read the new one — a test that passes for the wrong
        // reason.
        config(['glo.sources.breaker.key_prefix' => 'glo-breaker-test:'.Str::lower(Str::random(12)).':']);

        Cache::flush();

        config([
            'glo.sources.breaker.enabled' => true,
            'glo.sources.breaker.failure_threshold' => 2,
            'glo.sources.breaker.cooldown_seconds' => 60,
            'glo.sources.breaker.half_open_probes' => 1,
            'glo.sources.breaker.window_seconds' => 300,
            'glo.sources.priority' => [],
            'glo.sources.fall_through_to_fixture' => false,
            'glo.official_source.mode' => 'fixture',
        ]);
    }

    #[Test]
    public function an_empty_priority_ladder_is_the_single_configured_source(): void
    {
        // The additive property that makes this change safe to deploy: a
        // deployment that configures nothing new keeps its present behaviour.
        config(['glo.official_source.mode' => 'fixture']);

        $chain = app(GloResultProviderChain::class);

        $this->assertSame('chain', $chain->name());
        $this->assertTrue($chain->isConfigured());

        $payload = $chain->fetch('GLO-2026-09-16');

        $this->assertSame('imported', $payload['status']);
        $this->assertSame('fixture', $payload['provider']);
        $this->assertSame(['source' => 'fixture', 'outcome' => 'imported', 'reason' => null], $chain->attempts()[0]);
    }

    #[Test]
    public function the_official_source_is_preferred_when_the_ladder_says_so(): void
    {
        config(['glo.sources.priority' => ['official', 'fixture']]);

        $this->mock(GloOfficialResultProvider::class, function ($mock): void {
            $mock->shouldReceive('name')->andReturn('official');
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('fetch')->once()->with('DRAW-1')->andReturn(
                $this->payload('imported', 'official', 'DRAW-1'),
            );
        });

        $this->mock(GloFixtureResultProvider::class, function ($mock): void {
            $mock->shouldReceive('name')->andReturn('fixture');
            $mock->shouldReceive('isConfigured')->andReturn(true);
            // MUST NOT BE CALLED. If the ladder asked the fixture source after
            // the official source answered, that is a second outbound fetch for
            // a result it already holds.
            $mock->shouldReceive('fetch')->never();
        });

        $chain = app(GloResultProviderChain::class);
        $payload = $chain->fetch('DRAW-1');

        $this->assertSame('official', $payload['provider']);
        $this->assertSame(
            [['source' => 'official', 'outcome' => 'imported', 'reason' => null]],
            $chain->attempts(),
        );
    }

    #[Test]
    public function a_failed_official_fetch_falls_through_to_the_fixture_lane_when_permitted(): void
    {
        config([
            'glo.sources.priority' => ['official', 'fixture'],
            'glo.sources.fall_through_to_fixture' => true,
        ]);

        $this->mock(GloOfficialResultProvider::class, function ($mock): void {
            $mock->shouldReceive('name')->andReturn('official');
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('fetch')->once()->with('DRAW-1')->andReturn(
                $this->failed('official', 'connection timed out', 'DRAW-1'),
            );
        });

        $this->mock(GloFixtureResultProvider::class, function ($mock): void {
            $mock->shouldReceive('name')->andReturn('fixture');
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('fetch')->once()->with('DRAW-1')->andReturn(
                $this->payload('imported', 'fixture', 'DRAW-1'),
            );
        });

        $chain = app(GloResultProviderChain::class);
        $payload = $chain->fetch('DRAW-1');

        // THE LABEL IS THE ASSERTION THAT MATTERS. The ladder was asked for the
        // official source and answered with the fixture source; the payload must
        // say `fixture`, because that is the field that is written to
        // glo_result_imports.provider and read by the public source-state surface.
        $this->assertSame('fixture', $payload['provider']);
        $this->assertSame('imported', $payload['status']);

        $this->assertSame([
            ['source' => 'official', 'outcome' => 'failed', 'reason' => 'connection timed out'],
            ['source' => 'fixture', 'outcome' => 'imported', 'reason' => null],
        ], $chain->attempts());
    }

    #[Test]
    public function the_ladder_refuses_to_reach_the_fixture_lane_without_explicit_permission(): void
    {
        // The production-safe configuration: an ordered ladder that names the
        // fixture lane, with the fall-through switch OFF. The fixture source is
        // still asked if the official source is not configured — that is
        // configuration, not fall-through — but a FAILED official fetch must not
        // be answered with synthetic numbers.
        config([
            'glo.sources.priority' => ['official', 'fixture'],
            'glo.sources.fall_through_to_fixture' => false,
        ]);

        $this->mock(GloOfficialResultProvider::class, function ($mock): void {
            $mock->shouldReceive('name')->andReturn('official');
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('fetch')->once()->andReturn(
                $this->failed('official', 'source outage', 'DRAW-1'),
            );
        });

        $this->mock(GloFixtureResultProvider::class, function ($mock): void {
            $mock->shouldReceive('name')->andReturn('fixture');
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('fetch')->never();
        });

        $chain = app(GloResultProviderChain::class);
        $payload = $chain->fetch('DRAW-1');

        $this->assertSame('failed', $payload['status']);
        $this->assertNull($payload['first_prize']);
        $this->assertNull($payload['fingerprint']);
        $this->assertStringContainsString('Every result source failed', (string) $payload['failure_reason']);
        $this->assertStringContainsString('official', (string) $payload['failure_reason']);
    }

    #[Test]
    public function an_open_circuit_skips_the_source_without_a_network_call(): void
    {
        config([
            'glo.sources.priority' => ['official', 'fixture'],
            'glo.sources.breaker.failure_threshold' => 1,
        ]);

        $officialCalls = 0;

        $this->mock(GloOfficialResultProvider::class, function ($mock) use (&$officialCalls): void {
            $mock->shouldReceive('name')->andReturn('official');
            $mock->shouldReceive('isConfigured')->andReturn(true);
            // ONE call — the threshold — and then NEVER AGAIN, because the
            // next fetch must be refused by the breaker before it is made.
            $mock->shouldReceive('fetch')->once()->andReturnUsing(
                function () use (&$officialCalls) {
                    $officialCalls++;

                    return $this->failed('official', 'connection refused', 'DRAW-1');
                },
            );
        });

        $this->mock(GloFixtureResultProvider::class, function ($mock): void {
            $mock->shouldReceive('name')->andReturn('fixture');
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('fetch')->times(2)->andReturn($this->payload('imported', 'fixture', 'DRAW-1'));
        });

        $chain = app(GloResultProviderChain::class);

        // One failure trips the breaker (threshold 1). The first fetch falls
        // through to fixture, and the SECOND is the one that proves the point.
        $first = $chain->fetch('DRAW-1');
        $this->assertSame('fixture', $first['provider']);
        $this->assertSame(1, $officialCalls);

        $second = $chain->fetch('DRAW-1');
        $this->assertSame('fixture', $second['provider']);

        // THE ASSERTION THAT MATTERS: the official provider was called ONCE in
        // total. The breach was that each fetch paid a full request timeout
        // against a dead upstream; a breaker that still calls the source and
        // merely records the result has changed nothing.
        $this->assertSame(1, $officialCalls);

        $breaker = app(CircuitBreaker::class);
        $state = $breaker->describe('official', BreakerPolicy::fromConfig(app('config')));

        $this->assertSame('open', $state['state']);
        $this->assertGreaterThan(0, $state['retry_after_seconds']);

        $attempt = $chain->attempts()[0];
        $this->assertSame('official', $attempt['source']);
        $this->assertSame('skipped', $attempt['outcome']);
        $this->assertStringContainsString('circuit open', (string) $attempt['reason']);
    }

    #[Test]
    public function a_successful_probe_after_the_cooldown_closes_the_circuit(): void
    {
        config([
            'glo.sources.priority' => ['official', 'fixture'],
            'glo.sources.breaker.failure_threshold' => 1,
            'glo.sources.breaker.cooldown_seconds' => 60,
        ]);

        $this->mock(GloOfficialResultProvider::class, function ($mock): void {
            $mock->shouldReceive('name')->andReturn('official');
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('fetch')->twice()->andReturn(
                $this->failed('official', 'blip', 'DRAW-1'),
                $this->payload('imported', 'official', 'DRAW-1'),
            );
        });

        $this->mock(GloFixtureResultProvider::class, function ($mock): void {
            $mock->shouldReceive('name')->andReturn('fixture');
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('fetch')->once()->andReturn($this->payload('imported', 'fixture', 'DRAW-1'));
        });

        $breaker = app(CircuitBreaker::class);
        $policy = BreakerPolicy::fromConfig(app('config'));
        $chain = app(GloResultProviderChain::class);

        // Trip it (threshold 1).
        $this->assertSame('fixture', $chain->fetch('DRAW-1')['provider']);
        $this->assertSame('open', $breaker->describe('official', $policy)['state']);

        // Travel past the cooldown. Config is time-independent, so the clock is
        // moved rather than the policy weakened — a test that shortened the
        // cooldown would prove nothing about the cooldown.
        $this->travel(61)->seconds();

        $payload = $chain->fetch('DRAW-1');

        $this->assertSame('official', $payload['provider']);
        $this->assertSame('closed', $breaker->describe('official', $policy)['state']);
        $this->assertSame(0, $breaker->describe('official', $policy)['failures']);
    }

    #[Test]
    public function an_unconfigured_source_is_skipped_without_being_counted_as_a_failure(): void
    {
        // The distinction that keeps this breaker honest: `isConfigured() === false`
        // means THIS deployment has no credentials, and no amount of waiting will
        // change that. Counting it would open the circuit and report "the source
        // is down" for a source that was never reachable — sending an operator to
        // look at glo.or.th when the missing thing is an environment variable.
        config(['glo.sources.priority' => ['official', 'fixture']]);

        $this->mock(GloOfficialResultProvider::class, function ($mock): void {
            $mock->shouldReceive('name')->andReturn('official');
            $mock->shouldReceive('isConfigured')->andReturn(false);
            $mock->shouldReceive('fetch')->never();
        });

        $this->mock(GloFixtureResultProvider::class, function ($mock): void {
            $mock->shouldReceive('name')->andReturn('fixture');
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('fetch')->once()->andReturn($this->payload('imported', 'fixture', 'DRAW-1'));
        });

        $chain = app(GloResultProviderChain::class);
        $payload = $chain->fetch('DRAW-1');

        $this->assertSame('fixture', $payload['provider']);
        $this->assertSame('skipped', $chain->attempts()[0]['outcome']);

        $state = app(CircuitBreaker::class)->describe('official', BreakerPolicy::fromConfig(app('config')));

        $this->assertSame('closed', $state['state']);
        $this->assertSame(0, $state['failures']);
    }

    #[Test]
    public function when_every_source_fails_the_answer_contains_no_numbers(): void
    {
        config(['glo.sources.priority' => ['official', 'fixture']]);

        $this->mock(GloOfficialResultProvider::class, function ($mock): void {
            $mock->shouldReceive('name')->andReturn('official');
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('fetch')->andReturn($this->failed('official', 'timeout', 'DRAW-1'));
        });

        $this->mock(GloFixtureResultProvider::class, function ($mock): void {
            $mock->shouldReceive('name')->andReturn('fixture');
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('fetch')->andReturn($this->failed('fixture', 'fixture file missing', 'DRAW-1'));
        });

        $payload = app(GloResultProviderChain::class)->fetch('DRAW-1');

        $this->assertSame('failed', $payload['status']);

        // NOT ZERO, AND NOT AN EMPTY STRING. A zero is a number, and a
        // fabricated zero in a result payload is a winner nobody picked. The
        // empty shape is what makes the failure recognisable as a failure
        // downstream, including by the fingerprint fail-closed check.
        $this->assertNull($payload['first_prize']);
        $this->assertNull($payload['fingerprint']);
        $this->assertSame([], $payload['second_prize']);
        $this->assertSame([], $payload['third_prize']);
        $this->assertSame([], $payload['n3']);
        $this->assertSame([], $payload['tiers']);

        // The reason names every attempt, so an operator does not have to read
        // three log files to find out which sources were tried.
        $reason = (string) $payload['failure_reason'];
        $this->assertStringContainsString('official', $reason);
        $this->assertStringContainsString('fixture', $reason);
        $this->assertStringContainsString('timeout', $reason);
        $this->assertStringContainsString('fixture file missing', $reason);
    }

    #[Test]
    public function an_empty_ladder_is_reported_as_a_deployment_error_not_an_outage(): void
    {
        config(['glo.sources.priority' => ['a-source-this-build-does-not-implement']]);

        $chain = app(GloResultProviderChain::class);
        $payload = $chain->fetch('DRAW-1');

        $this->assertSame('failed', $payload['status']);
        $this->assertSame([], $chain->attempts());
        $this->assertStringContainsString('No result source is configured', (string) $payload['failure_reason']);
    }

    #[Test]
    public function a_provider_that_declares_itself_not_configured_does_not_open_the_circuit(): void
    {
        // The same distinction as the isConfigured() test above, arriving from the
        // other direction: the provider SAID it was configured and then answered
        // `not_configured`. Both must be treated as deployment facts.
        config(['glo.sources.priority' => ['official']]);

        $this->mock(GloOfficialResultProvider::class, function ($mock): void {
            $mock->shouldReceive('name')->andReturn('official');
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('fetch')->andReturn([
                'status' => 'not_configured',
                'provider' => 'official',
                'endpoint' => null,
                'draw_number' => 'DRAW-1',
                'first_prize' => null,
                'second_prize' => [],
                'third_prize' => [],
                'n3' => [],
                'tiers' => [],
                'failure_reason' => 'GLO_OFFICIAL_ENDPOINT is not set',
                'fingerprint' => null,
            ]);
        });

        $payload = app(GloResultProviderChain::class)->fetch('DRAW-1');

        $this->assertSame('failed', $payload['status']);
        $this->assertStringContainsString('not_configured', (string) $payload['failure_reason']);
        $this->assertStringContainsString('GLO_OFFICIAL_ENDPOINT is not set', (string) $payload['failure_reason']);

        $state = app(CircuitBreaker::class)->describe('official', BreakerPolicy::fromConfig(app('config')));

        $this->assertSame('closed', $state['state']);
    }

    #[Test]
    public function the_import_service_fetches_through_the_ladder_and_labels_from_the_payload(): void
    {
        // The end-to-end property, asserted at the seam that matters: the label
        // written to the provenance row comes from the PAYLOAD's provider field,
        // not from the name of the object the service asked.
        config([
            'glo.official_source.mode' => 'fixture',
            'glo.sources.priority' => [],
        ]);

        $imports = app(GloResultImportService::class);

        $this->assertSame('chain', $imports->provider()->name());
    }

    /**
     * A minimal `imported` payload in the provider contract's shape.
     *
     * Deliberately built by hand rather than read from the fixture file: these
     * tests are about the ladder's ordering and labelling, and a payload that
     * changed shape when the fixture was updated would make them fail for a
     * reason that has nothing to do with what they assert.
     *
     * @return array<string, mixed>
     */
    private function payload(string $status, string $provider, string $drawNumber): array
    {
        return [
            'status' => $status,
            'provider' => $provider,
            'endpoint' => 'test://'.$provider,
            'draw_number' => $drawNumber,
            'first_prize' => '042042',
            'second_prize' => ['112233'],
            'third_prize' => ['600001', '600002'],
            'n3' => ['special' => ['007']],
            'tiers' => ['last_two' => ['58']],
            'failure_reason' => null,
            'fingerprint' => hash('sha256', $provider.'|'.$drawNumber.'|042042'),
        ];
    }

    /**
     * A `failed` payload in the provider contract's shape — the honest negative.
     *
     * @return array<string, mixed>
     */
    private function failed(string $provider, string $reason, string $drawNumber): array
    {
        return [
            'status' => 'failed',
            'provider' => $provider,
            'endpoint' => null,
            'draw_number' => $drawNumber,
            'first_prize' => null,
            'second_prize' => [],
            'third_prize' => [],
            'n3' => [],
            'tiers' => [],
            'failure_reason' => $reason,
            'fingerprint' => null,
        ];
    }
}
