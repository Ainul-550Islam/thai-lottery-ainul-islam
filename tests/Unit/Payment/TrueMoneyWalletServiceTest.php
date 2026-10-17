<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Services\Payment\TrueMoneyWalletService;
use PHPUnit\Framework\TestCase;

class TrueMoneyWalletServiceTest extends TestCase
{
    public function test_extracts_voucher_code_from_valid_gift_urls(): void
    {
        $service = new TrueMoneyWalletService(
            \Mockery::mock(\App\Services\Deposit\DepositProcessingService::class),
            \Mockery::mock(\App\Services\Fraud\FraudDetectionService::class),
            \Mockery::mock(\App\Services\Lock\DistributedLockService::class)
        );

        $url = 'https://gift.truemoney.com/campaign/?v=1234567890abcdef1234567890abcdef';
        $code = $service->extractVoucherCode($url);

        $this->assertSame('1234567890abcdef1234567890abcdef', $code);
    }

    public function test_rejects_invalid_truemoney_urls(): void
    {
        $service = new TrueMoneyWalletService(
            \Mockery::mock(\App\Services\Deposit\DepositProcessingService::class),
            \Mockery::mock(\App\Services\Fraud\FraudDetectionService::class),
            \Mockery::mock(\App\Services\Lock\DistributedLockService::class)
        );

        $this->expectException(\InvalidArgumentException::class);
        $service->extractVoucherCode('https://phishing-site.com/voucher?v=invalid');
    }
}
