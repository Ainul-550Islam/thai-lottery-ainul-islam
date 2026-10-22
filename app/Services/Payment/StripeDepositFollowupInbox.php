<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Exceptions\FinancialException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Raw-body Stripe signature boundary. Durable observation only; never an Applied payment webhook. */
final class StripeDepositFollowupInbox
{
    public const TABLE = 'stripe_deposit_followup_inbox';

    public const TYPES = ['charge.refunded', 'charge.refund.updated', 'refund.created', 'refund.updated', 'refund.failed',
        'charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed',
        'charge.dispute.funds_withdrawn', 'charge.dispute.funds_reinstated'];

    public function receive(string $body, ?string $signature): array
    {
        app(DepositCheckoutService::class)->assertTopLevel();
        $this->enabled();
        if ($body === '' || strlen($body) > 1048576 || ! is_string($signature) || strlen($signature) > 8192) {
            $this->refuse('stripe_followup_signature');
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signature) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                $this->refuse('stripe_followup_signature');
            }
            if ($pair[0] === 't') {
                if ($timestamp !== null || preg_match('/^[1-9][0-9]{0,11}$/D', $pair[1]) !== 1) {
                    $this->refuse('stripe_followup_signature');
                }
                $timestamp = (int) $pair[1];
            } elseif ($pair[0] === 'v1') {
                if (preg_match('/^[a-f0-9]{64}$/D', $pair[1]) !== 1 || count($signatures) >= 16) {
                    $this->refuse('stripe_followup_signature');
                }
                $signatures[] = $pair[1];
            }
        }
        $received = now()->getTimestamp();
        $secret = config('payment.deposit_review.stripe.webhook_secret');
        if (! is_string($secret) || preg_match('/^whsec_\S{8,500}$/D', $secret) !== 1
            || $timestamp === null || abs($received - $timestamp) > 300) {
            $this->refuse('stripe_followup_signature');
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        if (! array_filter($signatures, fn ($value) => hash_equals($expected, $value))) {
            $this->refuse('stripe_followup_signature');
        }
        $payload = $this->payload($body, $received);
        $scope = app(DepositProviderAccountService::class)->current('stripe');
        $configuration = app(DepositCheckoutService::class)->stripeConfigurationFingerprint();
        $eventKey = hash('sha256', json_encode([$scope, $configuration, $payload['id']], JSON_THROW_ON_ERROR));
        $bytes = json_encode(['account_scope' => $scope, 'configuration_hash' => $configuration,
            'event_id' => $payload['id'], 'event_type' => $payload['type'], 'body' => $body,
            'received_epoch' => $received, 'signature_timestamp' => $timestamp], JSON_THROW_ON_ERROR);
        if (strlen($bytes) > 1048576) {
            $this->refuse();
        }

        return DB::transaction(function () use ($eventKey, $scope, $configuration, $payload, $bytes, $received, $body): array {
            $this->enabled();
            $inserted = DB::table(self::TABLE)->insertOrIgnore(['event_key' => $eventKey, 'account_scope' => $scope,
                'configuration_hash' => $configuration, 'event_id' => $payload['id'], 'event_type' => $payload['type'],
                'response_cipher' => Crypt::encryptString($bytes), 'response_hash' => hash('sha256', $bytes),
                'received_epoch' => $received, 'created_at' => now()]);
            $row = DB::table(self::TABLE)->where('event_key', $eventKey)->lockForUpdate()->sole();
            $source = $this->read($row);
            // Same event ID with changed raw bytes is a conflict, not a harmless delivery replay.
            if ($source['body_hash'] !== hash('sha256', $body)) {
                $this->refuse('stripe_followup_conflict');
            }

            return ['inbox_id' => (int) $row->id, 'replayed' => $inserted === 0];
        }, 3);
    }

    public function read(object $row): array
    {
        try {
            $bytes = Crypt::decryptString($row->response_cipher);
            $value = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($value) || ! hash_equals($row->response_hash, hash('sha256', $bytes))
                || ($value['account_scope'] ?? null) !== $row->account_scope
                || ($value['configuration_hash'] ?? null) !== $row->configuration_hash
                || ($value['event_id'] ?? null) !== $row->event_id || ($value['event_type'] ?? null) !== $row->event_type
                || ($value['received_epoch'] ?? null) !== (int) $row->received_epoch
                || ! is_int($value['signature_timestamp'] ?? null)
                || abs($value['received_epoch'] - $value['signature_timestamp']) > 300 || ! is_string($value['body'] ?? null)
                || $row->event_key !== hash('sha256', json_encode([$row->account_scope, $row->configuration_hash, $row->event_id], JSON_THROW_ON_ERROR))) {
                $this->refuse();
            }
            $payload = $this->payload($value['body'], $value['received_epoch']);
            if ($payload['id'] !== $row->event_id || $payload['type'] !== $row->event_type) {
                $this->refuse();
            }

            return ['inbox_id' => (int) $row->id, 'event_id' => $row->event_id, 'event_type' => $row->event_type,
                'body_hash' => hash('sha256', $value['body']), 'payload' => $payload];
        } catch (\Throwable) {
            $this->refuse();
        }
    }

    private function payload(string $body, int $received): array
    {
        try {
            $p = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->refuse();
        }
        $key = config('payment.gateways.stripe.secret');
        if (! is_array($p) || ! is_string($key) || preg_match('/^(?:sk|rk)_(test|live)_\S+$/D', $key, $mode) !== 1
            || ($p['object'] ?? null) !== 'event' || ! is_string($p['id'] ?? null)
            || preg_match('/^evt_[A-Za-z0-9_]{8,240}$/D', $p['id']) !== 1 || ! in_array($p['type'] ?? null, self::TYPES, true)
            || ! is_int($p['created'] ?? null) || $p['created'] < 1 || $p['created'] > $received + 300
            || ($p['livemode'] ?? null) !== ($mode[1] === 'live') || isset($p['account'])
            || ! is_array($p['data']['object'] ?? null)) {
            $this->refuse();
        }

        return $p;
    }

    private function enabled(): void
    {
        if (! config('payment.deposit_review.stripe.enabled', false)) {
            $this->refuse('stripe_review_disabled');
        }
    }

    private function refuse(string $code = 'stripe_followup_unproven'): never
    {
        throw FinancialException::withCode($code, 'Stripe follow-up signature, event identity, mode or encrypted custody could not be established.');
    }
}
