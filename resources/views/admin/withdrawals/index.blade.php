@extends('layouts.app')

@section('title', 'Withdrawal Disbursements Review — Admin')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-slate-800 pb-6">
        <div>
            <div class="flex items-center space-x-2">
                <a href="{{ route('admin.dashboard') }}" class="text-xs text-emerald-400 hover:underline">&larr; Admin Console</a>
                <span class="text-xs text-slate-600">/</span>
                <span class="text-xs text-slate-400 font-mono">DISBURSEMENTS</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight mt-1">Withdrawal Disbursement Review</h1>
        </div>

        <div class="flex items-center space-x-3">
            <span class="px-3 py-1 rounded-full text-xs font-bold font-mono bg-amber-500/10 text-amber-400 border border-amber-500/30">
                Dual-Control Gate
            </span>
        </div>
    </div>

    <!-- Withdrawals Review Table -->
    <div class="tl-glass-panel p-6 shadow-xl space-y-4">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-xs text-slate-400 uppercase font-semibold">
                        <th class="py-3 px-4">Payout Ref</th>
                        <th class="py-3 px-4">User</th>
                        <th class="py-3 px-4">Destination (Masked)</th>
                        <th class="py-3 px-4 text-right">Amount</th>
                        <th class="py-3 px-4 text-center">Status</th>
                        <th class="py-3 px-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($withdrawals ?? [] as $withdrawal)
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="py-3.5 px-4 font-mono font-bold text-slate-200">
                                {{ $withdrawal->reference_number ?? ('WD-' . $withdrawal->id) }}
                            </td>
                            <td class="py-3.5 px-4">
                                <span class="font-bold text-white block">{{ $withdrawal->user?->name ?? 'User #' . $withdrawal->user_id }}</span>
                                <span class="text-[11px] text-slate-500 font-mono">ID: {{ $withdrawal->user_id }}</span>
                            </td>
                            <td class="py-3.5 px-4 font-mono text-xs text-slate-300">
                                @php
                                    $dest = is_array($withdrawal->destination_details) ? $withdrawal->destination_details : json_decode((string) $withdrawal->destination_details, true);
                                    $acc = (string) ($dest['account_number'] ?? $dest['account'] ?? '—');
                                    $masked = strlen($acc) > 4 ? str_repeat('*', strlen($acc) - 4) . substr($acc, -4) : $acc;
                                @endphp
                                {{ $dest['bank'] ?? $dest['bank_name'] ?? 'Wire' }}: {{ $masked }}
                            </td>
                            <td class="py-3.5 px-4 text-right font-mono font-bold text-amber-400">
                                {{ \App\Services\Finance\Money::of((string) $withdrawal->amount, $withdrawal->currency ?? \App\Enums\Currency::THB)->format() }}
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                @php
                                    $st = strtolower((string) ($withdrawal->status->value ?? $withdrawal->status));
                                    $badge = match($st) {
                                        'completed' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30',
                                        'failed', 'rejected' => 'bg-rose-500/10 text-rose-400 border-rose-500/30',
                                        'processing' => 'bg-sky-500/10 text-sky-400 border-sky-500/30',
                                        default => 'bg-amber-500/10 text-amber-400 border-amber-500/30',
                                    };
                                @endphp
                                <span class="px-2.5 py-1 rounded-full text-xs font-bold uppercase border {{ $badge }}">
                                    {{ $st }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4 text-right">
                                @if(in_array($st, ['pending', 'under_review']))
                                    <div class="flex items-center justify-end space-x-2">
                                        <form method="POST" action="{{ route('admin.withdrawals.disburse', $withdrawal->id) }}">
                                            @csrf
                                            <button type="submit" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs rounded-lg transition" onclick="return confirm('Authorize and disburse this withdrawal?')">
                                                Disburse
                                            </button>
                                        </form>

                                        <form method="POST" action="{{ route('admin.withdrawals.reject', $withdrawal->id) }}">
                                            @csrf
                                            <button type="submit" class="px-3 py-1.5 bg-rose-600 hover:bg-rose-500 text-white font-bold text-xs rounded-lg transition" onclick="return confirm('Reject withdrawal and restore balance to user?')">
                                                Reject
                                            </button>
                                        </form>
                                    </div>
                                @else
                                    <span class="text-xs text-slate-500 font-mono">Settled</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center text-slate-500 text-xs">
                                No withdrawal disbursement requests in queue.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(isset($withdrawals) && method_exists($withdrawals, 'links'))
            <div class="pt-4 border-t border-slate-800">
                {{ $withdrawals->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
