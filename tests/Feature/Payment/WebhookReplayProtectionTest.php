<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Models\Deposit;
use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\WebhookEventType;
use App\Http\Middleware\VerifyWebhookSignature;
use App\Models\WebhookReplayGuard;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;

/**
 * Webhook retry idempotency and durable delivery observation.
 *
 * ============================================================================
 * WHAT THESE TESTS ESTABLISH
 * ============================================================================
 * The same valid signed event delivered twice must produce one financial effect.
 * The payment transaction/idempotency path owns that guarantee. The
 * WebhookReplayGuard table separately records accepted deliveries and counts
 * repeats; its unique index makes the telemetry row atomic, but it does NOT
 * reject a request before the controller and is NOT the financial control.
 *
 * Clearing the application cache between deliveries proves that this payment
 * path does not rely solely on node-local cache state for the tested duplicate.
 * It does not imply the observation middleware blocks the replay.
 */
final class WebhookReplayProtectionTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.webhook.replay_protection', true);
        Config::set('payment.webhook.replay_guard_retention_days', 30);
    }

    /**
     * @return array{payload: array<string, mixed>, header: string}
     */
    private function signedStripeDelivery(string $reference, string $eventId): array
    {
        $timestamp = time();

        // Stripe reports amount_total in the currency's smallest unit (satang). The gateway
        // rejects any event whose amount differs from the deposit it settles, so the
        // amount is read from the deposit itself rather than hard-coded.
        $depositAmount = (string) Deposit::query()->where('reference_number', $reference)->value('amount');
        $amountTotal = (int) bcmul($depositAmount, '100', 0);

        $payload = [
            'id' => $eventId,
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_'.$eventId,
                    'client_reference_id' => $reference,
                    'amount_total' => $amountTotal,
                    'currency' => 'thb',
                ],
            ],
        ];

        $json = json_encode($payload);
        $signature = hash_hmac('sha256', "{$timestamp}.{$json}", self::STRIPE_WEBHOOK_SECRET);

        return [
            'payload' => $payload,
            'header' => "t={$timestamp},v1={$signature}",
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The control
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function the_same_signed_webhook_delivered_twice_is_processed_once(): void
    {
        $player = $this->createPlayer('0.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '1000.00', PaymentMethod::Stripe);

        $delivery = $this->signedStripeDelivery($deposit->reference_number, 'evt_replay_001');

        // ── First delivery: processed, credited.
        $first = $this->withHeaders(['Stripe-Signature' => $delivery['header']])
            ->postJson('/api/v1/payments/webhook/stripe', $delivery['payload']);

        $first->assertStatus(200);
        $first->assertJsonPath('success', true);

        $balanceAfterFirst = $player['wallet']->fresh()->balance;
        $this->assertNotSame('0.00', (string) $balanceAfterFirst, 'the first delivery must credit');

        // ── Second delivery: payment idempotency recognizes the event replay.
        $second = $this->withHeaders(['Stripe-Signature' => $delivery['header']])
            ->postJson('/api/v1/payments/webhook/stripe', $delivery['payload']);

        // 200, not 4xx: the work is already done, and a provider that received a
        // 4xx would treat the delivery as failed and keep retrying.
        $second->assertStatus(200);
        $second->assertJsonPath('success', true);
        $second->assertJsonPath('duplicate', true);

        // The balance did not move a second time.
        $this->assertSame(
            (string) $balanceAfterFirst,
            (string) $player['wallet']->fresh()->balance,
            'a replayed webhook must not move money a second time',
        );
    }

    /**
     * The financial duplicate protection remains effective after cache loss.
     *
     * This is a payment idempotency assertion, not a claim that the observation
     * table rejects the second HTTP request.
     */
    #[Test]
    public function replay_is_idempotent_even_when_the_cache_is_cleared_between_deliveries(): void
    {
        $player = $this->createPlayer('0.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '750.00', PaymentMethod::Stripe);

        $delivery = $this->signedStripeDelivery($deposit->reference_number, 'evt_replay_002');

        $this->withHeaders(['Stripe-Signature' => $delivery['header']])
            ->postJson('/api/v1/payments/webhook/stripe', $delivery['payload'])
            ->assertStatus(200);

        $balanceAfterFirst = $player['wallet']->fresh()->balance;

        // Simulate a different node / a restarted container / an eviction:
        // everything the application remembers in cache is gone.
        cache()->flush();

        $second = $this->withHeaders(['Stripe-Signature' => $delivery['header']])
            ->postJson('/api/v1/payments/webhook/stripe', $delivery['payload']);

        $second->assertStatus(200);
        $second->assertJsonPath('duplicate', true);

        $this->assertSame(
            (string) $balanceAfterFirst,
            (string) $player['wallet']->fresh()->balance,
            'clearing cache must not allow a second financial effect',
        );
    }

    #[Test]
    public function the_guard_records_the_replay_durably(): void
    {
        $player = $this->createPlayer('0.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '300.00', PaymentMethod::Stripe);

        $delivery = $this->signedStripeDelivery($deposit->reference_number, 'evt_replay_003');

        $this->withHeaders(['Stripe-Signature' => $delivery['header']])
            ->postJson('/api/v1/payments/webhook/stripe', $delivery['payload'])
            ->assertStatus(200);

        $this->withHeaders(['Stripe-Signature' => $delivery['header']])
            ->postJson('/api/v1/payments/webhook/stripe', $delivery['payload'])
            ->assertStatus(200);

        $guard = WebhookReplayGuard::query()->where('gateway', 'stripe')->first();

        $this->assertNotNull($guard, 'the guard must persist a row for the delivery');
        $this->assertSame(2, $guard->deliveries, 'the second delivery must be counted');
        $this->assertTrue($guard->isReplay());
        $this->assertNotNull($guard->expires_at, 'a retention horizon must be set');
        $this->assertTrue(
            $guard->expires_at->isFuture(),
            'the guard row must not already be expired',
        );

        // Store only a stable digest for operations; never keep the provider's
        // raw signature proof in the replay-observation table.
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $guard->signature);
    }

    #[Test]
    public function a_different_payload_is_not_treated_as_a_replay(): void
    {
        $player = $this->createPlayer('0.00', Currency::THB);
        $depositA = $this->createPendingDeposit($player['wallet'], '100.00', PaymentMethod::Stripe);
        $depositB = $this->createPendingDeposit($player['wallet'], '200.00', PaymentMethod::Stripe);

        $deliveryA = $this->signedStripeDelivery($depositA->reference_number, 'evt_distinct_a');
        $deliveryB = $this->signedStripeDelivery($depositB->reference_number, 'evt_distinct_b');

        $this->withHeaders(['Stripe-Signature' => $deliveryA['header']])
            ->postJson('/api/v1/payments/webhook/stripe', $deliveryA['payload'])
            ->assertStatus(200)
            ->assertJsonMissingPath('duplicate');

        // A genuinely different signed payload must NOT be swallowed by the guard.
        // A guard that refuses everything is indistinguishable from an outage.
        $this->withHeaders(['Stripe-Signature' => $deliveryB['header']])
            ->postJson('/api/v1/payments/webhook/stripe', $deliveryB['payload'])
            ->assertStatus(200)
            ->assertJsonMissingPath('duplicate');

        $this->assertSame(2, WebhookReplayGuard::query()->where('gateway', 'stripe')->count());
    }

    #[Test]
    public function two_gateways_with_identical_bytes_do_not_shadow_each_other(): void
    {
        // The nonce is sha256(gateway|signature), so telemetry identity is
        // scoped by gateway. Without that, two providers could be conflated in
        // the observation table even though both requests still reach handlers.
        $player = $this->createPlayer('0.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '400.00', PaymentMethod::Stripe);

        $delivery = $this->signedStripeDelivery($deposit->reference_number, 'evt_shared_bytes');

        // Stripe, under Stripe's header.
        $this->withHeaders(['Stripe-Signature' => $delivery['header']])
            ->postJson('/api/v1/payments/webhook/stripe', $delivery['payload'])
            ->assertStatus(200);

        $this->assertSame(
            1,
            WebhookReplayGuard::query()->where('gateway', 'stripe')->count(),
        );

        // The same payload signed independently by bKash must occupy its own
        // nonce space. A Stripe signature is not a valid bKash signature, so
        // sending Stripe's header to bKash would correctly be rejected and must
        // not create a receipt.
        $bkashBody = json_encode($delivery['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $bkashSignature = hash_hmac('sha256', (string) $bkashBody, self::BKASH_WEBHOOK_SECRET);

        $this->withHeaders(['X-Bkash-Signature' => $bkashSignature])
            ->postJson('/api/v1/payments/webhook/bkash', $delivery['payload'])
            ->assertStatus(200);

        $this->assertSame(
            1,
            WebhookReplayGuard::query()->where('gateway', 'bkash')->count(),
            'the second gateway must get its own guard row',
        );
    }

    #[Test]
    public function replay_protection_can_be_switched_off_by_configuration(): void
    {
        // An escape hatch must exist for diagnosing a provider whose retry
        // behaviour collides with the guard. It is asserted here so that turning
        // it off is a DELIBERATE, TESTED act rather than a code change made under
        // pressure at 3am.
        Config::set('payment.webhook.replay_protection', false);
        Config::set('security.webhook.replay_protection', false);

        $player = $this->createPlayer('0.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '250.00', PaymentMethod::Stripe);

        $delivery = $this->signedStripeDelivery($deposit->reference_number, 'evt_guard_off');

        $this->withHeaders(['Stripe-Signature' => $delivery['header']])
            ->postJson('/api/v1/payments/webhook/stripe', $delivery['payload'])
            ->assertStatus(200);

        $this->withHeaders(['Stripe-Signature' => $delivery['header']])
            ->postJson('/api/v1/payments/webhook/stripe', $delivery['payload'])
            ->assertStatus(200);

        $this->assertSame(
            0,
            WebhookReplayGuard::query()->count(),
            'with the guard disabled no rows are written',
        );
    }

    /**
     * REJECTED DELIVERIES ARE NOT ACCEPTED-DELIVERY OBSERVATIONS.
     *
     * The middleware records only after the controller accepts a webhook. A bad
     * signature or other failed request must leave no observation row; the same
     * invalid request must still reach the provider verifier on retry and be
     * refused by that verifier. The middleware itself does not claim a nonce,
     * authenticate the request or swallow a replay.
     */
    #[Test]
    public function a_refused_delivery_is_not_remembered_so_a_retry_still_reaches_the_driver(): void
    {
        $player = $this->createPlayer('0.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '900.00', PaymentMethod::Stripe);

        $timestamp = time();
        $payload = [
            'id' => 'evt_refused_001',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_refused',
                    'client_reference_id' => $deposit->reference_number,
                    'amount_total' => 90000,
                    'currency' => 'thb',
                ],
            ],
        ];

        // Signed with the WRONG secret. The observer sees the header, but must
        // record nothing because the provider verifier will refuse the request.
        $forged = hash_hmac('sha256', $timestamp.'.'.json_encode($payload), 'not_the_webhook_secret');

        $first = $this->withHeaders(['Stripe-Signature' => "t={$timestamp},v1={$forged}"])
            ->postJson('/api/v1/payments/webhook/stripe', $payload);

        $this->assertGreaterThanOrEqual(400, $first->getStatusCode());

        $this->assertSame(
            0,
            WebhookReplayGuard::query()->where('gateway', 'stripe')->count(),
            'a refused delivery must not be recorded as an accepted observation',
        );

        // The retry must be judged on its own merits, not answered as a duplicate.
        $second = $this->withHeaders(['Stripe-Signature' => "t={$timestamp},v1={$forged}"])
            ->postJson('/api/v1/payments/webhook/stripe', $payload);

        $this->assertGreaterThanOrEqual(400, $second->getStatusCode());
        $this->assertNotSame(200, $second->getStatusCode());

        $this->assertSame(
            '0.00',
            (string) $player['wallet']->fresh()->balance,
            'a refused delivery must never move money',
        );
    }

    #[Test]
    public function an_accepted_delivery_records_observation(): void
    {
        // A delivery accepted by the application produces durable telemetry.
        // Duplicate payment effects remain blocked by the payment state machine,
        // not by this observation row.
        $player = $this->createPlayer('0.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '150.00', PaymentMethod::Stripe);

        $delivery = $this->signedStripeDelivery($deposit->reference_number, 'evt_keeps_claim');

        $this->withHeaders(['Stripe-Signature' => $delivery['header']])
            ->postJson('/api/v1/payments/webhook/stripe', $delivery['payload'])
            ->assertStatus(200);

        $this->assertSame(
            1,
            WebhookReplayGuard::query()->where('gateway', 'stripe')->count(),
            'an accepted delivery must be recorded for telemetry',
        );
    }

    /**
     * THE REGRESSION TEST FOR THE ORIGINAL DEFECT.
     *
     * The middleware existed and was registered as an alias in
     * bootstrap/app.php — and was attached to ZERO routes. `grep -rn
     * "webhook.signature" routes/` returned nothing. Dead security code is worse
     * than no security code: a reviewer who finds it reasonably concludes this
     * surface is guarded and reads no further.
     *
     * This asserts the alias is on the route's middleware stack, so the guard
     * cannot become decoration again. It is deliberately a ROUTE assertion
     * rather than a behavioural one: behaviour is covered by the tests above,
     * and behaviour passing while the middleware is absent would mean something
     * ELSE is doing the work — which is exactly how the defect hid.
     */
    #[Test]
    public function the_replay_guard_is_attached_to_every_webhook_route(): void
    {
        $router = app('router');

        $expected = [
            'api.v1.payments.webhook.handle' => 'api/v1/payments/webhook/{gateway}',
            'api.v1.payments.webhook.receive' => 'api/v1/payments/webhook/v2/{gateway}',
        ];

        foreach ($expected as $name => $uri) {
            $route = $router->getRoutes()->getByName($name);

            $this->assertNotNull($route, "route {$name} must exist");
            $this->assertSame($uri, $route->uri(), "route {$name} must map to {$uri}");

            // gatherRouteMiddleware() resolves ALIASES, so this asserts the
            // thing that actually matters: the class, not the string. An alias
            // registered in bootstrap/app.php and referenced by no route is
            // exactly the defect this pins shut.
            $middleware = $router->gatherRouteMiddleware($route);

            $this->assertContains(
                VerifyWebhookSignature::class,
                $middleware,
                "VerifyWebhookSignature must be on {$name}: a guard that is not attached to a route protects nothing",
            );

            // The alias carries its limiter as a parameter ("...ThrottleRequests:webhook"),
            // so the class is compared before the colon rather than the whole string.
            $throttleEntries = array_filter(
                $middleware,
                static fn (mixed $entry): bool => is_string($entry)
                    && explode(':', $entry, 2)[0] === ThrottleRequests::class,
            );

            $this->assertNotEmpty(
                $throttleEntries,
                "the flood limiter must still be on {$name}",
            );
        }
    }

    /**
     * Signature rotation is DETECTED and NOT refused.
     *
     * Stripe retries with a fresh timestamp, which produces a fresh signature
     * over the same body — different nonce, so the unique index does not refuse
     * it. That is correct: the downstream state machine and the ledger's unique
     * idempotency keys are what make a second credit impossible, and refusing
     * here would break providers whose protocol legitimately allows an identical
     * body twice (a bank-transfer notification with no per-event identifier).
     *
     * So the guard RECORDS it — which is how an operator tells a provider retry
     * storm apart from an attack — and emits `webhook.replay_signature_rotated`
     * to the log. This test pins the recording and the non-refusal; the log line
     * itself is asserted by its presence in the middleware, not here, so the
     * assertion does not depend on logger internals.
     */
    #[Test]
    public function a_rotated_signature_over_a_known_body_is_recorded_but_not_refused(): void
    {
        $player = $this->createPlayer('0.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '600.00', PaymentMethod::Stripe);

        $payload = [
            'id' => 'evt_rotation_001',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_rotation',
                    'client_reference_id' => $deposit->reference_number,
                    'amount_total' => 60000,
                    'currency' => 'thb',
                ],
            ],
        ];

        $json = json_encode($payload);

        $firstTimestamp = time();
        $firstSignature = hash_hmac('sha256', "{$firstTimestamp}.{$json}", self::STRIPE_WEBHOOK_SECRET);

        $this->withHeaders(['Stripe-Signature' => "t={$firstTimestamp},v1={$firstSignature}"])
            ->postJson('/api/v1/payments/webhook/stripe', $payload)
            ->assertStatus(200);

        // Same body, one second later, therefore a different signature.
        $secondTimestamp = time() + 1;
        $secondSignature = hash_hmac('sha256', "{$secondTimestamp}.{$json}", self::STRIPE_WEBHOOK_SECRET);
        $this->assertNotSame($firstSignature, $secondSignature);

        $this->withHeaders(['Stripe-Signature' => "t={$secondTimestamp},v1={$secondSignature}"])
            ->postJson('/api/v1/payments/webhook/stripe', $payload)
            ->assertStatus(200);

        $guards = WebhookReplayGuard::query()->where('gateway', 'stripe')->get();

        $this->assertCount(2, $guards, 'a rotated signature must occupy its own nonce');
        $this->assertCount(
            1,
            $guards->pluck('payload_hash')->unique()->filter()->all(),
            'both deliveries must carry the SAME body hash, which is what makes rotation detectable',
        );
        $this->assertCount(
            2,
            $guards->pluck('nonce')->unique()->all(),
            'the two nonces must differ',
        );
    }

    /**
     * ── THE SERVICE-LEVEL GUARD, PRESERVED VERBATIM ──────────────────────────
     *
     * This test predates the durable guard and is kept, unchanged, because it
     * covers something the middleware does NOT: processWebhookPayload() called
     * directly, as a queue job or a console command would call it, with no HTTP
     * request and therefore no route middleware in front of it.
     *
     * That path is guarded by the CACHE entry
     * ('payment:webhook:seen:{gateway}:{eventId}'), which is the per-node
     * substrate the durable guard exists to supplement. Both controls now run:
     * the table for anything arriving over HTTP, the cache for anything arriving
     * in-process. Deleting this test on the grounds that "the middleware covers
     * it now" would remove the only coverage of the in-process path.
     */
    #[Test]
    public function identical_webhook_event_id_is_cached_and_ignored_on_replay(): void
    {
        $player = $this->createPlayer('500.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '1000.00', PaymentMethod::Stripe);

        $payload = new WebhookPayload(
            gateway: 'stripe',
            eventId: 'evt_unique_test_replay_999',
            eventType: WebhookEventType::DepositSuccess,
            providerReference: 'cs_test_session_999',
            internalReference: $deposit->reference_number,
            amount: '1000.00',
            currency: Currency::THB,
            isSuccess: true,
        );

        // First execution
        $firstResult = $this->webhookService()->processWebhookPayload($payload);
        $this->assertTrue($firstResult->success);
        $this->assertFalse($firstResult->replayed);
        $this->assertSame('credited', $firstResult->actionTaken);

        $player['wallet']->refresh();
        $this->assertSame('1500.00', $player['wallet']->balance);

        // Replay of same eventId
        $secondResult = $this->webhookService()->processWebhookPayload($payload);
        $this->assertTrue($secondResult->success);
        $this->assertTrue($secondResult->replayed);
        $this->assertSame('ignored', $secondResult->actionTaken);

        // Balance remains 1500.00, not double credited
        $player['wallet']->refresh();
        $this->assertSame('1500.00', $player['wallet']->balance);
    }

    #[Test]
    public function an_unsigned_webhook_is_still_refused_by_the_driver(): void
    {
        // The middleware deliberately does NOT reject on a missing signature —
        // it has no nonce to key on and the driver owns that refusal. What must
        // hold is that the request is refused SOMEWHERE, so this asserts the
        // outcome rather than which layer produced it.
        $player = $this->createPlayer('0.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '100.00', PaymentMethod::Stripe);

        $delivery = $this->signedStripeDelivery($deposit->reference_number, 'evt_unsigned');

        $response = $this->postJson('/api/v1/payments/webhook/stripe', $delivery['payload']);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());

        $this->assertSame(
            '0.00',
            (string) $player['wallet']->fresh()->balance,
            'an unsigned webhook must not move money',
        );
    }
}
