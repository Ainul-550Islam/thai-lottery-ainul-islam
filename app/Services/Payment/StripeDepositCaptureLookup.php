<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Exceptions\FinancialException;
use App\Models\Deposit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/** Original session -> PaymentIntent -> Charge. GET only; never authorize, capture, refund or infer failure. */
final class StripeDepositCaptureLookup
{
    public function read(Deposit $deposit, object $attempt, string $reference): array
    {
        app(DepositCheckoutService::class)->assertTopLevel();
        $session = $this->get('checkout/sessions', $reference, 'cs');
        $intent = $this->get('payment_intents', $session['payment_intent'] ?? null, 'pi');
        $charge = $this->get('charges', $intent['latest_charge'] ?? null, 'ch');
        $refunds = $this->request('https://api.stripe.com/v1/refunds', ['charge' => $charge['id'] ?? '', 'limit' => 1]);
        $proof = compact('session', 'intent', 'charge', 'refunds');
        $this->validate($proof, $deposit, $attempt, $reference, now()->getTimestamp());

        return $proof;
    }

    public function validate(array $proof, Deposit $deposit, object $attempt, string $reference, int $observed): void
    {
        $s = $proof['session'] ?? [];
        $p = $proof['intent'] ?? [];
        $c = $proof['charge'] ?? [];
        $refunds = $proof['refunds'] ?? [];
        if (! is_array($refunds) || ($refunds['object'] ?? null) !== 'list'
            || ($refunds['has_more'] ?? null) !== false || ($refunds['data'] ?? null) !== []) {
            $this->refuse();
        }
        if (! is_array($s) || ! is_array($p) || ! is_array($c)) {
            $this->refuse();
        }
        $key = config('payment.gateways.stripe.secret');
        if (! is_string($key) || ! preg_match('/^(?:sk|rk)_(test|live)_\S+$/D', $key, $mode)) {
            $this->refuse();
        }
        $live = $mode[1] === 'live';
        $cents = bcmul((string) $deposit->amount, '100', 0);
        $currency = strtolower($deposit->currency->value);
        $start = Carbon::parse($attempt->dispatched_at, config('app.timezone'))->timestamp;
        foreach (['checkout_attempt' => $attempt->uuid, 'deposit_id' => (string) $deposit->id,
            'deposit_reference' => $deposit->reference_number, 'user_id' => (string) $deposit->user_id,
            'wallet_id' => (string) $deposit->wallet_id] as $name => $value) {
            if (data_get($s, 'metadata.'.$name) !== $value) {
                $this->refuse();
            }
        }
        if (! in_array($currency, ['thb', 'usd', 'bdt'], true) || ($s['object'] ?? null) !== 'checkout.session' || ($s['id'] ?? null) !== $reference
            || ($s['mode'] ?? null) !== 'payment' || ($s['status'] ?? null) !== 'complete'
            || ($s['payment_status'] ?? null) !== 'paid' || ($s['client_reference_id'] ?? null) !== $deposit->reference_number
            || ! is_int($s['created'] ?? null) || $s['created'] < $start - 300 || $s['created'] > $start + 3600
            || ! $this->identifier($s['payment_intent'] ?? null, 'pi')
            || ($p['object'] ?? null) !== 'payment_intent' || ($p['id'] ?? null) !== $s['payment_intent']
            || ($p['status'] ?? null) !== 'succeeded' || ($p['amount_capturable'] ?? null) !== 0
            || ! $this->identifier($p['latest_charge'] ?? null, 'ch')
            || ($c['object'] ?? null) !== 'charge' || ($c['id'] ?? null) !== $p['latest_charge']
            || ($c['payment_intent'] ?? null) !== $p['id'] || ($c['status'] ?? null) !== 'succeeded'
            || ($c['paid'] ?? null) !== true || ($c['captured'] ?? null) !== true
            || ($c['refunded'] ?? null) !== false || ($c['disputed'] ?? null) !== false
            || ($c['amount_refunded'] ?? null) !== 0 || ($c['failure_code'] ?? null) !== null
            || ($c['review'] ?? null) !== null || ($p['transfer_data'] ?? null) !== null
            || ($p['on_behalf_of'] ?? null) !== null || ($c['transfer_data'] ?? null) !== null
            || ($c['on_behalf_of'] ?? null) !== null || ($c['application_fee_amount'] ?? null) !== null) {
            $this->refuse();
        }
        foreach ([[$s, ['amount_total']], [$p, ['amount', 'amount_received']], [$c, ['amount', 'amount_captured']]] as [$object, $fields]) {
            if (($object['currency'] ?? null) !== $currency || ($object['livemode'] ?? null) !== $live
                || ! is_int($object['created'] ?? null) || $object['created'] > $observed + 300
                || $object['created'] < $start - 300) {
                $this->refuse();
            }
            foreach ($fields as $field) {
                if (! is_int($object[$field] ?? null) || (string) $object[$field] !== $cents) {
                    $this->refuse();
                }
            }
        }
    }

    private function get(string $resource, mixed $id, string $prefix): array
    {
        if (! $this->identifier($id, $prefix)) {
            $this->refuse();
        }

        return $this->request('https://api.stripe.com/v1/'.$resource.'/'.rawurlencode($id));
    }

    private function request(string $url, array $query = []): array
    {
        if (! config('payment.deposit_settlement.stripe.enabled', false)) {
            $this->refuse();
        }
        try {
            $response = Http::withToken((string) config('payment.gateways.stripe.secret'))->acceptJson()
                ->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false])
                ->get($url, $query);
            if (! $response->successful() || strlen($response->body()) > 1048576) {
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

    private function identifier(mixed $id, string $prefix): bool
    {
        return is_string($id) && preg_match('/^'.$prefix.'_[A-Za-z0-9_]{8,240}$/D', $id) === 1;
    }

    private function refuse(): never
    {
        throw FinancialException::withCode('stripe_capture_inconclusive', 'No exact original fully captured, nonrefunded, undisputed Stripe payment was established.');
    }
}
