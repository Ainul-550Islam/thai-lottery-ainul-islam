<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Exceptions\FinancialException;
use App\Services\Payout\ThaiBank\ThaiBankDestinationValidator;

final class ThaiWithdrawalDestinationRulesTest extends Batch01TestCase
{
    public function test_valid_mobile_and_national_id_promptpay_destinations_are_accepted(): void
    {
        $validator = app(ThaiBankDestinationValidator::class);
        $mobile = $validator->instruction('WD-THAI-01', '250.00', 'THB', [
            'destination_type' => 'promptpay_mobile', 'mobile' => '0812345678', 'account_name' => 'Recipient',
        ]);
        $nationalId = $validator->instruction('WD-THAI-02', '250.00', 'THB', [
            'destination_type' => 'promptpay_national_id', 'national_id' => '1234567890121', 'account_name' => 'Recipient',
        ]);

        self::assertSame('0812345678', $mobile->accountNumber);
        self::assertSame('promptpay_national_id', $nationalId->destinationType);
    }

    public function test_invalid_thai_proxy_checksum_bangladesh_mobile_and_bank_account_lengths_are_rejected(): void
    {
        $validator = app(ThaiBankDestinationValidator::class);
        foreach ([
            ['destination_type' => 'promptpay_mobile', 'mobile' => '01712345678', 'account_name' => 'Recipient'],
            ['destination_type' => 'promptpay_national_id', 'national_id' => '1234567890122', 'account_name' => 'Recipient'],
            ['destination_type' => 'bank_account', 'bank_code' => 'SCB', 'account_number' => '12345678', 'account_name' => 'Recipient'],
        ] as $details) {
            try {
                $validator->instruction('WD-THAI-03', '250.00', 'THB', $details);
                self::fail('Invalid Thai payout destination was accepted.');
            } catch (FinancialException $exception) {
                self::assertSame('invalid_payout_destination', $exception->errorCode());
            }
        }
    }
}
