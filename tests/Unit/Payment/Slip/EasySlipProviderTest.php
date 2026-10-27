<?php

declare(strict_types=1);

namespace Tests\Unit\Payment\Slip;

use App\Exceptions\FinancialException;
use App\Services\Payment\Slip\EasySlipProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
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

    public function test_maps_invalid_slip_authentication_server_failure_and_timeout_to_stable_codes(): void
    {
        foreach ([
            [Http::response(['success' => false, 'error' => ['code' => 'SLIP_NOT_FOUND']], 404), 'slip_invalid'],
            [Http::response(['message' => 'unauthorized'], 401), 'slip_provider_error'],
            [Http::response(['message' => 'unavailable'], 503), 'slip_provider_unreachable'],
            [static function () { throw new ConnectionException('network detail'); }, 'slip_provider_unreachable'],
        ] as [$response, $code]) {
            Http::fake(['*' => is_callable($response) ? $response : $response]);
            try {
                (new EasySlipProvider)->verifyQr('QR-PAYLOAD');
                self::fail('Provider failure was accepted.');
            } catch (FinancialException $exception) {
                self::assertSame($code, $exception->errorCode());
                self::assertStringNotContainsString('network detail', $exception->getMessage());
            }
        }
    }
}
