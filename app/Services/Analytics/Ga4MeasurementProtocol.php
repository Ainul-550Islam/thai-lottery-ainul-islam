<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GA4 Measurement Protocol transport (D3).
 *
 * WHY SERVER-SIDE AT ALL
 * ----------------------
 * Browser-side analytics loses conversions to ad blockers, to browsers that
 * block third-party scripts, and to visitors who close the tab the instant a
 * payment gateway redirects them — which on this product is most of them,
 * since deposits complete on a bKash/Nagad page and return later. A
 * browser-only funnel systematically under-reports exactly the conversions it
 * exists to measure.
 *
 * So the money events are sent from the server, where the truth already is.
 *
 * FAILURE ISOLATION IS THE WHOLE POINT
 * ------------------------------------
 * This class NEVER throws. Every failure path returns false and logs. That is
 * not defensive habit, it is the design requirement: this code is reached from
 * a queued job, and a queued job that throws gets retried, and a retry loop
 * against a rate-limited endpoint turns a brief analytics outage into a queue
 * backlog. Worse, if this were ever called inline from a payment path, a throw
 * would roll back a real-money transaction because Google was unreachable.
 *
 * An analytics call must be incapable of affecting the thing it measures.
 */
final class Ga4MeasurementProtocol
{
    private const ENDPOINT = 'https://www.google-analytics.com/mp/collect';

    /**
     * Send one conversion.
     *
     * @param  array<string, string>  $identity  'client_id' and/or 'session_id'
     */
    public function send(ConversionEvent $event, array $identity = []): bool
    {
        $measurementId = $this->config('ga4_measurement_id');
        $apiSecret = $this->config('ga4_api_secret');

        // Unconfigured is not an error. Silently doing nothing is correct:
        // no property has been nominated, so there is nowhere to send it.
        if (! is_string($measurementId) || $measurementId === ''
            || ! is_string($apiSecret) || $apiSecret === '') {
            return false;
        }

        $payload = $this->payload($event, $identity, $measurementId);

        if ($payload === null) {
            return false;
        }

        $timeout = (int) ($this->config('timeout_seconds') ?: 3);

        try {
            $response = Http::asJson()
                ->timeout($timeout)
                ->connectTimeout(min($timeout, 2))
                // No retries. A conversion that failed once is a data point
                // about the analytics pipeline, not about the business, and
                // retrying a real-money event risks double-counting revenue.
                ->retry(0)
                ->post(self::ENDPOINT.'?'.http_build_query([
                    'measurement_id' => $measurementId,
                    'api_secret' => $apiSecret,
                ]), $payload);

            if ($response->successful()) {
                return true;
            }

            // GA4 deliberately returns 2xx with an empty body for accepted
            // events and non-2xx for rejected ones. A rejected event means the
            // payload is malformed, which is a bug here — log the shape.
            Log::warning('analytics.ga4.rejected', [
                'status' => $response->status(),
                'event' => $event->name(),
                'param_keys' => array_keys($event->params()),
            ]);

            return false;
        } catch (Throwable $e) {
            // Connection refused, DNS failure, timeout: all expected at some
            // point, none of them actionable by the queue.
            Log::warning('analytics.ga4.unreachable', [
                'event' => $event->name(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Build the Measurement Protocol body.
     *
     * @param  array<string, string>  $identity
     * @return array<string, mixed>|null
     */
    private function payload(ConversionEvent $event, array $identity, string $measurementId): ?array
    {
        if ($event->isEmpty() && $identity === []) {
            return ['events' => [['name' => $event->name()]]];
        }

        $params = $event->params();

        // Measurement Protocol requires one of client_id or session_id.
        // Without either, GA4 discards the event, so there is no point sending.
        $clientId = $identity['client_id'] ?? null;
        $sessionId = $identity['session_id'] ?? null;

        if (! is_string($clientId) || $clientId === '') {
            if (! is_string($sessionId) || $sessionId === '') {
                return null;
            }
            $clientId = $sessionId;
        }

        return [
            'client_id' => $clientId,
            'events' => [
                [
                    'name' => $event->name(),
                    'params' => $params,
                ],
            ],
        ];
    }

    private function config(string $key): mixed
    {
        return config('services.analytics.'.$key);
    }
}
