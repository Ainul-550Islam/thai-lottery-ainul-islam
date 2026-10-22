<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\QueueName;
use App\Jobs\SendConversionEventJob;
use Illuminate\Http\Request;
use Throwable;

/**
 * The one entry point for recording a marketing conversion (D3).
 *
 * WHY A FACADE INSTEAD OF CALLING THE TRANSPORT DIRECTLY
 * ------------------------------------------------------
 * Two rules have to hold everywhere, and only one class can enforce them:
 *
 *   1. Nothing is ever sent inline. Every conversion becomes a queued job, so
 *      an unreachable analytics endpoint cannot slow or fail the request that
 *      produced it. A visitor must never wait for Google — and above all must
 *      never lose a real-money transaction because Google was down.
 *
 *   2. The whole thing can be switched off. One config flag, honoured here and
 *      nowhere else, so "stop measuring" is one environment change rather than
 *      an audit of every call site.
 *
 * CALLING THIS MUST BE FREE
 * -------------------------
 * Every method swallows its own failures and returns bool. Call sites in money
 * paths must not have to wrap analytics in try/catch — if they did, someone
 * would eventually forget, and the consequence of forgetting is a rolled-back
 * transaction.
 */
final class ConversionTracker
{
    public function __construct(
        private readonly Ga4MeasurementProtocol $transport,
    ) {}

    // ── the five conversions this business has ───────────────────────────────

    /**
     * A player completed registration.
     *
     * No `method` is sent: at registration time the player has not chosen a
     * payment rail, and sending an empty one would create a junk dimension.
     */
    public function registration(?string $playerRef = null): bool
    {
        return $this->track(ConversionEvent::make(
            ConversionEvent::REGISTRATION,
            ['player_ref' => $playerRef]
        ));
    }

    /**
     * A deposit reached a settled success state.
     *
     * @param  bool  $first  true when this is the player's first-ever deposit.
     *                       It sends BOTH the generic `deposit` event and the
     *                       `first_deposit` one, because the first deposit is
     *                       the single most important number in the funnel and
     *                       needs to be readable without a segment.
     */
    public function deposit(
        float $amount,
        string $currency,
        string $reference,
        string $method,
        bool $first = false,
        ?string $playerRef = null,
    ): bool {
        $params = [
            'value' => $amount,
            'currency' => $currency,
            'transaction_id' => $reference,
            'method' => $method,
            'player_ref' => $playerRef,
        ];

        $sent = $this->track(ConversionEvent::make(ConversionEvent::DEPOSIT, $params));

        if ($first) {
            $sent = $this->track(ConversionEvent::make(ConversionEvent::FIRST_DEPOSIT, $params)) || $sent;
        }

        return $sent;
    }

    /**
     * A bet was accepted.
     */
    public function betPlaced(
        float $stake,
        string $currency,
        string $reference,
        string $lane,
        ?string $playerRef = null,
    ): bool {
        return $this->track(ConversionEvent::make(ConversionEvent::BET_PLACED, [
            'value' => $stake,
            'currency' => $currency,
            'transaction_id' => $reference,
            'lane' => $lane,
            'player_ref' => $playerRef,
        ]));
    }

    /**
     * A withdrawal was requested.
     *
     * Value is sent as the amount requested. Note this is a REQUEST, not a
     * payout: recording it as revenue would double-count money that merely
     * moved out of the player's own balance.
     */
    public function withdrawalRequested(
        float $amount,
        string $currency,
        string $reference,
        string $method,
        ?string $playerRef = null,
    ): bool {
        return $this->track(ConversionEvent::make(ConversionEvent::WITHDRAWAL_REQUESTED, [
            'value' => $amount,
            'currency' => $currency,
            'transaction_id' => $reference,
            'method' => $method,
            'player_ref' => $playerRef,
        ]));
    }

    // ── core ─────────────────────────────────────────────────────────────────

    /**
     * Queue one conversion. Returns false when analytics is off or the dispatch
     * itself fails; never throws.
     */
    public function track(ConversionEvent $event): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        try {
            SendConversionEventJob::dispatch($event, $this->identity())
                ->onQueue(QueueName::Default->value);

            return true;
        } catch (Throwable) {
            // A queue that cannot accept the job is an infrastructure problem,
            // not a reason to disturb whatever the caller was doing.
            return false;
        }
    }

    public function enabled(): bool
    {
        return (bool) config('services.analytics.enabled', false);
    }

    /**
     * Pseudonymise a player id for outbound use.
     *
     * A raw player id is personal data under both the PDPA and the GDPR:
     * joined to the rest of the dataset it re-identifies a person. An HMAC with
     * a server-side salt makes the value stable enough to attribute a funnel
     * while being useless to anyone who obtains it, because the salt never
     * leaves this server.
     *
     * Returns null when no salt is configured. Sending an unsalted hash of a
     * small integer id would be trivially reversible by brute force, so the
     * honest answer is to send nothing rather than something weak.
     */
    public function playerRef(int|string|null $playerId): ?string
    {
        if ($playerId === null || $playerId === '') {
            return null;
        }

        $salt = config('services.analytics.identity_salt');

        if (! is_string($salt) || $salt === '') {
            return null;
        }

        return substr(hash_hmac('sha256', (string) $playerId, $salt), 0, 32);
    }

    /**
     * The browser identity this conversion should be attributed to.
     *
     * The GA `_ga` cookie holds `GA1.1.XXXXXXXXX.YYYYYYYYYY`; the client id is
     * the last two parts. Without it, a server-side conversion cannot be joined
     * to the visit that produced it and lands in GA4 as direct/none — which is
     * the single most common reason a Measurement Protocol funnel looks broken.
     */
    private function identity(): array
    {
        $identity = [];

        try {
            $request = request();
            if (! $request instanceof Request) {
                return $identity;
            }

            $ga = $request->cookie('_ga');
            if (is_string($ga) && $ga !== '') {
                $parts = explode('.', $ga);
                if (count($parts) >= 4) {
                    $identity['client_id'] = $parts[2].'.'.$parts[3];
                }
            }

            if ($request->hasSession()) {
                $sessionId = $request->session()->getId();
                if (is_string($sessionId) && $sessionId !== '') {
                    $identity['session_id'] = $sessionId;
                }
            }
        } catch (Throwable) {
            // No request context (console, queue) or no cookie: the event is
            // still sent, it just cannot be attributed to a browser session.
        }

        return $identity;
    }
}
