<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Exceptions\FinancialException;
use App\Services\Deposit\DepositProcessingService;
use App\Services\Fraud\FraudDetectionService;
use App\Services\Lock\DistributedLockService;
use App\Services\Payment\CryptoUsdtGatewayService;
use Mockery;
use PHPUnit\Framework\TestCase;

class CryptoUsdtGatewayServiceTest extends TestCase
{
    private CryptoUsdtGatewayService $cryptoService;

    protected function setUp(): void
    {
        parent::setUp();
        $deposits = Mockery::mock(DepositProcessingService::class);
        $fraud = Mockery::mock(FraudDetectionService::class);
        $locks = Mockery::mock(DistributedLockService::class);
        $deposits->shouldNotReceive('processConfirmedDeposit');
        $fraud->shouldNotReceive('evaluateTransactionRisk');
        $locks->shouldNotReceive('acquireLock');
        $this->cryptoService = new CryptoUsdtGatewayService($deposits, $fraud, $locks);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_unsupported_crypto_cannot_generate_a_fabricated_deposit_address(): void
    {
        try {
            $this->cryptoService->getUserDepositAddress('synthetic-user');
            $this->fail('An unverified crypto address must never be advertised.');
        } catch (FinancialException $exception) {
            $this->assertSame('crypto_unavailable', $exception->errorCode());
        }
    }

    public function test_caller_supplied_confirmations_cannot_credit_an_unverified_crypto_deposit(): void
    {
        try {
            $this->cryptoService->processUsdtDeposit(
                'synthetic-transaction', 'TJ9G2U2YVp4X6b2o8e3Q1R5T7Y9U1H3P5Q',
                'synthetic-user', '100.00', 99, '192.0.2.1',
            );
            $this->fail('Unverified chain data must not reach the deposit ledger.');
        } catch (FinancialException $exception) {
            $this->assertSame('crypto_unavailable', $exception->errorCode());
        }
    }

    public function test_address_helper_checks_shape_not_custody_or_checksum(): void
    {
        $this->assertTrue($this->cryptoService->isValidTronAddress('TJ9G2U2YVp4X6b2o8e3Q1R5T7Y9U1H3P5Q'));
        $this->assertFalse($this->cryptoService->isValidTronAddress('0x71C7656EC7ab88b098defB751B7401B5f6d8976F'));
        $this->assertFalse($this->cryptoService->isValidTronAddress('InvalidAddress'));
    }
}
