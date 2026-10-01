@extends('layouts.app')

@section('title', 'Commission Accruals Journal — Agent')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-slate-800 pb-6">
        <div>
            <div class="flex items-center space-x-2">
                <a href="{{ route('agent.dashboard') }}" class="text-xs text-emerald-400 hover:underline">&larr; Agent Dashboard</a>
                <span class="text-xs text-slate-600">/</span>
                <span class="text-xs text-slate-400 font-mono">COMMISSIONS</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight mt-1">Commission Accruals Journal</h1>
        </div>

        <div class="flex items-center space-x-3">
            <span class="px-3 py-1 rounded-full text-xs font-bold font-mono bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                Authoritative Accruals
            </span>
        </div>
    </div>

    <!-- Commissions Table -->
    <div class="tl-glass-panel p-6 shadow-xl space-y-4">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-xs text-slate-400 uppercase font-semibold">
                        <th class="py-3 px-4">Commission Ref</th>
                        <th class="py-3 px-4">Draw ID</th>
                        <th class="py-3 px-4 text-right">Stake Amount</th>
                        <th class="py-3 px-4 text-right">Commission (5%)</th>
                        <th class="py-3 px-4 text-center">Status</th>
                        <th class="py-3 px-4 text-right">Accrued Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($commissions ?? [] as $comm)
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="py-3.5 px-4 font-mono font-bold text-slate-200">
                                {{ $comm->reference_number ?? ('COMM-' . $comm->id) }}
                            </td>
                            <td class="py-3.5 px-4 font-mono text-slate-400 text-xs">
                                Draw #{{ $comm->draw_id }}
                            </td>
                            <td class="py-3.5 px-4 text-right font-mono text-slate-300">
                                {{ \App\Services\Finance\Money::of((string) $comm->stake_amount, $comm->currency ?? \App\Enums\Currency::THB)->format() }}
                            </td>
                            <td class="py-3.5 px-4 text-right font-mono font-bold text-emerald-400">
                                {{ \App\Services\Finance\Money::of((string) $comm->commission_amount, $comm->currency ?? \App\Enums\Currency::THB)->format() }}
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                @php
                                    $st = strtolower((string) ($comm->status->value ?? $comm->status));
                                    $badge = match($st) {
                                        'paid' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30',
                                        'cancelled', 'reversed' => 'bg-rose-500/10 text-rose-400 border-rose-500/30',
                                        default => 'bg-amber-500/10 text-amber-400 border-amber-500/30',
                                    };
                                @endphp
                                <span class="px-2.5 py-1 rounded-full text-xs font-bold uppercase border {{ $badge }}">
                                    {{ $st }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4 text-right text-xs text-slate-400 font-mono">
                                {{ $comm->created_at?->toIso8601String() }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center text-slate-500 text-xs">
                                No commission records accrued yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(isset($commissions) && method_exists($commissions, 'links'))
            <div class="pt-4 border-t border-slate-800">
                {{ $commissions->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
