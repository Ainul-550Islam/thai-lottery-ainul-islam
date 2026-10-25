<?php

declare(strict_types=1);

namespace Tests\Unit\Payment\TrueMoney;

use App\Services\Deposit\DepositProcessingService;
use App\Services\Fraud\FraudDetectionService;
use App\Services\Lock\DistributedLockService;
use App\Services\Payment\TrueMoneyWalletService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TrueMoneyVoucherParserTest extends TestCase
{
    private function service(): TrueMoneyWalletService
    {
        return new TrueMoneyWalletService(
            \Mockery::mock(DepositProcessingService::class),
            \Mockery::mock(FraudDetectionService::class),
            \Mockery::mock(DistributedLockService::class),
        );
    }

    public function test_parses_only_strict_https_gift_voucher_urls(): void
    {
        $service = $this->service();
        $code = '1234567890abcdef1234567890abcdef';

        self::assertSame($code, $service->extractVoucherCode('https://gift.truemoney.com/campaign/?v='.$code));
        self::assertSame($code, $service->extractVoucherCode('https://gift.truemoney.com/campaign?v='.$code));
    }

    public function test_rejects_wrong_host_http_short_code_and_injected_query_parameters(): void
    {
        $service = $this->service();
        $valid = '1234567890abcdef1234567890abcdef';
        foreach ([
            'https://phishing.example/campaign/?v='.$valid,
            'http://gift.truemoney.com/campaign/?v='.$valid,
            'https://gift.truemoney.com/campaign/?v=short',
            'https://gift.truemoney.com/campaign/?v='.$valid.'&next=https://evil.example',
            'https://gift.truemoney.com@evil.example/campaign/?v='.$valid,
            'https://gift.truemoney.com:443/campaign/?v='.$valid,
        ] as $url) {
            try {
                $service->extractVoucherCode($url);
                self::fail('Invalid voucher URL was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_voucher_hash_is_deterministic_and_only_last_four_are_returned_for_safe_display(): void
    {
        $code = '1234567890abcdef1234567890abcdef';

        self::assertSame(TrueMoneyWalletService::hashVoucherCode($code), TrueMoneyWalletService::hashVoucherCode($code));
        self::assertSame(hash('sha256', $code), TrueMoneyWalletService::hashVoucherCode($code));
        self::assertSame('cdef', TrueMoneyWalletService::voucherLastFour($code));
        self::assertStringNotContainsString($code, TrueMoneyWalletService::hashVoucherCode($code));
    }
}
