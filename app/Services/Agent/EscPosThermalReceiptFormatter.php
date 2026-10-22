<?php

declare(strict_types=1);

namespace App\Services\Agent;

class EscPosThermalReceiptFormatter
{
    // ESC/POS Standard Control Bytes
    private const ESC = "\x1B";

    private const GS = "\x1D";

    private const CMD_INIT = "\x1B\x40";

    private const CMD_ALIGN_LEFT = "\x1B\x61\x00";

    private const CMD_ALIGN_CENTER = "\x1B\x61\x01";

    private const CMD_ALIGN_RIGHT = "\x1B\x61\x02";

    private const CMD_BOLD_ON = "\x1B\x45\x01";

    private const CMD_BOLD_OFF = "\x1B\x45\x00";

    private const CMD_DOUBLE_HEIGHT_ON = "\x1B\x21\x10";

    private const CMD_DOUBLE_HEIGHT_OFF = "\x1B\x21\x00";

    private const CMD_CUT_PAPER = "\x1D\x56\x41\x00"; // Full cut with feed

    /**
     * Format a standard lottery ticket sale into an ESC/POS binary receipt string.
     *
     * @param array{
     *     receipt_number: string,
     *     agent_code: string,
     *     agent_name: string,
     *     draw_id: string,
     *     draw_date: string,
     *     entries: array<int, array{number: string, bet_type: string, amount: string}>,
     *     total_amount: string,
     *     digital_signature: string,
     *     issued_at: string
     * } $ticketData
     * @return string Binary ESC/POS command stream
     */
    public function formatReceipt(array $ticketData): string
    {
        $out = '';

        // 1. Initialize Printer
        $out .= self::CMD_INIT;

        // 2. Header (Center Aligned)
        $out .= self::CMD_ALIGN_CENTER;
        $out .= self::CMD_BOLD_ON;
        $out .= self::CMD_DOUBLE_HEIGHT_ON;
        $out .= "THAI LOTTERY ENTERPRISE\n";
        $out .= self::CMD_DOUBLE_HEIGHT_OFF;
        $out .= "GOVERNMENT LOTTERY DISTRIBUTOR\n";
        $out .= self::CMD_BOLD_OFF;
        $out .= "================================\n";

        // 3. Metadata
        $out .= self::CMD_ALIGN_LEFT;
        $out .= 'Receipt No: '.($ticketData['receipt_number'] ?? 'N/A')."\n";
        $out .= 'Agent Code: '.($ticketData['agent_code'] ?? 'N/A').' ('.($ticketData['agent_name'] ?? '').")\n";
        $out .= 'Draw ID   : '.($ticketData['draw_id'] ?? 'N/A')."\n";
        $out .= 'Draw Date : '.($ticketData['draw_date'] ?? 'N/A')."\n";
        $out .= 'Issued At : '.($ticketData['issued_at'] ?? date('Y-m-d H:i:s'))."\n";
        $out .= "--------------------------------\n";

        // 4. Ticket Entries Table
        $out .= self::CMD_BOLD_ON;
        $out .= sprintf("%-10s %-12s %8s\n", 'NUMBER', 'TYPE', 'THB');
        $out .= self::CMD_BOLD_OFF;
        $out .= "--------------------------------\n";

        foreach ($ticketData['entries'] as $entry) {
            $num = str_pad((string) $entry['number'], 6, ' ', STR_PAD_RIGHT);
            $type = str_pad((string) $entry['bet_type'], 12, ' ', STR_PAD_RIGHT);
            $amt = number_format((float) $entry['amount'], 2, '.', '');
            $amtPadded = str_pad($amt, 8, ' ', STR_PAD_LEFT);

            $out .= sprintf("%s %s %s\n", $num, $type, $amtPadded);
        }

        $out .= "================================\n";

        // 5. Total
        $out .= self::CMD_ALIGN_RIGHT;
        $out .= self::CMD_BOLD_ON;
        $out .= 'TOTAL AMOUNT: '.number_format((float) $ticketData['total_amount'], 2)." THB\n";
        $out .= self::CMD_BOLD_OFF;

        // 6. Security Digital Signature & Verification Notice
        $out .= self::CMD_ALIGN_CENTER;
        $out .= "\nDIGITAL SIGNATURE HASH:\n";
        $out .= substr($ticketData['digital_signature'], 0, 32)."\n";
        $out .= substr($ticketData['digital_signature'], 32, 32)."\n";
        $out .= "\nKeep this slip for prize redemption.\n";
        $out .= "Verify online at verify.thai-lottery.internal\n";
        $out .= "\n\n\n"; // Feed lines

        // 7. Cut Paper
        $out .= self::CMD_CUT_PAPER;

        return $out;
    }
}
