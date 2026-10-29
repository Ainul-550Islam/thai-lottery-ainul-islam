<?php

declare(strict_types=1);

namespace Tests\Unit\Payment\Slip;

use App\Exceptions\FinancialException;
use App\Services\Payment\Slip\SlipOkProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SlipOkProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('slip_verification.enabled', true);
        Config::set('slip_verification.providers.slipok.base_url', 'https://api.slipok.com');
        Config::set('slip_verification.providers.slipok.verify_path', '/api/line/apikey/{branch_id}');
        Config::set('slip_verification.providers.slipok.branch_id', 'test-branch');
        Config::set('slip_verification.providers.slipok.api_key', 'unit-test-key');
        Config::set('slip_verification.providers.slipok.authorization_header', 'x-authorization');
        Config::set('slip_verification.providers.slipok.timeout', 4);
    }

    public function test_maps_verified_slip_and_sends_documented_authentication_header(): void
    {
        Http::fake(['*' => Http::response([
            'success' => true,
            'data' => [
                'success' => true,
                'transRef' => 'SLIPOK-REF-1',
                'transTimestamp' => '2026-10-25T10:00:00+07:00',
                'amount' => 88.50,
                'currency' => 'THB',
                'sendingBank' => 'KBANK',
                'receivingBank' => 'SCB',
                'sender' => ['displayName' => 'Sender Name'],
                'receiver' => ['account' => ['value' => '12345678901234'], 'displayName' => 'Receiver Name'],
            ],
        ], 200)]);

        $result = (new SlipOkProvider)->verifyQr('QR-PAYLOAD');

        self::assertSame('88.50', $result->amount);
        self::assertSame('SLIPOK-REF-1', $result->transRef);
        self::assertSame('••••1234', $result->receiverAccountMasked);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.slipok.com/api/line/apikey/test-branch'
            && $request->hasHeader('x-authorization', 'unit-test-key')
            && $request['log'] === true);
    }

    public function test_uploads_a_scanned_png_using_the_documented_files_field(): void
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aT3sAAAAASUVORK5CYII=', true);
        self::assertIsString($bytes);
        $path = tempnam(sys_get_temp_dir(), 'slipok-test-');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        Http::fake(['*' => Http::response([
            'success' => true,
            'data' => [
                'success' => true,
                'transRef' => 'SLIPOK-IMAGE-001',
                'transTimestamp' => '2026-10-25T10:00:00+07:00',
                'amount' => 1,
                'currency' => 'THB',
                'sendingBank' => 'KBANK',
                'receivingBank' => 'SCB',
                'sender' => ['displayName' => 'Sender'],
                'receiver' => ['account' => ['value' => 'xxx1234'], 'displayName' => 'Receiver'],
            ],
        ], 200)]);

        try {
            $result = (new SlipOkProvider)->verifyImage($path);
        } finally {
            unlink($path);
        }

        self::assertSame('SLIPOK-IMAGE-001', $result->transRef);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.slipok.com/api/line/apikey/test-branch'
            && $request->isMultipart()
            && $request->hasFile('files')
            // Multipart converts PHP true to the wire value 1, as in SlipOK's PHP example.
            && preg_match('/name="log"\r\n(?:[^\r\n]+\r\n)*\r\n1\r\n/', $request->body()) === 1);
    }

    public function test_provider_duplicate_code_without_transfer_facts_is_refused_as_duplicate(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'code' => 1012, 'message' => 'duplicate'], 400)]);
        try {
            (new SlipOkProvider)->verifyQr('QR-PAYLOAD');
            self::fail('The provider duplicate code must not be treated as a verifiable transfer.');
        } catch (FinancialException $exception) {
            self::assertSame('slip_duplicate', $exception->errorCode());
        }
        Http::assertSentCount(1);
    }

    public function test_disabled_lane_refuses_without_network_call(): void
    {
        Config::set('slip_verification.enabled', false);
        Http::fake();
        try {
            (new SlipOkProvider)->verifyQr('QR-PAYLOAD');
            self::fail('A disabled slip lane must refuse verification.');
        } catch (FinancialException $exception) {
            self::assertSame('slip_provider_not_configured', $exception->errorCode());
        }
        Http::assertNothingSent();
    }

    public function test_image_over_the_five_mib_limit_is_refused_before_reading_or_network(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'slipok-oversize-');
        self::assertIsString($path);
        $stream = fopen($path, 'wb');
        self::assertIsResource($stream);
        self::assertTrue(ftruncate($stream, 5 * 1024 * 1024 + 1));
        fclose($stream);
        Http::fake();

        try {
            (new SlipOkProvider)->verifyImage($path);
            self::fail('The SlipOK image upload cap must be enforced before reading the image.');
        } catch (FinancialException $exception) {
            self::assertSame('slip_image_too_large', $exception->errorCode());
        } finally {
            unlink($path);
        }
        Http::assertNothingSent();
    }

    public function test_refuses_to_send_provider_credentials_to_an_untrusted_host(): void
    {
        Config::set('slip_verification.providers.slipok.base_url', 'https://untrusted.example');
        Http::fake();

        try {
            (new SlipOkProvider)->verifyQr('QR-PAYLOAD');
            self::fail('SlipOK credentials must not be sent to a non-provider host.');
        } catch (FinancialException $exception) {
            self::assertSame('slip_provider_not_configured', $exception->errorCode());
        }

        Http::assertNothingSent();
    }

    public function test_redirect_response_cannot_be_accepted_even_with_a_success_shaped_body(): void
    {
        Http::fake(['*' => Http::response([
            'success' => true,
            'data' => [
                'success' => true,
                'transRef' => 'SLIPOK-REDIRECT-001',
                'transTimestamp' => '2026-10-25T10:00:00+07:00',
                'amount' => 10.00,
                'currency' => 'THB',
                'sendingBank' => 'KBANK',
                'receivingBank' => 'SCB',
                'receiver' => ['account' => ['value' => '1234567890'], 'displayName' => 'Receiver'],
            ],
        ], 302)]);

        try {
            (new SlipOkProvider)->verifyQr('QR-PAYLOAD');
            self::fail('A non-final redirect response must never verify a slip.');
        } catch (FinancialException $exception) {
            self::assertSame('slip_provider_error', $exception->errorCode());
        }
        Http::assertSentCount(1);
    }

    public static function invalidSuccessFlags(): array
    {
        return [
            'outer false, inner true' => [false, true, 'slip_invalid'],
            'outer string false' => ['false', true, 'slip_provider_error'],
            'outer true, inner false' => [true, false, 'slip_invalid'],
            'inner string false' => [true, 'false', 'slip_provider_error'],
            'missing outer' => [null, true, 'slip_provider_error'],
            'missing inner' => [true, null, 'slip_provider_error'],
            'numeric outer' => [1, true, 'slip_provider_error'],
            'numeric inner' => [true, 1, 'slip_provider_error'],
        ];
    }

    #[DataProvider('invalidSuccessFlags')]
    public function test_success_requires_both_documented_boolean_flags(mixed $outer, mixed $inner, string $error): void
    {
        // SlipOK documents both success booleans as mandatory. Keep all other
        // transfer facts valid so a malformed flag cannot pass by accident.
        $data = [
            'transRef' => 'SYNTHETIC-STATUS-FLAG-1',
            'transTimestamp' => '2026-10-25T10:00:00+07:00',
            'amount' => 10, 'currency' => 'THB', 'sendingBank' => 'KBANK', 'receivingBank' => 'SCB',
            'receiver' => ['account' => ['value' => 'xxx1234'], 'displayName' => 'Receiver'],
        ];
        if ($inner !== null) {
            $data['success'] = $inner;
        }
        $payload = ['data' => $data];
        if ($outer !== null) {
            $payload['success'] = $outer;
        }
        Http::fake(['*' => Http::response($payload, 200)]);
        try {
            (new SlipOkProvider)->verifyQr('QR-PAYLOAD');
            self::fail('A malformed or contradictory success status was accepted.');
        } catch (FinancialException $exception) {
            self::assertSame($error, $exception->errorCode());
        }
        Http::assertSentCount(1);
    }

    public static function exactJsonAmounts(): array
    {
        return [
            'amount before reference with later decoy' => ['"amount":1.23,"transRef":"REF-1","other":{"amount":121.25}', '1.23'],
            'amount before reference without later amount' => ['"amount":1.23,"transRef":"REF-1"', '1.23'],
            'escaped amount field name' => ['"am\\u006funt":1.23,"transRef":"REF-1","other":{"amount":121.25}', '1.23'],
            'exponential number is not a plain decimal' => ['"transRef":"REF-1","amount":121.25e-2', null],
            'string amount' => ['"transRef":"REF-1","amount":"121.25"', '121.25'],
        ];
    }

    #[DataProvider('exactJsonAmounts')]
    public function test_amount_comes_from_the_exact_json_field_without_float_or_regex_ambiguity(string $amountFields, ?string $expected): void
    {
        $body = '{"success":true,"data":{"success":true,'.$amountFields.',"transTimestamp":"2026-10-25T10:00:00+07:00","currency":"THB"}}';
        Http::fake(['*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);
        try {
            $result = (new SlipOkProvider)->verifyQr('QR-PAYLOAD');
            if ($expected === null) {
                self::fail('An unsupported numeric spelling must not be interpreted as a different amount.');
            }
            self::assertSame($expected, $result->amount);
        } catch (FinancialException $exception) {
            self::assertNull($expected, 'A valid, exact amount was unexpectedly rejected.');
            self::assertSame('slip_provider_error', $exception->errorCode());
        }
        Http::assertSentCount(1);
    }

    public static function receiverAccountMasks(): array
    {
        return [
            'unmasked suffix' => ['xxx-x-1234', '••••1234'],
            'formatted suffix' => ['xxx-x-12-34', '••••1234'],
            'full account' => ['12345678901234', '••••1234'],
            'masked final digit' => ['xxx-x-x1234-x', ''],
            'masked within final digits' => ['xxx-12x34', ''],
            'only three final digits' => ['xxx-123', ''],
        ];
    }

    #[DataProvider('receiverAccountMasks')]
    public function test_receiver_suffix_is_only_inferred_from_four_known_final_digits(string $account, string $expected): void
    {
        Http::fake(['*' => Http::response([
            'success' => true,
            'data' => [
                'success' => true, 'transRef' => 'SYNTHETIC-MASK-1', 'amount' => 10,
                'transTimestamp' => '2026-10-25T10:00:00+07:00',
                'receiver' => ['account' => ['value' => $account]],
            ],
        ], 200)]);
        $result = (new SlipOkProvider)->verifyQr('QR-PAYLOAD');
        self::assertSame($expected, $result->receiverAccountMasked);
        Http::assertSentCount(1);
    }

    public static function malformedDuplicateFlags(): array
    {
        return [
            'null' => [null],
            'string false' => ['false'],
            'empty string' => [''],
            'numeric zero' => [0],
            'numeric one' => [1],
            'object-like array' => [['duplicate' => true]],
        ];
    }

    #[DataProvider('malformedDuplicateFlags')]
    public function test_rejects_a_present_non_boolean_duplicate_flag(mixed $flag): void
    {
        Http::fake(['*' => Http::response([
            'success' => true,
            'data' => [
                'success' => true, 'isDuplicate' => $flag,
                'transRef' => 'SYNTHETIC-DUPLICATE-FLAG-1',
                'transTimestamp' => '2026-10-25T10:00:00+07:00',
                'amount' => 10, 'currency' => 'THB', 'sendingBank' => 'KBANK', 'receivingBank' => 'SCB',
                'receiver' => ['account' => ['value' => 'xxx1234'], 'displayName' => 'Receiver'],
            ],
        ], 200)]);
        try {
            (new SlipOkProvider)->verifyQr('QR-PAYLOAD');
            self::fail('An ambiguous duplicate flag must not be accepted.');
        } catch (FinancialException $exception) {
            self::assertSame('slip_provider_error', $exception->errorCode());
        }
        Http::assertSentCount(1);
    }

    public static function rejectedResponses(): array
    {
        return [
            'invalid slip' => [400, ['success' => false, 'code' => '1005'], 'slip_invalid'],
            'unauthorized' => [401, ['message' => 'unauthorized'], 'slip_provider_error'],
            'forbidden duplicate-shaped body' => [403, ['code' => '1012'], 'slip_provider_error'],
            'rate limited' => [429, ['message' => 'limited'], 'slip_provider_unreachable'],
            'server unavailable' => [503, ['message' => 'unavailable'], 'slip_provider_unreachable'],
            'provider error in success HTTP response' => [200, ['success' => false, 'code' => '1009'], 'slip_provider_unreachable'],
            'invalid slip in success HTTP response' => [200, ['success' => false, 'code' => '1005'], 'slip_invalid'],
            'connection failure' => [null, [], 'slip_provider_unreachable'],
        ];
    }

    #[DataProvider('rejectedResponses')]
    public function test_maps_invalid_authentication_server_failure_and_timeout(?int $status, array $body, string $code): void
    {
        // Isolate HTTP fakes per test: Laravel merges '*' handlers, so a loop
        // in one test silently keeps returning the first 400 response.
        Http::fake(['*' => $status === null
            ? static function () { throw new ConnectionException('network detail'); }
            : Http::response($body, $status)]);
        try {
            (new SlipOkProvider)->verifyQr('QR-PAYLOAD');
            self::fail('Provider failure was accepted.');
        } catch (FinancialException $exception) {
            self::assertSame($code, $exception->errorCode());
            self::assertStringNotContainsString('network detail', $exception->getMessage());
        }
        if ($status !== null) {
            $recorded = Http::recorded();
            self::assertCount(1, $recorded);
            self::assertSame($status, $recorded->first()[1]->status());
        }
    }
}
