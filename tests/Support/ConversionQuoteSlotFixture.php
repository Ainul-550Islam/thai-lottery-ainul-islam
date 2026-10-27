<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\WalletConversionDirection;
use App\Exceptions\Wallet\ConversionException;
use Illuminate\Support\Facades\DB;

trait ConversionQuoteSlotFixture
{
    protected function slotFixture(): array
    {
        $f = $this->makeFundedMember();
        $f['win']->forceFill(['balance' => '100.00'])->save();

        return $f;
    }

    protected function slotRequest(array $f, string $key = 'slot-quote-request-0000001', WalletConversionDirection $direction = WalletConversionDirection::CashToWin)
    {
        return $this->request($f['user'], amount: '30.00', direction: $direction, idempotencyKey: $key);
    }

    protected function slotRefuses(callable $operation, string $code): void
    {
        try {
            $operation();
            $this->fail('Quote admission or execution accepted missing authority.');
        } catch (ConversionException $e) {
            $this->assertSame($code, $e->errorCode());
        }
    }

    protected function slotPaper(bool $includeQuotes = true): array
    {
        $paper = [];
        $tables = ['wallets', 'wallet_conversions', 'financial_transactions', 'ledger_entries', 'ledger_accounts'];
        if ($includeQuotes) {
            $tables[] = 'wallet_conversion_quotes';
        }
        foreach ($tables as $table) {
            $paper[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $paper;
    }
}
