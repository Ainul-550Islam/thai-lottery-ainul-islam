<?php

declare(strict_types=1);

namespace Tests\Unit\Payment\Slip;

use App\Exceptions\FinancialException;
use App\Services\Payment\Slip\SlipOkProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
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
            && $request['log'] === false);
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
            && $request->hasFile('files'));
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

    public function test_maps_invalid_authentication_server_failure_and_timeout(): void
    {
        foreach ([
            [Http::response(['success' => false, 'code' => '1005'], 400), 'slip_invalid'],
            [Http::response(['message' => 'unauthorized'], 401), 'slip_provider_error'],
            [Http::response(['message' => 'unavailable'], 503), 'slip_provider_unreachable'],
            [static function () { throw new ConnectionException('network detail'); }, 'slip_provider_unreachable'],
        ] as [$response, $code]) {
            Http::fake(['*' => is_callable($response) ? $response : $response]);
            try {
                (new SlipOkProvider)->verifyQr('QR-PAYLOAD');
                self::fail('Provider failure was accepted.');
            } catch (FinancialException $exception) {
                self::assertSame($code, $exception->errorCode());
                self::assertStringNotContainsString('network detail', $exception->getMessage());
            }
        }
    }
}
