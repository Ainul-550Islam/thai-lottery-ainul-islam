<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Models\WebhookReplayGuard;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * Retention for the durable webhook replay guard.
 *
 * ============================================================================
 * WHAT IS ACTUALLY AT RISK HERE
 * ============================================================================
 * The pruning command is the only thing in the system that DELETES a replay
 * guard row, and deleting a live row is not a cosmetic mistake: it re-opens the
 * replay window for that exact payload. The guard's whole value is that
 * (gateway, nonce) cannot be inserted twice while the horizon holds.
 *
 * So the tests below are weighted toward the refusal to delete: a live row must
 * survive, a row with no horizon must survive, and only a row whose own
 * `expires_at` has passed may go. Deleting too little is a table that grows;
 * deleting too much is a control that stops working, quietly.
 *
 * The second property is that the cutoff is the ROW'S OWN horizon and never a
 * recomputed "now minus N days". If retention is ever shortened, a recomputed
 * cutoff would delete rows that were created under the longer setting and are
 * still within the window they were promised.
 */
final class WebhookReplayGuardRetentionTest extends PaymentTestCase
{
    private function guard(
        string $nonce,
        ?Carbon $expiresAt,
        int $deliveries = 1,
        string $gateway = 'stripe',
    ): WebhookReplayGuard {
        $now = Carbon::now();

        $guard = new WebhookReplayGuard();

        $guard->fill([
            'gateway' => $gateway,
            'nonce' => $nonce,
            'signature' => 'sig_'.$nonce,
            'payload_hash' => hash('sha256', 'body_'.$nonce),
            'deliveries' => $deliveries,
            'first_seen_at' => $now->copy()->subDay(),
            'last_seen_at' => $now,
            'expires_at' => $expiresAt,
            'first_ip' => '127.0.0.1',
            'last_ip' => '127.0.0.1',
        ]);

        $guard->save();

        return $guard;
    }

    #[Test]
    public function it_removes_only_rows_whose_own_horizon_has_passed(): void
    {
        $expired = $this->guard('nonce_expired_a', Carbon::now()->subMinute());
        $alsoExpired = $this->guard('nonce_expired_b', Carbon::now()->subDays(2));
        $live = $this->guard('nonce_live', Carbon::now()->addDays(29));

        $this->artisan('payment:purge-webhook-replay-guards')
            ->assertExitCode(0);

        $this->assertNull(WebhookReplayGuard::query()->find($expired->id), 'an expired row must go');
        $this->assertNull(WebhookReplayGuard::query()->find($alsoExpired->id));
        $this->assertNotNull(WebhookReplayGuard::query()->find($live->id), 'a LIVE row must survive: deleting it re-opens the replay window');
    }

    #[Test]
    public function it_never_removes_a_row_that_has_no_horizon(): void
    {
        // A null expires_at means we never recorded a horizon for this row - an
        // older schema, or an operator writing one by hand. We did not promise a
        // window, so we do not guess one, and we certainly do not delete a row
        // whose purpose is to refuse replays.
        $noHorizon = $this->guard('nonce_no_horizon', null);

        $this->artisan('payment:purge-webhook-replay-guards')
            ->assertExitCode(0);

        $this->assertNotNull(WebhookReplayGuard::query()->find($noHorizon->id));
    }

    #[Test]
    public function a_dry_run_reports_the_same_count_and_writes_nothing(): void
    {
        $this->guard('nonce_dry_1', Carbon::now()->subHour());
        $this->guard('nonce_dry_2', Carbon::now()->subHour());
        $live = $this->guard('nonce_dry_live', Carbon::now()->addDay());

        $this->artisan('payment:purge-webhook-replay-guards --dry-run')
            ->expectsOutputToContain('Dry run: 2')
            ->assertExitCode(0);

        $this->assertSame(3, WebhookReplayGuard::query()->count(), 'a dry run must write nothing');
        $this->assertNotNull(WebhookReplayGuard::query()->find($live->id));
    }

    #[Test]
    public function it_reports_nothing_to_do_when_every_row_is_still_live(): void
    {
        $this->guard('nonce_live_only', Carbon::now()->addDays(10));

        $this->artisan('payment:purge-webhook-replay-guards')
            ->expectsOutputToContain('No webhook replay-guard rows')
            ->assertExitCode(0);

        $this->assertSame(1, WebhookReplayGuard::query()->count());
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        $this->guard('nonce_idem', Carbon::now()->subMinute());

        $this->artisan('payment:purge-webhook-replay-guards')->assertExitCode(0);
        $this->artisan('payment:purge-webhook-replay-guards')->assertExitCode(0);

        $this->assertSame(0, WebhookReplayGuard::query()->count());
    }

    #[Test]
    public function it_sweeps_more_than_one_chunk(): void
    {
        // Proves the loop actually iterates rather than deleting one chunk and
        // stopping. 25 rows with chunk=10 must all be gone.
        for ($i = 0; $i < 25; $i++) {
            $this->guard('nonce_chunk_'.$i, Carbon::now()->subMinute());
        }

        $this->artisan('payment:purge-webhook-replay-guards --chunk=10')
            ->assertExitCode(0);

        $this->assertSame(0, WebhookReplayGuard::query()->count());
    }

    #[Test]
    public function the_guard_row_records_a_future_horizon_when_the_middleware_claims_a_delivery(): void
    {
        // Ties the middleware's write to the retention setting, so a future
        // change to one without the other shows up here rather than in
        // production. The horizon must be in the FUTURE, otherwise the sweep
        // would delete the guard on its next run and re-open the replay window.
        config()->set('payment.webhook.replay_guard_retention_days', 14);

        $player = $this->createPlayer('0.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '100.00', PaymentMethod::Stripe);

        $timestamp = time();
        $payload = [
            'id' => 'evt_horizon_001',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_horizon',
                    'client_reference_id' => $deposit->reference_number,
                    'amount_total' => 10000,
                    'currency' => 'thb',
                ],
            ],
        ];

        $json = json_encode($payload);
        $signature = hash_hmac('sha256', "{$timestamp}.{$json}", self::STRIPE_WEBHOOK_SECRET);

        $this->withHeaders(['Stripe-Signature' => "t={$timestamp},v1={$signature}"])
            ->postJson('/api/v1/payments/webhook/stripe', $payload)
            ->assertStatus(200);

        $guard = WebhookReplayGuard::query()->where('gateway', 'stripe')->firstOrFail();

        $this->assertNotNull($guard->expires_at);
        $this->assertTrue($guard->expires_at->isFuture());

        // now + 14 days, allowing a generous window either side for clock drift
        // between the process that wrote the row and the process reading it.
        $this->assertTrue(
            $guard->expires_at->between(
                Carbon::now()->addDays(13)->subMinutes(5),
                Carbon::now()->addDays(14)->addMinutes(5),
            ),
            sprintf(
                'a 14-day retention must place the horizon ~14 days out; got %s',
                $guard->expires_at->toIso8601String(),
            ),
        );
    }
}
