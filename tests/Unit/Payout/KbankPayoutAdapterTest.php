<?php

declare(strict_types=1);

namespace Tests\Unit\Payout;

use App\DTOs\Payment\PayoutInstruction;
use App\Enums\ThaiBankPayoutState;
use App\Exceptions\FinancialException;
use App\Services\Payout\ThaiBank\KbankPayoutAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class KbankPayoutAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Config::set('thai_payout.enabled', true);
        Config::set('thai_payout.callback_tolerance_seconds', 300);
        Config::set('thai_payout.banks.kbank', [
            'enabled' => true,
            'contract_confirmed' => true,
            'base_url' => 'https://api.kbank.test',
            'token_path' => '/v2/oauth/token',
            'transfer_path' => '/v1/fundtransfer/fundtransfer',
            'status_path' => '/v1/fundtransfer/inqtxnstatus',
            'client_id' => 'consumer-id',
            'client_secret' => 'consumer-secret',
            'webhook_secret' => 'webhook-secret',
            'source_account' => 'source-account-id',
            'timeout_seconds' => 5,
            'client_id_header' => 'x-ibm-client-id',
            'request_id_header' => 'X-Request-ID',
            'idempotency_header' => 'Idempotency-Key',
            'callback_signature_header' => 'X-Signature',
            'callback_timestamp_header' => 'X-Timestamp',
        ]);
    }

    public function test_uses_oauth_client_credentials_and_kbank_transfer_contract_fields(): void
    {
        Http::fake([
            'https://api.kbank.test/v2/oauth/token' => Http::response(['access_token' => 'kbank-token', 'expires_in' => 1800], 200),
            '*' => Http::response(['data' => ['status' => 'ACCEPTED', 'transactionId' => 'KBANK-TXN-1']], 200),
        ]);
        $adapter = new KbankPayoutAdapter;
        $outcome = $adapter->initiateTransfer($this->instruction('WD-KB-001'));

        self::assertSame(ThaiBankPayoutState::Accepted, $outcome->state);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/v2/oauth/token')
            && $request->hasHeader('Authorization')
            && $request->data()['grant_type'] === 'client_credentials');
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/v1/fundtransfer/fundtransfer')
            && $request->hasHeader('x-ibm-client-id', 'consumer-id')
            && $request->hasHeader('Idempotency-Key', 'WD-KB-001')
            && $request['debitAccountNumber'] === 'source-account-id'
            && $request['creditAccountBankCode'] === '014'
            && $request['transactionReference'] === 'WD-KB-001');
    }

    public function test_status_is_posted_and_cached_token_is_reused(): void
    {
        Http::fake(['*' => Http::response(['data' => ['status' => 'SETTLED', 'transactionId' => 'KBANK-TXN-2', 'amount' => '50.00', 'currency' => 'THB']], 200)]);
        Cache::put(KbankPayoutAdapter::tokenCacheKey('consumer-id'), 'kbank-token', now()->addHour());
        $outcome = (new KbankPayoutAdapter)->queryStatus('KBANK-TXN-2');

        self::assertSame(ThaiBankPayoutState::Settled, $outcome->state);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/v1/fundtransfer/inqtxnstatus')
            && $request->method() === 'POST'
            && $request['transactionReference'] === 'KBANK-TXN-2');
    }

    public function test_maps_definitive_client_failure_and_server_or_connection_failure(): void
    {
        Cache::put(KbankPayoutAdapter::tokenCacheKey('consumer-id'), 'kbank-token', now()->addHour());
        $adapter = new KbankPayoutAdapter;
        Http::fake(['*' => Http::sequence()
            ->push(['error' => 'declined'], 422)
            ->push([
                'data' => ['status' => 'SETTLED', 'transactionId' => 'KBANK-REDIRECT', 'amount' => '50.00', 'currency' => 'THB'],
            ], 302, ['Location' => 'https://untrusted.example/'])
            ->push(['error' => 'server'], 503)
            ->pushFailedConnection('network detail')]);
        self::assertSame(ThaiBankPayoutState::Rejected, $adapter->initiateTransfer($this->instruction('WD-KB-002'))->state);

        self::assertSame(ThaiBankPayoutState::Uncertain, $adapter->initiateTransfer($this->instruction('WD-KB-REDIRECT'))->state);

        self::assertSame(ThaiBankPayoutState::Uncertain, $adapter->initiateTransfer($this->instruction('WD-KB-003'))->state);

        self::assertSame(ThaiBankPayoutState::Uncertain, $adapter->initiateTransfer($this->instruction('WD-KB-004'))->state);
        Http::assertSentCount(4);
    }

    public function test_status_query_client_errors_remain_uncertain(): void
    {
        Cache::put(KbankPayoutAdapter::tokenCacheKey('consumer-id'), 'kbank-token', now()->addHour());
        Http::fake(['*' => Http::response(['message' => 'not found'], 404)]);

        $outcome = (new KbankPayoutAdapter)->queryStatus('KBANK-UNKNOWN-REF');

        self::assertSame(ThaiBankPayoutState::Uncertain, $outcome->state);
        self::assertSame(404, $outcome->rawSanitised['http_status']);
    }

    public function test_disabled_payout_lane_refuses_transfers_and_status_queries_without_network_calls(): void
    {
        Config::set('thai_payout.enabled', false);
        Http::fake();
        $adapter = new KbankPayoutAdapter;

        self::assertSame(ThaiBankPayoutState::Uncertain, $adapter->initiateTransfer($this->instruction('WD-KB-DISABLED'))->state);
        self::assertSame(ThaiBankPayoutState::Uncertain, $adapter->queryStatus('WD-KB-DISABLED')->state);
        Http::assertNothingSent();

        Config::set('thai_payout.enabled', true);
        Config::set('thai_payout.banks.kbank.enabled', false);
        self::assertSame(ThaiBankPayoutState::Uncertain, $adapter->initiateTransfer($this->instruction('WD-KB-BANK-DISABLED'))->state);
        Http::assertNothingSent();
    }

    public function test_verifies_timestamped_callback_hmac(): void
    {
        $adapter = new KbankPayoutAdapter;
        $body = '{"reference":"WD-KB-005","state":"REJECTED"}';
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'webhook-secret');
        self::assertTrue($adapter->verifyCallback($body, ['X-Signature' => 'sha256='.$signature, 'X-Timestamp' => $timestamp]));
        self::assertFalse($adapter->verifyCallback($body, ['X-Signature' => $signature, 'X-Timestamp' => 'invalid']));
    }

    public function test_rejects_callback_identifiers_that_exceed_persisted_limits(): void
    {
        $adapter = new KbankPayoutAdapter;
        foreach ([
            ['reference' => str_repeat('W', 65), 'state' => 'ACCEPTED'],
            ['reference' => 'WD-KB-VALID', 'state' => 'ACCEPTED', 'providerReference' => str_repeat('P', 192)],
            ['reference' => 'WD-KB-VALID', 'state' => str_repeat('S', 65)],
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
