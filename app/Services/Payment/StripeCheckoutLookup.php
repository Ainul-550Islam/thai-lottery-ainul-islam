<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\GatewayDepositResponse;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/** Bounded, positive-only server-account GET lookup. Never create, expire, charge, refund or infer absence. */
final class StripeCheckoutLookup
{
    public function find(Deposit $deposit, object $attempt): GatewayDepositResponse
    {
        app(DepositCheckoutService::class)->assertTopLevel();
        $secret = config('payment.gateways.stripe.secret');
        if (! config('payment.checkout_lookup.stripe.enabled', false) || ! is_string($secret) || trim($secret) === '') {
            $this->refuse();
        }
        $start = Carbon::parse($attempt->dispatched_at, config('app.timezone'))->timestamp;
        $query = ['limit' => 100, 'created' => ['gte' => $start - 300, 'lte' => $start + 3600]];
        $found = [];
        $seen = [];
        for ($page = 0; $page < 3; $page++) {
            $list = $this->get('https://api.stripe.com/v1/checkout/sessions', $query, $secret);
            if (($list['object'] ?? null) !== 'list' || ! is_array($list['data'] ?? null)
                || ! array_is_list($list['data']) || count($list['data']) > 100 || ! is_bool($list['has_more'] ?? null)) {
                $this->refuse();
            }
            foreach ($list['data'] as $session) {
                if (! is_array($session) || ! $this->id($session['id'] ?? null) || isset($seen[$session['id']])) {
                    $this->refuse();
                }
                $seen[$session['id']] = true;
                if (data_get($session, 'metadata.checkout_attempt') === $attempt->uuid) {
                    $this->assertSession($session, $deposit, $attempt, $start);
                    $found[] = $session['id'];
                }
            }
            if (count($found) > 1) {
                $this->refuse();
            }
            if (! $list['has_more']) {
                if (count($found) !== 1) {
                    $this->refuse();
                }
                $session = $this->get('https://api.stripe.com/v1/checkout/sessions/'.rawurlencode($found[0]), [], $secret);
                $this->assertSession($session, $deposit, $attempt, $start);
                if ($session['id'] !== $found[0]) {
                    $this->refuse();
                }

                return new GatewayDepositResponse(successful: true,
                    redirectUrl: $session['status'] === 'open' ? $session['url'] : null,
                    providerReference: $session['id'], rawResponse: $session,
                    metadata: ['session_id' => $session['id'], 'payment_intent' => $session['payment_intent'] ?? null]);
            }
            if ($list['data'] === []) {
                $this->refuse();
            }
            $query['starting_after'] = $list['data'][array_key_last($list['data'])]['id'];
        }
        // A truncated scan is not proof of uniqueness or of nonacceptance.
        $this->refuse();
    }

    private function get(string $url, array $query, string $secret): array
    {
        try {
            $response = Http::withToken($secret)->acceptJson()->connectTimeout(5)->timeout(15)
                ->withOptions(['allow_redirects' => false])->get($url, $query);
            if (! $response->successful() || strlen($response->body()) > 2097152) {
                $this->refuse();
            }
            $value = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($value)) {
                $this->refuse();
            }

            return $value;
        } catch (\Throwable) {
            $this->refuse();
        }
    }

    private function assertSession(array $s, Deposit $d, object $a, int $start): void
    {
        $expected = ['checkout_attempt' => $a->uuid, 'deposit_id' => (string) $d->id,
            'deposit_reference' => $d->reference_number, 'wallet_id' => (string) $d->wallet_id, 'user_id' => (string) $d->user_id];
        foreach ($expected as $field => $value) {
            if (data_get($s, 'metadata.'.$field) !== $value) {
                $this->refuse();
            }
        }
        $cents = bcmul((string) $d->amount, '100', 0);
        if (! $this->id($s['id'] ?? null) || ($s['object'] ?? null) !== 'checkout.session'
            || ($s['mode'] ?? null) !== 'payment' || ($s['client_reference_id'] ?? null) !== $d->reference_number
            || ($s['currency'] ?? null) !== strtolower($d->currency->value)
            || ! is_int($s['amount_total'] ?? null) || (string) $s['amount_total'] !== $cents
            || ! is_int($s['created'] ?? null) || $s['created'] < $start - 300 || $s['created'] > $start + 3600
            || ! in_array($s['status'] ?? null, ['open', 'complete', 'expired'], true)
            || ! is_bool($s['livemode'] ?? null)) {
            $this->refuse();
        }
        $key = (string) config('payment.gateways.stripe.secret');
        $mode = str_starts_with($key, 'sk_live_') || str_starts_with($key, 'rk_live_');
        if ((! $mode && ! str_starts_with($key, 'sk_test_') && ! str_starts_with($key, 'rk_test_')) || $s['livemode'] !== $mode) {
            $this->refuse();
        }
        if ($s['status'] === 'open') {
            $url = $s['url'] ?? null;
            $parts = is_string($url) && strlen($url) <= 4096 ? parse_url($url) : false;
            if (! $parts || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'checkout.stripe.com'
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
                || preg_match('/[\x00-\x20\x7f]/', $url) || ! is_int($s['expires_at'] ?? null) || $s['expires_at'] <= time()) {
                $this->refuse();
            }
        }
    }

    private function id(mixed $id): bool
    {
        return is_string($id) && preg_match('/^cs_(?:test_|live_)?[A-Za-z0-9]{8,240}$/D', $id) === 1;
    }

    private function refuse(): never
    {
        throw FinancialException::withCode('checkout_lookup_inconclusive', 'The bounded provider lookup did not prove one original checkout; keep the hold and never resend.');
    }
}
