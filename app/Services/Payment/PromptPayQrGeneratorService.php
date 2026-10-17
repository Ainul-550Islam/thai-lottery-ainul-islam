<?php

declare(strict_types=1);

namespace App\Services\Payment;

class PromptPayQrGeneratorService
{
    private const PAYLOAD_FORMAT_INDICATOR = '00';
    private const POINT_OF_INITIATION_METHOD = '01';
    private const MERCHANT_ACCOUNT_INFORMATION = '29';
    private const TRANSACTION_CURRENCY = '53';
    private const TRANSACTION_AMOUNT = '54';
    private const COUNTRY_CODE = '58';
    private const CRC = '63';

    private const AID_PROMPTPAY = 'A000000677010111';
    private const CURRENCY_THB = '764'; // ISO 4217 for Thai Baht
    private const COUNTRY_TH = 'TH';

    /**
     * Generate an EMVCo-compliant dynamic or static PromptPay QR payload string.
     *
     * @param string $recipientId Mobile number (08XXXXXXXX), National ID (13 digits), or Tax ID (15 digits)
     * @param string|null $amount Exact amount in THB formatted to 2 decimal places (e.g., '100.00'), or null for static
     * @return string Raw EMVCo QR code string ready for QR rendering
     */
    public function generatePayload(string $recipientId, ?string $amount = null): string
    {
        $sanitizedId = preg_replace('/[^0-9]/', '', $recipientId) ?? '';
        $formattedRecipient = $this->formatRecipientId($sanitizedId);

        // Build Tag 29 (Merchant Account Information - PromptPay)
        $subTag00 = $this->formatTlv('00', self::AID_PROMPTPAY);
        $subTag01 = $this->formatTlv('01', $formattedRecipient);
        $tag29Value = $subTag00 . $subTag01;

        $isDynamic = !empty($amount) && bccomp((string) $amount, '0.00', 2) > 0;

        $payload = '';
        $payload .= $this->formatTlv(self::PAYLOAD_FORMAT_INDICATOR, '01');
        $payload .= $this->formatTlv(self::POINT_OF_INITIATION_METHOD, $isDynamic ? '12' : '11'); // 12 = Dynamic, 11 = Static
        $payload .= $this->formatTlv(self::MERCHANT_ACCOUNT_INFORMATION, $tag29Value);
        $payload .= $this->formatTlv(self::COUNTRY_CODE, self::COUNTRY_TH);
        $payload .= $this->formatTlv(self::TRANSACTION_CURRENCY, self::CURRENCY_THB);

        if ($isDynamic) {
            $formattedAmount = number_format((float) $amount, 2, '.', '');
            $payload .= $this->formatTlv(self::TRANSACTION_AMOUNT, $formattedAmount);
        }

        // Tag 63 (CRC-16-CCITT) placeholder
        $payloadWithCrcPrefix = $payload . self::CRC . '04';
        $checksum = $this->calculateCrc16Ccitt($payloadWithCrcPrefix);

        return $payloadWithCrcPrefix . $checksum;
    }

    /**
     * Formats recipient ID into PromptPay standard format.
     */
    private function formatRecipientId(string $id): string
    {
        $length = strlen($id);

        if ($length === 10 && str_starts_with($id, '0')) {
            // Mobile Number: replace leading 0 with 0066 (e.g. 0812345678 -> 0066812345678)
            return '0066' . substr($id, 1);
        }

        if ($length === 9 && !str_starts_with($id, '0')) {
            return '0066' . $id;
        }

        // National ID (13 digits) or Tax ID (15 digits)
        return $id;
    }

    /**
     * Formats a Tag-Length-Value (TLV) segment.
     */
    private function formatTlv(string $tag, string $value): string
    {
        $length = str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT);
        return $tag . $length . $value;
    }

    /**
     * Calculates CRC-16-CCITT (Polynomial 0x1021, Initial value 0xFFFF).
     */
    public function calculateCrc16Ccitt(string $data): string
    {
        $crc = 0xFFFF;
        $polynomial = 0x1021;
        $bytes = unpack('C*', $data);

        if ($bytes === false) {
            return '0000';
        }

        foreach ($bytes as $b) {
            for ($i = 0; $i < 8; $i++) {
                $bit = (($b >> (7 - $i)) & 1) === 1;
                $c15 = (($crc >> 15) & 1) === 1;
                $crc <<= 1;
                if ($c15 ^ $bit) {
                    $crc ^= $polynomial;
                }
            }
        }

        $crc &= 0xFFFF;

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
