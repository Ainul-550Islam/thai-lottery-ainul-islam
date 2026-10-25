<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Exceptions\FinancialException;
use App\Services\Payment\PromptPayQrGeneratorService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PromptPayQrGeneratorValidationTest extends TestCase
{
    private PromptPayQrGeneratorService $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new PromptPayQrGeneratorService;
    }

    /** @return array<string, array{string}> */
    public static function validRecipients(): array
    {
        return [
            'mobile' => ['0812345678'],
            'mobile with dashes' => ['081-234-5678'],
            'mobile with country code' => ['+66 81-234-5678'],
            'national or tax id' => ['1234567890123'],
            'e-wallet id' => ['123456789012345'],
        ];
    }

    #[DataProvider('validRecipients')]
    public function test_valid_recipient_formats(string $recipient): void
    {
        $payload = $this->generator->generatePayload($recipient, '10.00', 'DEP-TEST-0001');

        $this->assertTrue($this->generator->isValidRecipientId($recipient));
        $this->assertStringContainsString('0513DEP-TEST-0001', $payload);
        $this->assertSame(substr($payload, -4), $this->generator->calculateCrc16Ccitt(substr($payload, 0, -4)));
    }

    /** @return array<string, array{string}> */
    public static function invalidRecipients(): array
    {
        return [
            'empty' => [''],
            'too short' => ['081234567'],
            'too long' => ['08123456789'],
            'letters' => ['08123A5678'],
            'twelve digits' => ['123456789012'],
            'sixteen digits' => ['1234567890123456'],
            'punctuation' => ['081.234.5678'],
        ];
    }

    #[DataProvider('invalidRecipients')]
    public function test_invalid_recipient_formats_fail_closed(string $recipient): void
    {
        $this->assertFalse($this->generator->isValidRecipientId($recipient));

        try {
            $this->generator->generatePayload($recipient);
            $this->fail('An invalid PromptPay recipient was accepted.');
        } catch (FinancialException $exception) {
            $this->assertSame('invalid_promptpay_recipient', $exception->errorCode());
        }
    }

    /** @return array<string, array{string}> */
    public static function invalidAmounts(): array
    {
        return [
            'zero' => ['0.00'],
            'negative' => ['-1.00'],
            'integer' => ['1'],
            'one decimal' => ['1.0'],
            'three decimals' => ['1.000'],
            'leading zero' => ['01.00'],
            'scientific notation' => ['1e2'],
            'not numeric' => ['NaN'],
            'whitespace' => [' 1.00'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_amount_requires_a_positive_two_decimal_string(string $amount): void
    {
        try {
            $this->generator->generatePayload('0812345678', $amount);
            $this->fail('An invalid PromptPay amount was accepted.');
        } catch (FinancialException $exception) {
            $this->assertSame('invalid_promptpay_amount', $exception->errorCode());
        }
    }

    public function test_amount_is_never_converted_through_a_float(): void
    {
        $payload = $this->generator->generatePayload('0812345678', '999999999999.99');

        $this->assertStringContainsString('5415999999999999.99', $payload);
    }

    public function test_emv_tags_are_complete_and_in_numeric_order(): void
    {
        $payload = $this->generator->generatePayload('0812345678', '10.00', 'DEP-TEST-0001');
        $tags = [];
        $offset = 0;
        while ($offset < strlen($payload)) {
            $tag = substr($payload, $offset, 2);
            $length = (int) substr($payload, $offset + 2, 2);
            $tags[] = $tag;
            $offset += 4 + $length;
        }

        $this->assertSame(strlen($payload), $offset);
        $this->assertSame(['00', '01', '29', '53', '54', '58', '62', '63'], $tags);
    }

    public function test_crc16_ccitt_known_vector(): void
    {
        $this->assertSame('29B1', $this->generator->calculateCrc16Ccitt('123456789'));
    }
}
