<?php

declare(strict_types=1);

namespace Tests\Unit\Payout;

use App\Enums\ThaiBankCode;
use App\Exceptions\FinancialException;
use App\Services\Payout\ThaiBank\ThaiBankDestinationValidator;
use PHPUnit\Framework\TestCase;

final class ThaiBankDestinationValidatorTest extends TestCase
{
    public function test_validates_bank_specific_account_lengths_and_masks_account_numbers(): void
    {
        $validator = new ThaiBankDestinationValidator;
        $instruction = $validator->instruction('WD-TEST-123', '25.50', 'THB', [
            'destination_type' => 'bank_account',
            'bank_code' => 'SCB',
            'account_number' => '1234567890',
            'account_name' => 'Ms.  Somchai   Example',
        ]);

        self::assertSame(ThaiBankCode::Scb->value, $instruction->bankCode);
        self::assertSame('1234567890', $instruction->accountNumber);
        self::assertSame('Somchai Example', $instruction->accountName);
        self::assertSame('••••7890', ThaiBankDestinationValidator::maskAccount($instruction->accountNumber));
    }

    public function test_rejects_incorrect_account_length_without_echoing_the_number(): void
    {
        $validator = new ThaiBankDestinationValidator;
        $number = '1234567890123456';
        try {
            $validator->instruction('WD-TEST-124', '1.00', 'THB', [
                'destination_type' => 'bank_account',
                'bank_code' => 'KTB',
                'account_number' => $number,
                'account_name' => 'Example Name',
            ]);
            self::fail('Invalid bank account length was accepted.');
        } catch (FinancialException $exception) {
            self::assertSame('invalid_payout_destination', $exception->errorCode());
            self::assertStringNotContainsString($number, $exception->getMessage());
        }
    }

    public function test_normalises_thai_promptpay_mobile_with_country_prefix(): void
    {
        $instruction = (new ThaiBankDestinationValidator)->instruction('WD-TEST-125', '10.00', 'THB', [
            'destination_type' => 'promptpay_mobile',
            'mobile' => '+66812345678',
            'account_name' => 'นาย Somchai Example',
        ]);

        self::assertSame('promptpay_mobile', $instruction->destinationType);
        self::assertSame('0812345678', $instruction->accountNumber);
        self::assertSame('Somchai Example', $instruction->accountName);
    }

    public function test_validates_promptpay_national_id_checksum(): void
    {
        self::assertTrue(ThaiBankDestinationValidator::hasValidThaiNationalIdChecksum('1234567890121'));
        self::assertFalse(ThaiBankDestinationValidator::hasValidThaiNationalIdChecksum('1234567890122'));
        $instruction = (new ThaiBankDestinationValidator)->instruction('WD-TEST-126', '10.00', 'THB', [
            'destination_type' => 'promptpay_national_id',
            'national_id' => '1234567890121',
            'account_name' => 'Example Person',
        ]);
        self::assertSame('1234567890121', $instruction->accountNumber);
    }

    public function test_account_name_normalisation_strips_titles_and_collapses_spaces(): void
    {
        self::assertSame('Example Person', ThaiBankDestinationValidator::normaliseAccountName('  Mr.   Example   Person  '));
    }
}
