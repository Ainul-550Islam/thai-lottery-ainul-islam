<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Currency;
use App\Services\Finance\LedgerPostingService;
use App\Services\Finance\WalletService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Guarantee the chart-of-accounts rows the conversion lane posts against.
 *
 * WHY A DEDICATED SEEDER EXISTS WHEN LedgerAccountSeeder ALREADY DOES THIS
 * LedgerAccountSeeder seeds the WHOLE chart and is the right thing to run on a
 * fresh install. This seeder exists for the narrower, more dangerous case: an
 * environment where the chart was seeded before the conversion lane existed, or
 * where a currency was added later. The conversion lane posts against three
 * accounts — the wallet liability the engine already uses, the transfer
 * counterpart, and fee revenue. If any one of them is missing, the engine
 * refuses the posting ("ledger_account_missing") rather than inventing an
 * account, which is correct but leaves the feature dead in the water.
 *
 * Running this seeder is therefore safe and idempotent at any time: it creates
 * only what is absent and never modifies an existing row, so it can be part of
 * every deploy without touching balances.
 */
class WalletConversionLedgerSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            WalletService::ACCOUNT_PLAYER_LIABILITY => [
                'Player Wallet Liability',
                'liability',
                'Player balances held by the platform, including Cash and Win wallets.',
            ],
            WalletService::ACCOUNT_ADJUSTMENT_EQUITY => [
                'Adjustment Equity',
                'equity',
                'Counterpart for internal transfers and manual corrections, including wallet conversions.',
            ],
            WalletService::ACCOUNT_FEE_REVENUE => [
                'Fee Revenue',
                'revenue',
                'Transaction and processing fees charged to players, including conversion fees.',
            ],
        ];

        // The conversion currency and the platform's primary currency are both
        // required to carry a full account set, and they are frequently the same
        // value — so the set is de-duplicated by the enum's backing value rather
        // than by comparing enum objects, which PHP cannot order or stringify
        // safely inside array_unique.
        $configured = Currency::tryFrom((string) config('conversions.currency', '')) ?? Currency::primary();

        $codes = array_values(array_unique([
            Currency::primary()->value,
            $configured->value,
        ]));

        $currencies = array_map(
            static fn (string $code): Currency => Currency::tryFrom($code) ?? Currency::primary(),
            $codes,
        );

        foreach ($currencies as $currency) {
            foreach ($accounts as $baseCode => [$name, $type, $description]) {
                $code = LedgerPostingService::accountCodeFor((string) $baseCode, $currency);

                $exists = DB::table('ledger_accounts')->where('code', $code)->exists();

                if ($exists) {
                    continue;
                }

                DB::table('ledger_accounts')->insert([
                    'code' => $code,
                    'name' => $currency === Currency::primary() ? $name : $name.' ('.$currency->value.')',
                    'type' => $type,
                    'description' => $description,
                    'currency' => $currency->value,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->command?->info(sprintf(
            'Conversion ledger accounts present for %d currency/currency set.',
            count($currencies),
        ));
    }
}
