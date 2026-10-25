<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Exceptions\FinancialException;
use InvalidArgumentException;

class PromptPayQrGeneratorService
{
    private const PAYLOAD_FORMAT_INDICATOR = '00';
    private const POINT_OF_INITIATION_METHOD = '01';
    private const MERCHANT_ACCOUNT_INFORMATION = '29';
    private const TRANSACTION_CURRENCY = '53';
    private const TRANSACTION_AMOUNT = '54';
    private const COUNTRY_CODE = '58';
    private const ADDITIONAL_DATA = '62';
    private const CRC = '63';
    private const AID_PROMPTPAY = 'A000000677010111';
    private const CURRENCY_THB = '764';
    private const COUNTRY_TH = 'TH';

    public function generatePayload(string $recipientId, ?string $amount = null, ?string $billReference = null): string
    {
        $formattedRecipient = $this->formatRecipientId($this->normalizeRecipientId($recipientId));
        $formattedAmount = $amount === null ? null : $this->normalizeAmount($amount);
        $billReference = $billReference === null ? null : $this->normalizeBillReference($billReference);

        $tag29Value = $this->formatTlv('00', self::AID_PROMPTPAY)
            .$this->formatTlv('01', $formattedRecipient);

        $payload = $this->formatTlv(self::PAYLOAD_FORMAT_INDICATOR, '01')
            .$this->formatTlv(self::POINT_OF_INITIATION_METHOD, $formattedAmount === null ? '11' : '12')
            .$this->formatTlv(self::MERCHANT_ACCOUNT_INFORMATION, $tag29Value)
            .$this->formatTlv(self::TRANSACTION_CURRENCY, self::CURRENCY_THB);

        if ($formattedAmount !== null) {
            $payload .= $this->formatTlv(self::TRANSACTION_AMOUNT, $formattedAmount);
        }

        $payload .= $this->formatTlv(self::COUNTRY_CODE, self::COUNTRY_TH);

        if ($billReference !== null) {
            $payload .= $this->formatTlv(self::ADDITIONAL_DATA, $this->formatTlv('05', $billReference));
        }

        $payloadWithCrcPrefix = $payload.self::CRC.'04';

        return $payloadWithCrcPrefix.$this->calculateCrc16Ccitt($payloadWithCrcPrefix);
    }

    public function isValidRecipientId(string $recipientId): bool
    {
        try {
            $this->formatRecipientId($this->normalizeRecipientId($recipientId));

            return true;
        } catch (FinancialException) {
            return false;
        }
    }

    private function normalizeRecipientId(string $recipientId): string
    {
        $normalized = preg_replace('/[\s-]+/', '', trim($recipientId)) ?? '';
        if (str_starts_with($normalized, '+66')) {
            $normalized = '0'.substr($normalized, 3);
        }

        if ($normalized === '' || ! ctype_digit($normalized)) {
            throw FinancialException::withCode(
                'invalid_promptpay_recipient',
                'PromptPay recipient must contain only a supported numeric identifier.',
            );
        }

        return $normalized;
    }

    private function formatRecipientId(string $id): string
    {
        $length = strlen($id);
        if ($length === 10 && str_starts_with($id, '0')) {
            return '0066'.substr($id, 1);
        }

        if ($length === 13 || $length === 15) {
            return $id;
        }

        throw FinancialException::withCode(
            'invalid_promptpay_recipient',
            'PromptPay recipient must be a 10-digit mobile, 13-digit national/tax ID, or 15-digit e-wallet ID.',
        );
    }

    private function normalizeAmount(string $amount): string
    {
        if (preg_match('/^(?:0|[1-9][0-9]*)\.[0-9]{2}$/D', $amount) !== 1 || bccomp($amount, '0.00', 2) <= 0) {
            throw FinancialException::withCode(
                'invalid_promptpay_amount',
                'PromptPay amount must be a positive decimal string with exactly two decimal places.',
            );
        }

        return bcadd($amount, '0.00', 2);
    }

    private function normalizeBillReference(string $billReference): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,24}$/D', $billReference) !== 1) {
            throw FinancialException::withCode(
                'invalid_promptpay_bill_reference',
                'PromptPay bill reference must be 1-25 safe ASCII characters.',
            );
        }

        return $billReference;
    }

    private function formatTlv(string $tag, string $value): string
    {
        $length = strlen($value);
        if ($length > 99) {
            throw new InvalidArgumentException('PromptPay TLV value exceeds the EMV two-digit length field.');
        }

        return $tag.str_pad((string) $length, 2, '0', STR_PAD_LEFT).$value;
    }

    public function calculateCrc16Ccitt(string $data): string
    {
        $crc = 0xFFFF;
        $polynomial = 0x1021;
        $bytes = unpack('C*', $data);
        if ($bytes === false) {
            throw new InvalidArgumentException('Unable to calculate PromptPay CRC.');
        }

        foreach ($bytes as $byte) {
            for ($i = 0; $i < 8; $i++) {
                $bit = (($byte >> (7 - $i)) & 1) === 1;
                $c15 = (($crc >> 15) & 1) === 1;
                $crc <<= 1;
                if ($c15 ^ $bit) {
                    $crc ^= $polynomial;
                }
            }
        }

        return strtoupper(str_pad(dechex($crc & 0xFFFF), 4, '0', STR_PAD_LEFT));
    }
}
