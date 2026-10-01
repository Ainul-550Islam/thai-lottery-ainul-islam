@extends('layouts.app')

@section('title', 'AML Compliance & Player Protection Desk — Admin')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-slate-800 pb-6">
        <div>
            <div class="flex items-center space-x-2">
                <a href="{{ route('admin.dashboard') }}" class="text-xs text-emerald-400 hover:underline">&larr; Admin Console</a>
                <span class="text-xs text-slate-600">/</span>
                <span class="text-xs text-slate-400 font-mono">COMPLIANCE</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight mt-1">AML &amp; Responsible Gaming Desk</h1>
        </div>

        <div class="flex items-center space-x-3">
            <span class="px-3 py-1 rounded-full text-xs font-bold font-mono bg-purple-500/10 text-purple-400 border border-purple-500/30">
                AML Engine v1
            </span>
        </div>
    </div>

    <!-- Active Compliance Cases Table -->
    <div class="tl-glass-panel p-6 shadow-xl space-y-4">
        <h2 class="text-lg font-bold text-white">Active Compliance &amp; AML Cases</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-xs text-slate-400 uppercase font-semibold">
                        <th class="py-3 px-4">Case Ref</th>
                        <th class="py-3 px-4">Subject User</th>
                        <th class="py-3 px-4">Trigger / Category</th>
                        <th class="py-3 px-4 text-center">AML Risk Score</th>
                        <th class="py-3 px-4 text-center">Status</th>
                        <th class="py-3 px-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($cases ?? [] as $case)
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="py-3.5 px-4 font-mono font-bold text-slate-200">
                                {{ $case->case_number ?? ('CASE-' . $case->id) }}
                            </td>
                            <td class="py-3.5 px-4">
                                <span class="font-bold text-white block">{{ $case->subject?->name ?? 'User #' . $case->subject_user_id }}</span>
                                <span class="text-[11px] text-slate-500 font-mono">ID: {{ $case->subject_user_id }}</span>
                            </td>
                            <td class="py-3.5 px-4 text-xs text-slate-300">
                                {{ $case->category->value ?? (string) $case->category }}
                            </td>
                            <td class="py-3.5 px-4 text-center font-mono font-bold">
                                @php
                                    $score = (int) ($case->risk_score ?? 0);
                                    $scoreColor = $score > 70 ? 'text-rose-400' : ($score > 35 ? 'text-amber-400' : 'text-emerald-400');
                                @endphp
                                <span class="{{ $scoreColor }}">{{ $score }} / 100</span>
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                <span class="px-2.5 py-1 rounded-full text-xs font-bold uppercase bg-amber-500/10 text-amber-400 border border-amber-500/30">
                                    {{ $case->status->value ?? (string) $case->status }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4 text-right">
                                <a href="#" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-bold rounded-lg border border-slate-700 transition">
                                    Investigate
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center text-slate-500 text-xs">
                                No open compliance or AML escalation cases.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
