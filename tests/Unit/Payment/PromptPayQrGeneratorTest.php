<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Services\Payment\PromptPayQrGeneratorService;
use PHPUnit\Framework\TestCase;

class PromptPayQrGeneratorTest extends TestCase
{
    private PromptPayQrGeneratorService $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new PromptPayQrGeneratorService();
    }

    public function test_generates_valid_static_promptpay_qr_for_phone_number(): void
    {
        // Thai mobile: 0812345678 -> 0066812345678
        $payload = $this->generator->generatePayload('0812345678');

        $this->assertStringStartsWith('000201010211', $payload); // Static initiation (11)
        $this->assertStringContainsString('A000000677010111', $payload); // PromptPay AID
        $this->assertStringContainsString('0066812345678', $payload); // Formatted phone
        $this->assertStringContainsString('5303764', $payload); // THB Currency code 764
        $this->assertStringContainsString('5802TH', $payload); // Country TH

        // Check CRC checksum calculation
        $dataWithoutCrc = substr($payload, 0, -4);
        $expectedCrc = $this->generator->calculateCrc16Ccitt($dataWithoutCrc);
        $this->assertStringEndsWith($expectedCrc, $payload);
    }

    public function test_generates_valid_dynamic_promptpay_qr_with_amount(): void
    {
        // Thai National ID: 1234567890123 with amount 150.00 THB
        $payload = $this->generator->generatePayload('1234567890123', '150.00');

        $this->assertStringStartsWith('000201010212', $payload); // Dynamic initiation (12)
        $this->assertStringContainsString('1234567890123', $payload);
        $this->assertStringContainsString('5406150.00', $payload); // Amount tag (54) + length 06 + 150.00

        $dataWithoutCrc = substr($payload, 0, -4);
        $expectedCrc = $this->generator->calculateCrc16Ccitt($dataWithoutCrc);
        $this->assertStringEndsWith($expectedCrc, $payload);
    }

    public function test_crc16_ccitt_checksum_known_vectors(): void
    {
        $crc = $this->generator->calculateCrc16Ccitt('00020101021129370016A000000677010111011300668123456785802TH53037646304');
        $this->assertSame(4, strlen($crc));
        $this->assertMatchesRegularExpression('/^[0-9A-F]{4}$/', $crc);
    }
}
