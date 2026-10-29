<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\PaymentWebhookData;
use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\GatewayIntegrationStatus;
use App\Enums\PaymentMethod;
use App\Enums\WebhookEventType;
use App\Exceptions\FinancialException;
use App\Exceptions\PaymentWebhookException;
use App\Jobs\Payment\ProcessPaymentWebhookJob;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Services\Finance\Money;
use App\Services\Operations\ProviderReadinessService;
use App\Services\Payment\DepositCheckoutService;
use App\Services\Payment\FinancialProviderReferenceAliasService;
use App\Services\Payment\Drivers\BkashGateway;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\PaymentWebhookVerificationService;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as OutboundRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/** Synthetic HTTP contract tests; not evidence of a live bKash merchant integration. */
final class BkashOriginalCheckoutProofTest extends PaymentTestCase
{
    private const BASE = 'https://bkash.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'payment.currency.default' => 'BDT',
            'payment.country' => 'BD',
            'payment.markets.bkash' => ['currencies' => ['BDT'], 'countries' => ['BD']],
            'payment.gateways.bkash.enabled' => true,
            'payment.gateways.bkash.supports_deposit' => true,
            'payment.gateways.bkash.supported_currencies' => ['BDT'],
            'payment.gateways.bkash.username' => 'synthetic-user',
            'payment.gateways.bkash.password' => 'synthetic-password',
            'payment.gateways.bkash.base_url' => self::BASE,
        ]);
    }

    /** @return array{user: \App\Models\User, wallet: \App\Models\Wallet, deposit: Deposit, payment: Payment} */
    private function fixture(string $suffix): array
    {
        $f = $this->createPlayer('0.00', Currency::BDT);
        $paymentId = 'PAYMENT-'.$suffix;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (OutboundRequest $request) use ($paymentId) {
            $this->assertSame(0, DB::transactionLevel(), 'No application SQL transaction may span a provider request.');
            if (str_ends_with($request->url(), '/tokenized/checkout/token/grant')) {
                return Http::response(['statusCode' => '0000', 'id_token' => 'synthetic-token']);
            }
            if (str_ends_with($request->url(), '/tokenized/checkout/create')) {
                $this->assertSame('BDT', $request['currency']);
                $this->assertSame('100.00', $request['amount']);
                return Http::response(['statusCode' => '0000', 'paymentID' => $paymentId,
                    'bkashURL' => 'https://checkout.example.test/synthetic']);
            }
            $this->fail('Unexpected provider request: '.$request->url());
        });
        $out = app(DepositCheckoutService::class)->execute(
            $f['wallet'], Money::of('100.00', Currency::BDT), PaymentMethod::Bkash, 'bkash-checkout-'.$suffix, [], app(BkashGateway::class),
        );
        $f['deposit'] = $out['deposit'];
        $f['payment'] = $out['payment'];
        $this->assertSame($paymentId, $f['payment']->gateway_reference);
        Http::swap(new Factory);
        Http::preventStrayRequests();

        return $f;
    }

    /** @param array<string, mixed> $override */
    private function fakeStatus(array $f, string $transactionId, array $override = [], bool $needsExecute = false, ?\Closure $duringQuery = null): void
    {
        $queries = 0;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (OutboundRequest $request) use ($f, $transactionId, $override, $needsExecute, $duringQuery, &$queries) {
            $this->assertSame(0, DB::transactionLevel(), 'Provider HTTP must be outside the owned SQL transaction.');
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            if (str_ends_with($request->url(), '/tokenized/checkout/token/grant')) {
                return Http::response(['statusCode' => '0000', 'id_token' => 'synthetic-token']);
            }
            if (str_ends_with($request->url(), '/tokenized/checkout/payment/status')) {
                $queries++;
                $this->assertSame($f['payment']->gateway_reference, $request['paymentID']);
                if ($duringQuery) {
                    $duringQuery();
                }
                $data = [
                    'statusCode' => '0000',
                    'paymentID' => $f['payment']->gateway_reference,
                    // Query Payment uses merchantInvoice, unlike create/execute.
                    'merchantInvoice' => $f['deposit']->reference_number,
                    'amount' => '100.00',
                    'currency' => 'BDT',
                    'transactionStatus' => $needsExecute && $queries === 1 ? 'Initiated' : 'Completed',
                    'trxID' => $transactionId,
                ];

                return Http::response(array_replace($data, $override));
            }
            if (str_ends_with($request->url(), '/tokenized/checkout/execute')) {
                $this->assertSame('POST', $request->method());
                $this->assertSame($f['payment']->gateway_reference, $request['paymentID']);
                return Http::response(['statusCode' => '0000']);
            }
            $this->fail('Unexpected provider request: '.$request->url());
        });
    }

    /** A provider status response with exact JSON bytes, including malformed aliases. */
    private function fakeRawStatus(array $f, string $amountFields): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (OutboundRequest $request) use ($f, $amountFields) {
            $this->assertSame(0, DB::transactionLevel(), 'Provider HTTP must be outside the owned SQL transaction.');
            if (str_ends_with($request->url(), '/tokenized/checkout/token/grant')) {
                return Http::response(['statusCode' => '0000', 'id_token' => 'synthetic-token']);
            }
            if (str_ends_with($request->url(), '/tokenized/checkout/payment/status')) {
                $body = '{"statusCode":"0000","paymentID":'.json_encode($f['payment']->gateway_reference, JSON_THROW_ON_ERROR)
                    .',"merchantInvoice":'.json_encode($f['deposit']->reference_number, JSON_THROW_ON_ERROR)
                    .','.$amountFields.',"currency":"BDT","transactionStatus":"Completed","trxID":"TRANSACTION-RAW"}';

                return Http::response($body, 200, ['Content-Type' => 'application/json']);
            }
            $this->fail('Unexpected provider request: '.$request->url());
        });
    }

    /** @param array<string, mixed> $override */
    private function signedCallback(array $f, array $override = []): Request
    {
        $body = json_encode(array_replace([
            'paymentID' => $f['payment']->gateway_reference,
            'merchantInvoiceNumber' => $f['deposit']->reference_number,
            'transactionStatus' => 'SUCCESS',
            // An attacker-controlled body amount is deliberately wrong. Only
            // the provider's query amount may be used for wallet credit.
            'amount' => '999.99', 'currency' => 'BDT',
        ], $override), JSON_THROW_ON_ERROR);
        $request = Request::create('/webhook/bkash', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $request->headers->set('X-Bkash-Signature', hash_hmac('sha256', $body, self::BKASH_WEBHOOK_SECRET));

        return $request;
    }

    /** Exercise the isolated adapter, not the disabled public ingress. */
    private function runSyntheticPrototype(Request $request): \App\DTOs\Payment\PaymentProcessingResult
    {
        $driver = app(BkashGateway::class);
        $this->assertTrue($driver->verifyWebhookSignature($request));
        $request->attributes->set('webhook.signature_verified', true);

        return $this->webhookService()->processWebhookPayload($driver->parseWebhook($request));
    }

    private function assertNoCredit(array $f): void
    {
        $this->assertSame('0.00', (string) $f['wallet']->fresh()->balance);
        $this->assertSame(DepositStatus::Pending, $f['deposit']->fresh()->status);
        $this->assertNull($f['deposit']->fresh()->financial_transaction_id);
    }

    public function test_authoritative_amount_and_distinct_ids_credit_once_with_original_proof(): void
    {
        $f = $this->fixture('ONE');
        $this->fakeStatus($f, 'TRANSACTION-ONE');
        $result = $this->runSyntheticPrototype($this->signedCallback($f));

        $this->assertTrue($result->success);
        $this->assertSame('100.00', (string) $f['wallet']->fresh()->balance);
        $this->assertSame(DepositStatus::Confirmed, $f['deposit']->fresh()->status);
        $this->assertSame('PAYMENT-ONE', $f['payment']->fresh()->gateway_reference);
        $this->assertSame('PAYMENT-ONE', $f['deposit']->fresh()->provider_reference);
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame(2, LedgerEntry::query()->count());
        $this->assertDatabaseCount(FinancialProviderReferenceAliasService::TABLE, 1);

        // A process/cache restart cannot turn an already bound provider
        // transaction into a second wallet credit.
        Cache::flush();
        $this->assertTrue($this->runSyntheticPrototype($this->signedCallback($f))->success);
        $this->assertSame('100.00', (string) $f['wallet']->fresh()->balance);
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertDatabaseCount(FinancialProviderReferenceAliasService::TABLE, 1);
    }

    public static function ambiguousStatusAmounts(): array
    {
        return [
            'sub-cent amount rounded by json decode' => ['"amount":100.00000000000000001'],
            'scientific notation' => ['"amount":1e2'],
            'excess fractional digits' => ['"amount":100.000'],
            'duplicate amount' => ['"amount":99.99,"amount":100.00'],
            'escaped duplicate amount' => ['"amount":99.99,"am\\u006funt":100.00'],
        ];
    }

    #[DataProvider('ambiguousStatusAmounts')]
    public function test_ambiguous_provider_query_amount_never_credits_the_synthetic_prototype(string $amountFields): void
    {
        $f = $this->fixture('EXACT');
        $this->fakeRawStatus($f, $amountFields);
        try {
            $this->runSyntheticPrototype($this->signedCallback($f));
            $this->fail('An ambiguous query response must not authorize wallet credit.');
        } catch (FinancialException $exception) {
            $this->assertSame('bkash_payment_verification_failed', $exception->errorCode());
        }
        $this->assertNoCredit($f);
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public static function duplicateCallbackFields(): array
    {
        return [
            'payment ID' => ['paymentID', 'OTHER-PAYMENT'],
            'invoice' => ['merchantInvoiceNumber', 'OTHER-INVOICE'],
            'status' => ['transactionStatus', 'FAILED'],
        ];
    }

    #[DataProvider('duplicateCallbackFields')]
    public function test_duplicate_callback_facts_do_not_dispatch_a_query_or_credit(string $field, string $decoy): void
    {
        $f = $this->fixture('CALLBACK-DUP');
        $this->fakeStatus($f, 'TRANSACTION-CALLBACK-DUP');
        $valid = json_encode([
            'paymentID' => $f['payment']->gateway_reference,
            'merchantInvoiceNumber' => $f['deposit']->reference_number,
            'transactionStatus' => 'SUCCESS', 'amount' => '100.00', 'currency' => 'BDT',
        ], JSON_THROW_ON_ERROR);
        $body = '{'.json_encode($field, JSON_THROW_ON_ERROR).':'.json_encode($decoy, JSON_THROW_ON_ERROR).','.substr($valid, 1);
        $request = Request::create('/webhook/bkash', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $request->headers->set('X-Bkash-Signature', hash_hmac('sha256', $body, self::BKASH_WEBHOOK_SECRET));

        try {
            $this->runSyntheticPrototype($request);
            $this->fail('Duplicate callback identity or status must be refused before provider dispatch.');
        } catch (FinancialException $exception) {
            $this->assertSame('bkash_payment_verification_failed', $exception->errorCode());
        }
        $this->assertNoCredit($f);
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
        Http::assertNothingSent();
    }

    public function test_exact_numeric_provider_query_amount_credits_the_synthetic_prototype_once(): void
    {
        $f = $this->fixture('NUMERIC');
        $this->fakeRawStatus($f, '"amount":100.00');
        $result = $this->runSyntheticPrototype($this->signedCallback($f));
        $this->assertTrue($result->success);
        $this->assertSame('100.00', (string) $f['wallet']->fresh()->balance);
        $this->assertSame(DepositStatus::Confirmed, $f['deposit']->fresh()->status);
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame(2, LedgerEntry::query()->count());
    }

    public function test_authorized_unfinished_checkout_uses_documented_execute_body_then_queries_again(): void
    {
        $f = $this->fixture('EXECUTE');
        $this->fakeStatus($f, 'TRANSACTION-EXECUTE', ['verificationStatus' => 'Complete'], true);
        $result = $this->runSyntheticPrototype($this->signedCallback($f));
        $this->assertTrue($result->success);
        $this->assertSame('100.00', (string) $f['wallet']->fresh()->balance);
        Http::assertSentCount(4); // grant, query, execute, query
    }

    public static function incorrectProviderFacts(): array
    {
        return [
            'amount' => [['amount' => '99.00']],
            'invalid amount' => [['amount' => '1e2']],
            'currency' => [['currency' => 'USD']],
            'payment id' => [['paymentID' => 'OTHER-PAYMENT']],
            'invoice' => [['merchantInvoice' => 'OTHER-INVOICE']],
            'conflicting invoice aliases' => [['merchantInvoiceNumber' => 'OTHER-INVOICE']],
            'provider error' => [['statusCode' => '2002']],
            'missing transaction' => [['trxID' => '']],
            'unknown status' => [['transactionStatus' => 'Failed']],
            'unverified customer' => [['transactionStatus' => 'Initiated', 'verificationStatus' => 'Incomplete']],
            'missing customer verification' => [['transactionStatus' => 'Initiated']],
            'null customer verification' => [['transactionStatus' => 'Authorized', 'verificationStatus' => null]],
            'conflicting customer verification' => [['transactionStatus' => 'Initiated', 'verificationStatus' => 'Complete', 'userVerificationStatus' => 'Incomplete']],
        ];
    }

    #[DataProvider('incorrectProviderFacts')]
    public function test_provider_mismatch_cannot_execute_or_credit(array $override): void
    {
        $f = $this->fixture('BAD');
        $this->fakeStatus($f, 'TRANSACTION-BAD', $override);
        try {
            $this->runSyntheticPrototype($this->signedCallback($f));
            $this->fail('Mismatched provider facts must be refused.');
        } catch (FinancialException $exception) {
            $this->assertSame('bkash_payment_verification_failed', $exception->errorCode());
        }
        $this->assertNoCredit($f);
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertDatabaseCount(FinancialProviderReferenceAliasService::TABLE, 0);
        Http::assertNotSent(fn (OutboundRequest $request) => str_ends_with($request->url(), '/tokenized/checkout/execute'));
    }

    public function test_wrong_original_payment_id_is_refused_before_any_network_request(): void
    {
        $f = $this->fixture('LOCAL');
        try {
            $this->runSyntheticPrototype($this->signedCallback($f, ['paymentID' => 'UNOWNED-PAYMENT']));
            $this->fail('An unrelated payment must not be queried or executed.');
        } catch (FinancialException $exception) {
            $this->assertSame('bkash_payment_verification_failed', $exception->errorCode());
        }
        Http::assertNothingSent();
        $this->assertNoCredit($f);
    }

    public function test_checkout_tampering_during_provider_query_is_rechecked_before_credit(): void
    {
        $f = $this->fixture('TAMPER');
        $this->fakeStatus($f, 'TRANSACTION-TAMPER', duringQuery: function () use ($f): void {
            DB::table(DepositCheckoutService::TABLE)->where('deposit_id', $f['deposit']->id)->update(['configuration_hash' => str_repeat('0', 64)]);
        });
        $result = $this->runSyntheticPrototype($this->signedCallback($f));
        $this->assertFalse($result->success);
        $this->assertNoCredit($f);
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertDatabaseCount(FinancialProviderReferenceAliasService::TABLE, 0);
    }

    public function test_same_transaction_id_cannot_credit_two_original_checkouts(): void
    {
        $first = $this->fixture('FIRST');
        $second = $this->fixture('SECOND');
        $this->fakeStatus($first, 'TRANSACTION-SHARED');
        $this->assertTrue($this->runSyntheticPrototype($this->signedCallback($first))->success);
        $this->fakeStatus($second, 'TRANSACTION-SHARED');
        $this->assertFalse($this->runSyntheticPrototype($this->signedCallback($second))->success);
        $this->assertSame('100.00', (string) $first['wallet']->fresh()->balance);
        $this->assertNoCredit($second);
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertDatabaseCount(FinancialProviderReferenceAliasService::TABLE, 1);
        $this->assertDatabaseCount('financial_provider_reference_owners', 1);
    }

    public function test_one_checkout_cannot_rotate_its_owned_transaction_alias(): void
    {
        $f = $this->fixture('ROTATE');
        $attempt = DB::table(DepositCheckoutService::TABLE)->where('deposit_id', $f['deposit']->id)->first();
        DB::transaction(fn () => app(FinancialProviderReferenceAliasService::class)
            ->bind($f['deposit'], $f['payment'], $attempt, 'SYNTHETIC-ALIAS-ONE'));
        try {
            DB::transaction(fn () => app(FinancialProviderReferenceAliasService::class)
                ->bind($f['deposit'], $f['payment'], $attempt, 'SYNTHETIC-ALIAS-TWO'));
            $this->fail('One checkout cannot claim a second transaction alias.');
        } catch (FinancialException $exception) {
            $this->assertSame('outbound_reference_ownership_conflict', $exception->errorCode());
        }
        $this->assertDatabaseCount(FinancialProviderReferenceAliasService::TABLE, 1);
        $this->assertSame(hash('sha256', 'SYNTHETIC-ALIAS-ONE'), DB::table(FinancialProviderReferenceAliasService::TABLE)->sole()->reference_hash);
        $this->assertNoCredit($f);
    }

    public function test_transaction_alias_cannot_reuse_another_checkouts_primary_payment_id(): void
    {
        $first = $this->fixture('PRIMARY');
        $second = $this->fixture('ALIAS');
        $this->fakeStatus($first, 'TRANSACTION-PRIMARY');
        $this->assertTrue($this->runSyntheticPrototype($this->signedCallback($first))->success);
        $this->fakeStatus($second, 'PAYMENT-PRIMARY');
        $this->assertFalse($this->runSyntheticPrototype($this->signedCallback($second))->success);
        $this->assertNoCredit($second);
        $this->assertDatabaseCount('financial_provider_reference_owners', 1);
        $this->assertDatabaseCount(FinancialProviderReferenceAliasService::TABLE, 1);
    }

    public function test_unverified_callback_contract_keeps_public_capability_and_ingress_closed(): void
    {
        $readiness = app(ProviderReadinessService::class)->gateway('bkash', 'deposit');
        $this->assertFalse($readiness->ready);
        $this->assertSame(GatewayIntegrationStatus::PartiallyImplemented, $readiness->status);
        $this->assertContains('bkash_callback_authentication_unverified', $readiness->issues);
        $this->assertFalse(app(PaymentGatewayManager::class)->isDepositCapable('bkash'));
        try {
            app(PaymentGatewayManager::class)->depositDriver(PaymentMethod::Bkash, Currency::BDT);
            $this->fail('Configured credentials cannot manufacture an authenticated provider contract.');
        } catch (FinancialException $exception) {
            $this->assertSame('payment_gateway_not_ready', $exception->errorCode());
        }

        $f = $this->fixture('CLOSED');
        $result = $this->webhookService()->handleWebhookRequest('bkash', $this->signedCallback($f));
        $this->assertFalse($result->success);
        $this->assertSame('bkash_callback_authentication_unverified', $result->metadata['error_code']);
        $this->assertNoCredit($f);
        Http::assertNothingSent();
    }

    public function test_previously_queued_bkash_dto_cannot_bypass_closed_ingress(): void
    {
        $f = $this->fixture('QUEUE');
        $paymentId = $f['payment']->gateway_reference;
        $transactionId = 'TRANSACTION-QUEUE';
        $payload = new WebhookPayload('bkash', FinancialProviderReferenceAliasService::bkashEventId($paymentId, $transactionId),
            WebhookEventType::DepositSuccess, $paymentId, $f['deposit']->reference_number, '100.00', Currency::BDT, true,
            metadata: ['paymentID' => $paymentId, 'trxID' => $transactionId]);
        $result = (new ProcessPaymentWebhookJob($payload))->handle($this->webhookService());
        $this->assertFalse($result->success);
        $this->assertSame('bkash_callback_authentication_unverified', $result->metadata['error_code']);
        $this->assertNoCredit($f);
        Http::assertNothingSent();
    }

    public function test_missing_webhook_secret_does_not_fall_back_to_merchant_app_secret(): void
    {
        config(['payment.gateways.bkash.webhook_secret' => '']);
        $request = Request::create('/webhook/bkash', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        $request->headers->set('X-Bkash-Signature', hash_hmac('sha256', '{}', self::BKASH_WEBHOOK_SECRET));
        $this->assertFalse(app(BkashGateway::class)->verifyWebhookSignature($request));
    }

    public function test_generic_hmac_envelope_cannot_bypass_the_provider_specific_verification(): void
    {
        $f = $this->fixture('ENVELOPE');
        $payload = ['id' => 'evt-synthetic', 'provider' => 'bkash', 'status' => 'succeeded',
            'reference' => $f['deposit']->reference_number, 'amount' => '100.00', 'currency' => 'BDT'];
        $signature = app(PaymentWebhookVerificationService::class)->expectedSignatureFor('bkash', $payload);
        $envelope = PaymentWebhookData::fromInput('bkash', 'evt-synthetic', 'payment.success', $signature, now()->toIso8601String(), $payload);
        try {
            $this->webhookService()->processEnvelope($envelope);
            $this->fail('Generic HMAC evidence must not bypass a bKash authoritative query.');
        } catch (PaymentWebhookException $exception) {
            $this->assertSame(PaymentWebhookException::CODE_MALFORMED, $exception->errorCode());
        }
        $this->assertNoCredit($f);
        Http::assertNothingSent();
    }
}
