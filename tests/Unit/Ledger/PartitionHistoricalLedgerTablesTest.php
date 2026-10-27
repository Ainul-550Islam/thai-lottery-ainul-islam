<?php

declare(strict_types=1);

namespace Tests\Unit\Ledger;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class PartitionHistoricalLedgerTablesTest extends TestCase
{
    public function test_computes_correct_partition_date_ranges(): void
    {
        $testDate = Carbon::create(2026, 10, 1, 0, 0, 0);

        $partitionSuffix = $testDate->format('Y_m');
        $fromStr = $testDate->format('Y-m-d 00:00:00');
        $toStr = $testDate->copy()->addMonth()->format('Y-m-d 00:00:00');

        $this->assertSame('2026_10', $partitionSuffix);
        $this->assertSame('2026-10-01 00:00:00', $fromStr);
        $this->assertSame('2026-11-01 00:00:00', $toStr);

        $expectedSql = "CREATE TABLE IF NOT EXISTS ledger_entries_2026_10 PARTITION OF ledger_entries FOR VALUES FROM ('2026-10-01 00:00:00') TO ('2026-11-01 00:00:00');";
        $this->assertStringContainsString('ledger_entries_2026_10', $expectedSql);
    }
}
