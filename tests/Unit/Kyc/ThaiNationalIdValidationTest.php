<?php

declare(strict_types=1);

namespace Tests\Unit\Kyc;

use App\Services\Kyc\ThaiNationalIdValidationService;
use PHPUnit\Framework\TestCase;

class ThaiNationalIdValidationTest extends TestCase
{
    private ThaiNationalIdValidationService $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ThaiNationalIdValidationService();
    }

    public function test_validates_correct_thai_national_id_checksum(): void
    {
        // 1-1002-01123-45-2 (Valid test vector with correct check digit 2)
        // Let's compute a known valid ID: 123456789012X
        // Sum = 1*13 + 2*12 + 3*11 + 4*10 + 5*9 + 6*8 + 7*7 + 8*6 + 9*5 + 0*4 + 1*3 + 2*2 = 369
        // 369 % 11 = 6. (11 - 6) % 10 = 5.
        // Valid ID: '1234567890125'
        $validId = '1234567890125';
        $this->assertTrue($this->validator->isValidNationalId($validId));

        // Formatted with dashes
        $this->assertTrue($this->validator->isValidNationalId('1-2345-67890-12-5'));
    }

    public function test_rejects_invalid_checksum_digit(): void
    {
        // 1234567890125 check digit is 5, so 1234567890124 is invalid
        $this->assertFalse($this->validator->isValidNationalId('1234567890124'));
        $this->assertFalse($this->validator->isValidNationalId('1234567890129'));
    }

    public function test_rejects_identical_digit_patterns(): void
    {
        $this->assertFalse($this->validator->isValidNationalId('0000000000000'));
        $this->assertFalse($this->validator->isValidNationalId('1111111111111'));
        $this->assertFalse($this->validator->isValidNationalId('9999999999999'));
    }

    public function test_rejects_invalid_lengths_or_non_numeric(): void
    {
        $this->assertFalse($this->validator->isValidNationalId('123456789012')); // 12 digits
        $this->assertFalse($this->validator->isValidNationalId('12345678901234')); // 14 digits
        $this->assertFalse($this->validator->isValidNationalId('abcdefghijklm'));
    }
}
