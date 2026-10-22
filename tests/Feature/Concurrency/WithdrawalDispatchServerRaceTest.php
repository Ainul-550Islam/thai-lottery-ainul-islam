<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\WithdrawalStatus;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalService;
use Tests\Feature\Payment\PaymentTestCase;
use Tests\Support\RunsServerFinancialRaces;

final class WithdrawalDispatchServerRaceTest extends PaymentTestCase
{
    use RunsServerFinancialRaces;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireServerRace();
    }

    private function race(string $mode): void
    {
        $player = $this->createPlayer('1000.00', Currency::THB);
        $row = app(WithdrawalService::class)->request($player['wallet'], Money::of('400.00', Currency::THB), PaymentMethod::BankTransfer);
        app(WithdrawalApprovalService::class)->approve($row);
        $out = $this->serverRace(array_fill(0, 2, ['action' => 'dispatch', 'withdrawal_id' => (int) $row->id, 'mode' => $mode]),
            'wallets', (int) $player['wallet']->id);
        $this->assertSame(['dispatched', 'dispatched'], array_column($out, 'outcome'), json_encode($out));
        $this->assertSame(1, max(array_column($out, 'synthetic_calls')));
        $this->assertSame([true, true], array_column($out, 'pending'));
        if ($mode === 'timeout') {
            $this->assertSame([true, true], array_column($out, 'unknown'));
        }
        $this->assertSame(WithdrawalStatus::Processing, $row->fresh()->status);
        $this->assertSame($mode === 'pending' ? 'pending' : 'unknown', $row->fresh()->metadata['outbound_dispatch']['state']);
        $this->assertSame('1000.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame('400.00', (string) $player['wallet']->fresh()->locked_balance);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_concurrent_pending_dispatch_has_one_external_invocation(): void
    {
        $this->race('pending');
    }

    public function test_concurrent_uncertain_dispatch_preserves_hold_and_sends_once(): void
    {
        $this->race('timeout');
    }
}
