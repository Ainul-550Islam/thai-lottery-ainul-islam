@extends('layouts.app')

@section('title', 'Commission Settlements & Disbursements — Agent')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-slate-800 pb-6">
        <div>
            <div class="flex items-center space-x-2">
                <a href="{{ route('agent.dashboard') }}" class="text-xs text-emerald-400 hover:underline">&larr; Agent Dashboard</a>
                <span class="text-xs text-slate-600">/</span>
                <span class="text-xs text-slate-400 font-mono">SETTLEMENTS</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight mt-1">Settlement &amp; Payout Journal</h1>
        </div>

        <div class="flex items-center space-x-3">
            <span class="px-3 py-1 rounded-full text-xs font-bold font-mono bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                Direct Wallet Credit
            </span>
        </div>
    </div>

    <!-- Settlements Table -->
    <div class="tl-glass-panel p-6 shadow-xl space-y-4">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-xs text-slate-400 uppercase font-semibold">
                        <th class="py-3 px-4">Settlement Ref</th>
                        <th class="py-3 px-4">Event Type</th>
                        <th class="py-3 px-4 text-right">Settled Amount</th>
                        <th class="py-3 px-4 text-center">Disbursement Status</th>
                        <th class="py-3 px-4 text-right">Settled At</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($settlements ?? [] as $settlement)
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="py-3.5 px-4 font-mono font-bold text-slate-200">
                                {{ $settlement->reference_number ?? ('STL-' . $settlement->id) }}
                            </td>
                            <td class="py-3.5 px-4 text-xs font-bold text-slate-300">
                                Draw Settlement #{{ $settlement->draw_id }}
                            </td>
                            <td class="py-3.5 px-4 text-right font-mono font-bold text-emerald-400">
                                {{ \App\Services\Finance\Money::of((string) $settlement->commission_amount, $settlement->currency ?? \App\Enums\Currency::THB)->format() }}
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                <span class="px-2.5 py-1 rounded-full text-xs font-bold uppercase bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                                    Disbursed to Wallet
                                </span>
                            </td>
                            <td class="py-3.5 px-4 text-right text-xs text-slate-400 font-mono">
                                {{ $settlement->paid_at?->toIso8601String() ?? $settlement->updated_at?->toIso8601String() }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-12 text-center text-slate-500 text-xs">
                                No commission settlements processed yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
