<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\WalletConversionDirection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait ConversionRollingLimitFixture
{
    protected function rollingFixture(): array
    {
        $f = $this->makeFundedMember();
        $f['win']->forceFill(['balance' => '100.00'])->save();

        return $f;
    }

    protected function rollingRequest(array $f, string $key = 'rolling-admission-request-0001', string $amount = '30.00', WalletConversionDirection $direction = WalletConversionDirection::CashToWin)
    {
        $quote = $this->quoteFor($f['user'], amount: $amount, direction: $direction, idempotencyKey: 'quote-'.$key);

        return $this->request($f['user'], amount: $amount, direction: $direction, idempotencyKey: $key, quoteReference: $quote->reference);
    }

    /** Synthetic eligibility facts only: never posted, enrolled, replayed or claimed as proved original accounting. */
    protected function syntheticAllowance(array $f, string $amount = '80.00', ?Carbon $at = null, array $overrides = []): int
    {
        $nonce = (string) Str::uuid();

        return DB::table('wallet_conversions')->insertGetId(array_replace([
            'reference' => 'LIMIT-'.$nonce, 'user_id' => $f['user']->id,
            'direction' => 'cash_to_win', 'status' => 'completed', 'currency' => 'THB',
            'from_wallet_id' => $f['cash']->id, 'to_wallet_id' => $f['win']->id,
            'from_wallet_type' => $f['cash']->type->value, 'to_wallet_type' => $f['win']->type->value,
            'amount_in' => $amount, 'amount_out' => $amount, 'fee_amount' => '0.00',
            'fee_rate' => '0.00000000', 'fee_bearer' => 'source', 'fee_rule_key' => 'cash_to_win',
            'fee_rule_version' => 'synthetic-eligibility-only', 'channel' => 'api',
            'idempotency_key' => hash('sha256', $nonce), 'completed_at' => ($at ?? Carbon::now())->toDateTimeString(),
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    protected function rollingPaper(): array
    {
        $paper = [];
        foreach (['wallets', 'wallet_conversions', 'wallet_conversion_quotes', 'financial_transactions', 'ledger_entries', 'ledger_accounts'] as $table) {
            $paper[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $paper;
    }
}
