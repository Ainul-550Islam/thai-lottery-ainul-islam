<?php

declare(strict_types=1);

namespace Tests\Unit\Payment\Slip;

use App\Exceptions\FinancialException;
use App\Services\Payment\Slip\EasySlipProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EasySlipProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('slip_verification.enabled', true);
        Config::set('slip_verification.providers.easyslip.base_url', 'https://api.easyslip.com/v2');
        Config::set('slip_verification.providers.easyslip.verify_path', '/verify/bank');
        Config::set('slip_verification.providers.easyslip.token', 'unit-test-token');
        Config::set('slip_verification.providers.easyslip.timeout', 4);
    }

    public function test_maps_verified_slip_and_preserves_exact_decimal_amount_string(): void
    {
        Http::fake(['*' => Http::response([
            'success' => true,
            'data' => [
                'rawSlip' => [
                    'transRef' => 'SLIP-REF-001',
                    'date' => '2026-10-25T10:00:00+07:00',
                    'amount' => ['amount' => 123.45, 'local' => ['amount' => 123.45, 'currency' => 'THB']],
                    'sender' => ['bank' => ['short' => 'KBANK'], 'account' => ['name' => ['th' => 'Sender Name']]],
                    'receiver' => ['bank' => ['short' => 'SCB'], 'account' => ['bank' => ['account' => '12345678901234'], 'name' => ['th' => 'Receiver Name']]],
                ],
                'isDuplicate' => false,
            ],
        ], 200)]);

        $result = (new EasySlipProvider)->verifyQr('QR-PAYLOAD');

        self::assertSame('SLIP-REF-001', $result->transRef);
        self::assertSame('123.45', $result->amount);
        self::assertSame('THB', $result->currency);
        self::assertSame('••••1234', $result->receiverAccountMasked);
        self::assertSame('SCB', $result->receivingBank);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.easyslip.com/v2/verify/bank'
            && $request->hasHeader('Authorization', 'Bearer unit-test-token')
            && $request['checkDuplicate'] === true);
    }

    public function test_uploads_a_scanned_png_using_the_documented_image_field(): void
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aT3sAAAAASUVORK5CYII=', true);
        self::assertIsString($bytes);
        $path = tempnam(sys_get_temp_dir(), 'easyslip-test-');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        Http::fake(['*' => Http::response([
            'success' => true,
            'data' => [
                'isDuplicate' => false,
                'rawSlip' => [
                    'transRef' => 'SLIP-IMAGE-001',
                    'date' => '2026-10-25T10:00:00+07:00',
                    'amount' => ['amount' => 1, 'local' => ['amount' => 1, 'currency' => 'THB']],
                    'sender' => ['bank' => ['short' => 'KBANK'], 'account' => ['name' => ['th' => 'Sender']]],
                    'receiver' => ['bank' => ['short' => 'SCB'], 'account' => ['bank' => ['account' => 'xxx1234'], 'name' => ['th' => 'Receiver']]],
                ],
            ],
        ], 200)]);

        try {
            $result = (new EasySlipProvider)->verifyImage($path);
        } finally {
            unlink($path);
        }

        self::assertSame('SLIP-IMAGE-001', $result->transRef);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.easyslip.com/v2/verify/bank'
            && $request->isMultipart()
            && $request->hasFile('image'));
    }

    public function test_disabled_lane_refuses_without_network_call(): void
    {
        Config::set('slip_verification.enabled', false);
        Http::fake();
        try {
            (new EasySlipProvider)->verifyQr('QR-PAYLOAD');
            self::fail('A disabled slip lane must refuse verification.');
        } catch (FinancialException $exception) {
            self::assertSame('slip_provider_not_configured', $exception->errorCode());
        }
        Http::assertNothingSent();
    }

    public function test_image_over_the_documented_four_megabyte_limit_is_refused_before_network(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'easyslip-oversize-');
        self::assertIsString($path);
        $stream = fopen($path, 'wb');
        self::assertIsResource($stream);
        self::assertTrue(ftruncate($stream, 4_000_001));
        fclose($stream);
        Http::fake();

        try {
            (new EasySlipProvider)->verifyImage($path);
            self::fail('The EasySlip image upload cap must be enforced.');
        } catch (FinancialException $exception) {
            self::assertSame('slip_image_too_large', $exception->errorCode());
        } finally {
            unlink($path);
        }

        Http::assertNothingSent();
    }

    public function test_refuses_to_send_provider_credentials_to_an_untrusted_host(): void
    {
        Config::set('slip_verification.providers.easyslip.base_url', 'https://untrusted.example/v2');
        Http::fake();

        try {
            (new EasySlipProvider)->verifyQr('QR-PAYLOAD');
            self::fail('EasySlip credentials must not be sent to a non-provider host.');
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
                'isDuplicate' => false,
                'rawSlip' => [
                    'transRef' => 'SLIP-REDIRECT-001',
                    'date' => '2026-10-25T10:00:00+07:00',
                    'amount' => ['local' => ['amount' => 10.00, 'currency' => 'THB']],
                    'sender' => ['bank' => ['short' => 'KBANK']],
                    'receiver' => [
                        'bank' => ['short' => 'SCB'],
                        'account' => ['bank' => ['account' => '1234567890'], 'name' => ['th' => 'Receiver']],
                    ],
                ],
            ],
        ], 302)]);

        try {
            (new EasySlipProvider)->verifyQr('QR-PAYLOAD');
            self::fail('A non-final redirect response must never verify a slip.');
        } catch (FinancialException $exception) {
            self::assertSame('slip_provider_error', $exception->errorCode());
        }
        Http::assertSentCount(1);
    }

    public static function exactJsonAmounts(): array
    {
        return [
            'unrelated local amount before real amount' => ['{"decoy":{"local":{"amount":121.25}},"success":true,"data":{"rawSlip":{"transRef":"REF-1","date":"2026-10-25T10:00:00+07:00","amount":{"local":{"amount":1.23,"currency":"THB"}}}}}', '1.23'],
            'exponential number is not a plain decimal' => ['{"success":true,"data":{"rawSlip":{"transRef":"REF-1","date":"2026-10-25T10:00:00+07:00","amount":{"local":{"amount":121.25e-2,"currency":"THB"}}}}}', null],
            'string amount' => ['{"success":true,"data":{"rawSlip":{"transRef":"REF-1","date":"2026-10-25T10:00:00+07:00","amount":{"local":{"amount":"121.25","currency":"THB"}}}}}', '121.25'],
        ];
    }

    #[DataProvider('exactJsonAmounts')]
    public function test_amount_comes_from_the_exact_json_field_without_float_or_regex_ambiguity(string $body, ?string $expected): void
    {
        Http::fake(['*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);
        try {
            $result = (new EasySlipProvider)->verifyQr('QR-PAYLOAD');
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
                'rawSlip' => [
                    'transRef' => 'SYNTHETIC-MASK-1', 'date' => '2026-10-25T10:00:00+07:00',
                    'amount' => ['local' => ['amount' => 10, 'currency' => 'THB']],
                    'receiver' => ['account' => ['bank' => ['account' => $account]]],
                ],
            ],
        ], 200)]);
        $result = (new EasySlipProvider)->verifyQr('QR-PAYLOAD');
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
                'isDuplicate' => $flag,
                'rawSlip' => [
                    'transRef' => 'SYNTHETIC-DUPLICATE-FLAG-1',
                    'date' => '2026-10-25T10:00:00+07:00',
                    'amount' => ['local' => ['amount' => 10, 'currency' => 'THB']],
                    'receiver' => ['bank' => ['short' => 'SCB'], 'account' => ['bank' => ['account' => 'xxx1234'], 'name' => ['th' => 'Receiver']]],
                ],
            ],
        ], 200)]);
        try {
            (new EasySlipProvider)->verifyQr('QR-PAYLOAD');
            self::fail('An ambiguous duplicate flag must not be accepted.');
        } catch (FinancialException $exception) {
            self::assertSame('slip_provider_error', $exception->errorCode());
        }
        Http::assertSentCount(1);
    }

    public static function rejectedResponses(): array
    {
        return [
            'invalid slip' => [404, ['success' => false, 'error' => ['code' => 'SLIP_NOT_FOUND']], 'slip_invalid'],
            'unauthorized' => [401, ['message' => 'unauthorized'], 'slip_provider_error'],
            'forbidden duplicate-shaped body' => [403, ['data' => ['isDuplicate' => true]], 'slip_provider_error'],
            'rate limited' => [429, ['message' => 'limited'], 'slip_provider_unreachable'],
            'server unavailable' => [503, ['message' => 'unavailable'], 'slip_provider_unreachable'],
            'provider error in success HTTP response' => [200, ['success' => false, 'error' => ['code' => 'API_SERVER_ERROR']], 'slip_provider_unreachable'],
            'invalid slip in success HTTP response' => [200, ['success' => false, 'error' => ['code' => 'INVALID_SLIP']], 'slip_invalid'],
            'connection failure' => [null, [], 'slip_provider_unreachable'],
        ];
    }

    #[DataProvider('rejectedResponses')]
    public function test_maps_invalid_slip_authentication_server_failure_and_timeout_to_stable_codes(?int $status, array $body, string $code): void
    {
        // Each case gets a fresh Laravel HTTP factory. Calling Http::fake('*')
        // repeatedly in one loop merges handlers, so the first response would
        // otherwise shadow every later status and falsely exercise only 404.
        Http::fake(['*' => $status === null
            ? static function () { throw new ConnectionException('network detail'); }
            : Http::response($body, $status)]);
        try {
            (new EasySlipProvider)->verifyQr('QR-PAYLOAD');
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
