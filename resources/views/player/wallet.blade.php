@extends('layouts.app')

@section('title', __('player.wallet_title'))
@section('meta_description', __('player.wallet_lead'))
@section('meta_robots', 'noindex,nofollow')

@section('content')
@php
    $currencyCode = $selectedCurrency ?? ($wallet instanceof \App\Models\Wallet && $wallet->currency instanceof \App\Enums\Currency ? $wallet->currency->value : null);
    $currencyEnum = $currencyCode !== null ? \App\Enums\Currency::tryFrom((string) $currencyCode) : null;
@endphp
<div class="space-y-8">
    <div>
        <span class="tl-v2-eyebrow">{{ __('player.nav_wallet') }}</span>
        <h1 class="mt-1 text-2xl sm:text-3xl font-black text-[#FFF6D6] tracking-tight">{{ __('player.wallet_title') }}</h1>
        <p class="text-[#D4CAB3] text-sm mt-1">{{ __('player.wallet_lead') }}</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
        <div class="tl-v2-card border-[#D4AF37]/35">
            <span class="text-xs text-[#9D937D] font-bold uppercase tracking-wider block">{{ __('player.available_balance') }}</span>
            <div class="text-3xl font-black font-mono text-[#FFF6D6] mt-2" data-tabular-nums>
                @if ($wallet instanceof \App\Models\Wallet && $currencyEnum instanceof \App\Enums\Currency)
                    {{ \App\Services\Finance\Money::of((string) $wallet->balance, $currencyEnum)->format() }}
                @else
                    {{ __('player.not_configured') }}
                @endif
            </div>
            <span class="text-xs text-[#D4CAB3] mt-1.5 block">{{ __('player.available_balance_hint') }}</span>
        </div>

        <div class="tl-v2-card">
            <span class="text-xs text-[#9D937D] font-bold uppercase tracking-wider block">{{ __('player.total_deposited') }}</span>
            <div class="text-3xl font-black font-mono text-[#F5E6B8] mt-2" data-tabular-nums>
                @if ($wallet instanceof \App\Models\Wallet && $currencyEnum instanceof \App\Enums\Currency)
                    {{ \App\Services\Finance\Money::of((string) $totalDeposited, $currencyEnum)->format() }}
                @else
                    {{ __('player.not_configured') }}
                @endif
            </div>
            <span class="text-xs text-[#D4CAB3] mt-1.5 block">{{ __('player.total_deposited_hint') }}</span>
        </div>

        <div class="tl-v2-card">
            <span class="text-xs text-[#9D937D] font-bold uppercase tracking-wider block">{{ __('player.total_prizes') }}</span>
            <div class="text-3xl font-black font-mono text-[#86EFAC] mt-2" data-tabular-nums>
                @if ($wallet instanceof \App\Models\Wallet && $currencyEnum instanceof \App\Enums\Currency)
                    {{ \App\Services\Finance\Money::of((string) $totalPrizesWon, $currencyEnum)->format() }}
                @else
                    {{ __('player.not_configured') }}
                @endif
            </div>
            <span class="text-xs text-[#D4CAB3] mt-1.5 block">{{ __('player.total_prizes_hint') }}</span>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ route('player.deposit') }}" class="tl-v2-btn-gold text-sm">
            {{ __('player.deposit_title') }}
        </a>
        <a href="{{ route('player.withdraw') }}" class="tl-v2-btn-outline text-sm">
            {{ __('player.withdraw_title') }}
        </a>
        <a href="{{ route('player.wallet.conversion') }}" class="tl-v2-btn-outline text-sm">
            {{ __('wallet_conversion.page_title') }}
        </a>
    </div>

    <div class="tl-v2-card">
        <h2 class="text-lg font-extrabold text-[#FFF6D6] mb-5">{{ __('player.journal_title') }}</h2>

        <div class="tl-v2-table-wrap">
            <table class="tl-v2-table">
                <caption class="sr-only">{{ __('player.journal_title') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('player.col_transaction_ref') }}</th>
                        <th scope="col">{{ __('player.col_type') }}</th>
                        <th scope="col">{{ __('player.col_description') }}</th>
                        <th scope="col" class="text-right">{{ __('player.col_amount') }}</th>
                        <th scope="col" class="text-right">{{ __('player.col_date') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($transactions as $tx)
                        @php
                            $txCurrency = $tx->currency instanceof \App\Enums\Currency ? $tx->currency : null;
                            $txAmountLabel = $txCurrency instanceof \App\Enums\Currency
                                ? \App\Services\Finance\Money::of((string) $tx->amount, $txCurrency)->format()
                                : __('player.not_configured');
                            $txType = $tx->type instanceof \BackedEnum ? (string) $tx->type->value : (string) $tx->type;
                            $isCredit = in_array($txType, ['deposit', 'payout', 'prize_payout'], true);
                        @endphp
                        <tr>
                            <td class="font-mono font-bold text-[#FFF6D6]">
                                {{ $tx->reference_number ?? __('player.not_recorded') }}
                            </td>
                            <td>
                                <span class="tl-v2-pill {{ $isCredit ? 'tl-v2-pill--verified' : 'tl-v2-pill--danger' }}">
                                    {{ $txType }}
                                </span>
                            </td>
                            <td class="text-xs text-[#D4CAB3]">
                                {{ $tx->description ?? __('player.not_recorded') }}
                            </td>
                            <td class="text-right font-mono font-bold {{ $isCredit ? 'text-[#86EFAC]' : 'text-[#FAF6EC]' }}">
                                {{ $isCredit ? '+' : '-' }}{{ $txAmountLabel }}
                            </td>
                            <td class="text-right text-xs text-[#9D937D] font-mono">
                                {{ $tx->created_at?->format('M d, Y H:i') ?? __('player.not_recorded') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-12 text-center text-[#D4CAB3] text-sm">
                                {{ __('player.no_transactions_recorded') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(is_object($transactions) && method_exists($transactions, 'links'))
            <div class="pt-6">
                {{ $transactions->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
