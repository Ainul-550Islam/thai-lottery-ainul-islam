<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Exceptions\FinancialException;
use Illuminate\Support\Facades\Http;

/** A narrow authenticated full-refund proof. Fixed GET endpoints, no retries/redirects/provider writes. */
final class StripeRefundRecoveryLookup
{
    public function read(array $original): array
    {
        app(DepositCheckoutService::class)->assertTopLevel();
        $r = $this->get('refunds/'.$original['refund']);
        $c = $this->get('charges/'.$original['references'][2]);
        $p = $this->get('payment_intents/'.$original['references'][1]);
        $list = $this->get('refunds', ['charge' => $original['references'][2], 'limit' => 2]);
        $proof = ['refund' => $r, 'charge' => $c, 'intent' => $p, 'list' => $list];
        $this->validate($proof, $original);

        return $proof;
    }

    public function validate(array $proof, array $o): void
    {
        $r = $proof['refund'] ?? [];
        $c = $proof['charge'] ?? [];
        $p = $proof['intent'] ?? [];
        $list = $proof['list'] ?? [];
        $this->refund($r, $o);
        if (($list['object'] ?? null) !== 'list' || ($list['has_more'] ?? null) !== false
            || ! is_array($list['data'] ?? null) || ! array_is_list($list['data']) || count($list['data']) !== 1) {
            $this->refuse();
        }
        $this->refund($list['data'][0], $o);
        if (($c['object'] ?? null) !== 'charge' || ($c['id'] ?? null) !== $o['references'][2]
            || ($c['payment_intent'] ?? null) !== $o['references'][1] || ($c['status'] ?? null) !== 'succeeded'
            || ($c['paid'] ?? null) !== true || ($c['captured'] ?? null) !== true || ($c['refunded'] ?? null) !== true
            || ($c['disputed'] ?? null) !== false || ($p['object'] ?? null) !== 'payment_intent'
            || ($p['id'] ?? null) !== $o['references'][1] || ($p['latest_charge'] ?? null) !== $o['references'][2]
            || ($p['status'] ?? null) !== 'succeeded' || ($p['amount_capturable'] ?? null) !== 0) {
            $this->refuse();
        }
        foreach ([[$c, ['amount', 'amount_captured', 'amount_refunded']], [$p, ['amount', 'amount_received']]] as [$obj, $fields]) {
            if (($obj['currency'] ?? null) !== $o['currency'] || ($obj['livemode'] ?? null) !== $o['live']) {
                $this->refuse();
            }
            foreach ($fields as $field) {
                if (! is_int($obj[$field] ?? null) || (string) $obj[$field] !== $o['minor']) {
                    $this->refuse();
                }
            }
            foreach (['transfer_data', 'on_behalf_of', 'application_fee_amount', 'application_fee', 'transfer', 'review', 'failure_code'] as $field) {
                if (($obj[$field] ?? null) !== null) {
                    $this->refuse();
                }
            }
        }
    }

    public function refund(mixed $r, array $o): void
    {
        if (! is_array($r) || ($r['object'] ?? null) !== 'refund' || ($r['id'] ?? null) !== $o['refund']
            || ($r['status'] ?? null) !== 'succeeded' || ! is_int($r['amount'] ?? null)
            || (string) $r['amount'] !== $o['minor'] || ($r['currency'] ?? null) !== $o['currency']
            || ($r['charge'] ?? null) !== $o['references'][2] || ($r['payment_intent'] ?? null) !== $o['references'][1]
            || ! is_int($r['created'] ?? null) || $r['created'] < $o['dispatched'] - 300 || $r['created'] > now()->timestamp + 300
            || ($r['source_transfer_reversal'] ?? null) !== null || ($r['transfer_reversal'] ?? null) !== null
            || ($r['failure_reason'] ?? null) !== null) {
            $this->refuse();
        }
    }

    private function get(string $path, array $query = []): array
    {
        app(DepositCheckoutService::class)->assertTopLevel();
        try {
            $response = Http::withToken((string) config('payment.gateways.stripe.secret'))->acceptJson()
                ->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false])
                ->get('https://api.stripe.com/v1/'.$path, $query);
            if (! $response->successful() || strlen($response->body()) > 1048576) {
                $this->refuse();
            }
            $value = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($value) || array_is_list($value)) {
                $this->refuse();
            }

            return $value;
        } catch (\Throwable) {
            $this->refuse();
        }
    }

    private function refuse(): never
    {
        throw FinancialException::withCode('stripe_refund_provider_unproven', 'A sole succeeded full refund, original charge and PaymentIntent could not be proven. No provider write was attempted.');
    }
}
