<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Services\Payment\CryptoUsdtGatewayService;
use PHPUnit\Framework\TestCase;

class CryptoUsdtGatewayServiceTest extends TestCase
{
    private CryptoUsdtGatewayService $cryptoService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cryptoService = new CryptoUsdtGatewayService(
            \Mockery::mock(\App\Services\Deposit\DepositProcessingService::class),
            \Mockery::mock(\App\Services\Fraud\FraudDetectionService::class),
            \Mockery::mock(\App\Services\Lock\DistributedLockService::class)
        );
    }

    public function test_generates_deterministic_tron_deposit_address(): void
    {
        $userId = 'usr_crypto_test_01';
        $addr1 = $this->cryptoService->getUserDepositAddress($userId);
        $addr2 = $this->cryptoService->getUserDepositAddress($userId);

        $this->assertSame($addr1, $addr2);
        $this->assertStringStartsWith('T', $addr1);
        $this->assertSame(34, strlen($addr1));
    }

    public function test_validates_tron_base58_address_format(): void
    {
        $this->assertTrue($this->cryptoService->isValidTronAddress('TJ9G2U2YVp4X6b2o8e3Q1R5T7Y9U1I3O5P'));
        $this->assertFalse($this->cryptoService->isValidTronAddress('0x71C7656EC7ab88b098defB751B7401B5f6d8976F')); // Ethereum address
        $this->assertFalse($this->cryptoService->isValidTronAddress('InvalidAddress'));
    }
}
