<?php

declare(strict_types=1);

namespace Tests\Unit\Payout;

use App\DTOs\Payment\PayoutInstruction;
use App\DTOs\Payment\PayoutOutcome;
use App\Enums\ThaiBankPayoutState;
use App\Exceptions\FinancialException;
use App\Services\Payout\ThaiBank\ScbPayoutAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ScbPayoutAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Config::set('thai_payout.enabled', true);
        Config::set('thai_payout.callback_tolerance_seconds', 300);
        Config::set('thai_payout.banks.scb', [
            'enabled' => true,
            'contract_confirmed' => true,
            'status_method' => 'GET',
            'base_url' => 'https://api.scb.test',
            'token_path' => '/v1/oauth/token',
            'transfer_path' => '/v1/approved/credit-transfer',
            'status_path' => '/v1/approved/transfers/{reference}',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'resource_owner_id' => 'owner-id',
            'webhook_secret' => 'webhook-secret',
            'source_account' => 'source-account-id',
            'timeout_seconds' => 5,
            'resource_owner_header' => 'resourceOwnerId',
            'request_id_header' => 'requestUId',
            'idempotency_header' => 'Idempotency-Key',
            'channel_header' => 'Channel',
            'channel_value' => 'partner',
            'callback_signature_header' => 'X-Signature',
            'callback_timestamp_header' => 'X-Timestamp',
        ]);
    }

    public function test_caches_oauth_token_and_sends_stable_idempotency_reference(): void
    {
        Http::fake([
            'https://api.scb.test/v1/oauth/token' => Http::response(['data' => ['accessToken' => 'access-token', 'expiresIn' => 3600]], 200),
            '*' => Http::response(['data' => ['status' => 'ACCEPTED', 'transactionId' => 'SCB-TXN-1']], 200),
        ]);
        $adapter = new ScbPayoutAdapter;
        $first = $adapter->initiateTransfer($this->instruction('WD-SCB-001'));
        $second = $adapter->initiateTransfer($this->instruction('WD-SCB-002'));

        self::assertSame(ThaiBankPayoutState::Accepted, $first->state);
        self::assertSame('SCB-TXN-1', $first->providerReference);
        self::assertSame(ThaiBankPayoutState::Accepted, $second->state);
        self::assertSame(1, Http::recorded()->filter(fn ($request): bool => str_ends_with($request->url(), '/v1/oauth/token'))->count());
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/v1/approved/credit-transfer')
            && $request->hasHeader('Idempotency-Key', 'WD-SCB-001')
            && $request->hasHeader('resourceOwnerId', 'owner-id')
            && $request['reference'] === 'WD-SCB-001');
    }

    public function test_maps_4xx_to_rejected_and_5xx_or_timeout_to_uncertain(): void
    {
        Cache::put(ScbPayoutAdapter::tokenCacheKey('client-id'), 'access-token', now()->addHour());
        $adapter = new ScbPayoutAdapter;
        Http::fake(['*' => Http::response(['error' => 'declined'], 422)]);
        self::assertSame(ThaiBankPayoutState::Rejected, $adapter->initiateTransfer($this->instruction('WD-SCB-003'))->state);

        Http::fake(['*' => Http::response([
            'data' => ['status' => 'SETTLED', 'transactionId' => 'SCB-REDIRECT', 'amount' => '50.00', 'currency' => 'THB'],
        ], 302, ['Location' => 'https://untrusted.example/'])]);
        self::assertSame(ThaiBankPayoutState::Uncertain, $adapter->initiateTransfer($this->instruction('WD-SCB-REDIRECT'))->state);

        Http::fake(['*' => Http::response(['error' => 'server'], 503)]);
        self::assertSame(ThaiBankPayoutState::Uncertain, $adapter->initiateTransfer($this->instruction('WD-SCB-004'))->state);

        Http::fake(['*' => static function () { throw new ConnectionException('connection detail'); }]);
        $outcome = $adapter->initiateTransfer($this->instruction('WD-SCB-005'));
        self::assertSame(ThaiBankPayoutState::Uncertain, $outcome->state);
        self::assertStringNotContainsString('connection detail', (string) $outcome->message);
    }

    public function test_status_query_http_errors_remain_uncertain_instead_of_releasing_a_hold(): void
    {
        Cache::put(ScbPayoutAdapter::tokenCacheKey('client-id'), 'access-token', now()->addHour());
        Http::fake(['*' => Http::response(['message' => 'not found'], 404)]);

        $outcome = (new ScbPayoutAdapter)->queryStatus('SCB-UNKNOWN-REF');

        self::assertSame(ThaiBankPayoutState::Uncertain, $outcome->state);
        self::assertSame(404, $outcome->rawSanitised['http_status']);
    }

    public function test_disabled_payout_lane_refuses_transfers_and_status_queries_without_network_calls(): void
    {
        Config::set('thai_payout.enabled', false);
        Http::fake();
        $adapter = new ScbPayoutAdapter;

        self::assertSame(ThaiBankPayoutState::Uncertain, $adapter->initiateTransfer($this->instruction('WD-SCB-DISABLED'))->state);
        self::assertSame(ThaiBankPayoutState::Uncertain, $adapter->queryStatus('WD-SCB-DISABLED')->state);
        Http::assertNothingSent();

        Config::set('thai_payout.enabled', true);
        Config::set('thai_payout.banks.scb.enabled', false);
        self::assertSame(ThaiBankPayoutState::Uncertain, $adapter->initiateTransfer($this->instruction('WD-SCB-BANK-DISABLED'))->state);
        Http::assertNothingSent();
    }

    public function test_verifies_timestamped_callback_hmac_and_rejects_stale_timestamps(): void
    {
        $adapter = new ScbPayoutAdapter;
        $body = '{"reference":"WD-SCB-006","state":"SETTLED"}';
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'webhook-secret');
        self::assertTrue($adapter->verifyCallback($body, ['X-Signature' => $signature, 'X-Timestamp' => $timestamp]));
        $stale = (string) (time() - 1000);
        $staleSignature = hash_hmac('sha256', $stale.'.'.$body, 'webhook-secret');
        self::assertFalse($adapter->verifyCallback($body, ['X-Signature' => $staleSignature, 'X-Timestamp' => $stale]));
    }

    public function test_rejects_callback_identifiers_that_exceed_persisted_limits(): void
    {
        $adapter = new ScbPayoutAdapter;
        foreach ([
            ['reference' => str_repeat('W', 65), 'state' => 'ACCEPTED'],
            ['reference' => 'WD-SCB-VALID', 'state' => 'ACCEPTED', 'providerReference' => str_repeat('P', 192)],
            ['reference' => 'WD-SCB-VALID', 'state' => str_repeat('S', 65)],
        ] as $payload) {
            try {
                $adapter->parseCallback(json_encode($payload, JSON_THROW_ON_ERROR));
                self::fail('Oversized callback facts must not be accepted.');
            } catch (FinancialException $exception) {
                self::assertSame('payout_webhook_payload_invalid', $exception->errorCode());
            }
        }
    }

    private function instruction(string $reference): PayoutInstruction
    {
        return new PayoutInstruction($reference, '50.00', 'THB', 'bank_account', '014', '1234567890', 'Example Name', 'Test payout');
    }
}
