@extends('layouts.app')

@section('title', 'Payment Transactions Ledger — Admin')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-slate-800 pb-6">
        <div>
            <div class="flex items-center space-x-2">
                <a href="{{ route('admin.dashboard') }}" class="text-xs text-emerald-400 hover:underline">&larr; Admin Console</a>
                <span class="text-xs text-slate-600">/</span>
                <span class="text-xs text-slate-400 font-mono">PAYMENTS</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight mt-1">Payment Transactions Ledger</h1>
        </div>

        <div class="flex items-center space-x-3">
            <span class="px-3 py-1 rounded-full text-xs font-bold font-mono bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                Audited Ledger
            </span>
        </div>
    </div>

    <!-- Payments Table Card -->
    <div class="tl-glass-panel p-6 shadow-xl space-y-4">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-xs text-slate-400 uppercase font-semibold">
                        <th class="py-3 px-4">Transaction Ref</th>
                        <th class="py-3 px-4">User</th>
                        <th class="py-3 px-4">Provider / Channel</th>
                        <th class="py-3 px-4 text-right">Amount</th>
                        <th class="py-3 px-4 text-center">Status</th>
                        <th class="py-3 px-4 text-right">Created At</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($payments ?? [] as $payment)
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="py-3.5 px-4 font-mono font-bold text-slate-200">
                                {{ $payment->reference_number ?? '—' }}
                            </td>
                            <td class="py-3.5 px-4">
                                <span class="font-bold text-white block">{{ $payment->user?->name ?? 'User #' . $payment->user_id }}</span>
                                <span class="text-[11px] text-slate-500 font-mono">ID: {{ $payment->user_id }}</span>
                            </td>
                            <td class="py-3.5 px-4">
                                <span class="px-2.5 py-0.5 rounded-lg text-xs font-bold uppercase bg-slate-800 text-slate-300 border border-slate-700">
                                    {{ $payment->channel->value ?? (string) $payment->channel }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4 text-right font-mono font-bold text-emerald-400">
                                {{ \App\Services\Finance\Money::of((string) $payment->amount, $payment->currency ?? \App\Enums\Currency::THB)->format() }}
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                @php
                                    $st = strtolower((string) ($payment->status->value ?? $payment->status));
                                    $statusBadge = match($st) {
                                        'completed', 'success', 'captured' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30',
                                        'failed', 'declined' => 'bg-rose-500/10 text-rose-400 border-rose-500/30',
                                        'cancelled' => 'bg-slate-800 text-slate-400 border-slate-700',
                                        default => 'bg-amber-500/10 text-amber-400 border-amber-500/30',
                                    };
                                @endphp
                                <span class="px-2.5 py-1 rounded-full text-xs font-bold uppercase border {{ $statusBadge }}">
                                    {{ $st }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4 text-right text-xs text-slate-400 font-mono">
                                {{ $payment->created_at?->toIso8601String() }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center text-slate-500 text-xs">
                                No payment transactions recorded in this period.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(isset($payments) && method_exists($payments, 'links'))
            <div class="pt-4 border-t border-slate-800">
                {{ $payments->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
