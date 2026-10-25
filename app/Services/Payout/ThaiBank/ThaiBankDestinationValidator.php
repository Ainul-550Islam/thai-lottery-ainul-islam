<?php

declare(strict_types=1);

namespace App\Services\Payout\ThaiBank;

use App\DTOs\Payment\PayoutInstruction;
use App\Enums\ThaiBankCode;
use App\Exceptions\FinancialException;
use App\Services\Finance\Money;

final class ThaiBankDestinationValidator
{
    /** @param array<string, mixed> $details */
    public function instruction(string $reference, string $amount, string $currency, array $details): PayoutInstruction
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,63}\z/D', $reference) !== 1 || strtoupper($currency) !== 'THB') {
            $this->invalid('reference_or_currency');
        }
        $normalisedAmount = Money::of($amount, \App\Enums\Currency::THB)->toString();
        if (! Money::of($normalisedAmount, \App\Enums\Currency::THB)->isPositive()) {
            $this->invalid('amount');
        }

        $type = strtolower(trim((string) ($details['destination_type'] ?? $details['type'] ?? $details['method'] ?? '')));
        if ($type === '') {
            if (isset($details['national_id']) || isset($details['promptpay_national_id'])) {
                $type = 'promptpay_national_id';
            } elseif (isset($details['mobile']) || isset($details['phone']) || isset($details['promptpay_mobile'])) {
                $type = 'promptpay_mobile';
            } else {
                $type = 'bank_account';
            }
        }

        $accountName = self::normaliseAccountName((string) ($details['account_name'] ?? $details['beneficiary_name'] ?? ''));
        if ($accountName === '' || strlen($accountName) > 120) {
            $this->invalid('account_name');
        }
        $memo = trim((string) ($details['memo'] ?? ''));
        $memo = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $memo) ?? '';
        $memo = substr(preg_replace('/\s+/', ' ', $memo) ?? '', 0, 120);

        if ($type === 'bank_account') {
            $bank = ThaiBankCode::resolve((string) ($details['bank_code'] ?? $details['bank'] ?? $details['bank_name'] ?? ''));
            $rawNumber = (string) ($details['account_number'] ?? '');
            if (! $bank instanceof ThaiBankCode || preg_match('/\A[0-9\s().-]+\z/D', $rawNumber) !== 1) {
                $this->invalid('bank_account_shape');
            }
            $number = preg_replace('/\D+/', '', $rawNumber) ?? '';
            if (! in_array(strlen($number), $bank->accountNumberLengths(), true)) {
                $this->invalid('bank_account_length');
            }

            return new PayoutInstruction(
                reference: $reference,
                amount: $normalisedAmount,
                currency: 'THB',
                destinationType: 'bank_account',
                bankCode: $bank->value,
                accountNumber: $number,
                accountName: $accountName,
                memo: $memo,
            );
        }

        if ($type === 'promptpay_mobile') {
            $rawNumber = (string) ($details['mobile'] ?? $details['phone'] ?? $details['promptpay_mobile'] ?? $details['account_number'] ?? '');
            if (preg_match('/\A\+?[0-9\s().-]+\z/D', $rawNumber) !== 1) {
                $this->invalid('promptpay_mobile_shape');
            }
            $number = preg_replace('/[^0-9+]/', '', $rawNumber) ?? '';
            if (str_starts_with($number, '+66')) {
                $number = '0'.substr($number, 3);
            } elseif (str_starts_with($number, '66') && strlen($number) === 11) {
                $number = '0'.substr($number, 2);
            }
            if (preg_match('/\A0[689][0-9]{8}\z/D', $number) !== 1) {
                $this->invalid('promptpay_mobile_length');
            }

            return new PayoutInstruction(
                reference: $reference,
                amount: $normalisedAmount,
                currency: 'THB',
                destinationType: 'promptpay_mobile',
                bankCode: null,
                accountNumber: $number,
                accountName: $accountName,
                memo: $memo,
            );
        }

        if ($type === 'promptpay_national_id') {
            $rawNumber = (string) ($details['national_id'] ?? $details['promptpay_national_id'] ?? $details['promptpay_id'] ?? $details['account_number'] ?? '');
            if (preg_match('/\A[0-9\s-]+\z/D', $rawNumber) !== 1) {
                $this->invalid('promptpay_national_id_shape');
            }
            $number = preg_replace('/\D+/', '', $rawNumber) ?? '';
            if (! self::hasValidThaiNationalIdChecksum($number)) {
                $this->invalid('promptpay_national_id_checksum');
            }

            return new PayoutInstruction(
                reference: $reference,
                amount: $normalisedAmount,
                currency: 'THB',
                destinationType: 'promptpay_national_id',
                bankCode: null,
                accountNumber: $number,
                accountName: $accountName,
                memo: $memo,
            );
        }

        $this->invalid('destination_type');
    }

    public static function normaliseAccountName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $name = preg_replace('/\A(?:mr\.?|mrs\.?|miss|ms\.?|นาย|นางสาว|นาง|น\.ส\.|คุณ)\s+/iu', '', $name) ?? $name;

        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }

    public static function maskAccount(string $account): string
    {
        $digits = preg_replace('/\D+/', '', $account) ?? '';
        $last = substr($digits, -4);

        return $last === '' ? '[MASKED]' : '••••'.$last;
    }

    public static function hasValidThaiNationalIdChecksum(string $number): bool
    {
        if (preg_match('/\A[0-9]{13}\z/D', $number) !== 1) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += ((int) $number[$i]) * (13 - $i);
        }
        $check = (11 - ($sum % 11)) % 10;

        return $check === (int) $number[12];
    }

    private function invalid(string $reason): never
    {
        throw FinancialException::withCode(
            'invalid_payout_destination',
            'The Thai bank payout destination is invalid; no transfer was submitted.',
            ['reason' => $reason],
        );
    }
}
