<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Services\Agent\EscPosThermalReceiptFormatter;
use PHPUnit\Framework\TestCase;

class EscPosThermalReceiptFormatterTest extends TestCase
{
    private EscPosThermalReceiptFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new EscPosThermalReceiptFormatter();
    }

    public function test_formats_valid_escpos_thermal_receipt_byte_stream(): void
    {
        $ticketData = [
            'receipt_number' => 'POS-20261016-A1B2C3D4',
            'agent_code' => 'AGT-BKK-001',
            'agent_name' => 'Bangkok Central Outlet',
            'draw_id' => 'GLO-20261016-01',
            'draw_date' => '2026-10-16',
            'entries' => [
                ['number' => '000123', 'bet_type' => '6D_OFFICIAL', 'amount' => '80.0000'],
                ['number' => '456', 'bet_type' => '3D_DIRECT', 'amount' => '100.0000'],
            ],
            'total_amount' => '180.0000',
            'digital_signature' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
            'issued_at' => '2026-10-16 14:30:00',
        ];

        $rawBytes = $this->formatter->formatReceipt($ticketData);

        // 1. Must start with ESC @ (Init printer: 0x1B 0x40)
        $this->assertStringStartsWith("\x1B\x40", $rawBytes);

        // 2. Must end with GS V A 0 (Cut paper: 0x1D 0x56 0x41 0x00)
        $this->assertStringEndsWith("\x1D\x56\x41\x00", $rawBytes);

        // 3. Must contain ticket metadata strings
        $this->assertStringContainsString('THAI LOTTERY ENTERPRISE', $rawBytes);
        $this->assertStringContainsString('POS-20261016-A1B2C3D4', $rawBytes);
        $this->assertStringContainsString('AGT-BKK-001', $rawBytes);
        $this->assertStringContainsString('TOTAL AMOUNT: 180.00 THB', $rawBytes);
    }
}
