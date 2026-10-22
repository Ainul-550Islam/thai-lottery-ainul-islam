@extends('layouts.app')

@section('title', 'KYC Document Verification Desk — Admin')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-slate-800 pb-6">
        <div>
            <div class="flex items-center space-x-2">
                <a href="{{ route('admin.dashboard') }}" class="text-xs text-emerald-400 hover:underline">&larr; Admin Console</a>
                <span class="text-xs text-slate-600">/</span>
                <span class="text-xs text-slate-400 font-mono">KYC-DESK</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight mt-1">KYC Verification Review Desk</h1>
        </div>

        <div class="flex items-center space-x-3">
            <span class="px-3 py-1 rounded-full text-xs font-bold font-mono bg-sky-500/10 text-sky-400 border border-sky-500/30">
                Encrypted Private Disk
            </span>
        </div>
    </div>

    <!-- KYC Submissions Table -->
    <div class="tl-glass-panel p-6 shadow-xl space-y-4">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-xs text-slate-400 uppercase font-semibold">
                        <th class="py-3 px-4">Doc ID</th>
                        <th class="py-3 px-4">User</th>
                        <th class="py-3 px-4">Document Type</th>
                        <th class="py-3 px-4">MIME / Size</th>
                        <th class="py-3 px-4 text-center">Status</th>
                        <th class="py-3 px-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($documents ?? [] as $doc)
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="py-3.5 px-4 font-mono font-bold text-slate-200">
                                #{{ $doc->id }}
                            </td>
                            <td class="py-3.5 px-4">
                                <span class="font-bold text-white block">{{ $doc->owner?->name ?? 'User #' . $doc->user_id }}</span>
                                <span class="text-[11px] text-slate-500 font-mono">ID: {{ $doc->user_id }}</span>
                            </td>
                            <td class="py-3.5 px-4">
                                <span class="px-2.5 py-0.5 rounded-lg text-xs font-bold uppercase bg-slate-800 text-slate-300 border border-slate-700">
                                    {{ $doc->document_type->value ?? (string) $doc->document_type }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4 text-xs font-mono text-slate-400">
                                {{ $doc->mime_type }} ({{ number_format((float) ($doc->file_size / 1024), 1) }} KB)
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                @php
                                    $st = strtolower((string) ($doc->status->value ?? $doc->status));
                                    $badge = match($st) {
                                        'verified', 'approved' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30',
                                        'rejected' => 'bg-rose-500/10 text-rose-400 border-rose-500/30',
                                        default => 'bg-amber-500/10 text-amber-400 border-amber-500/30',
                                    };
                                @endphp
                                <span class="px-2.5 py-1 rounded-full text-xs font-bold uppercase border {{ $badge }}">
                                    {{ $st }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4 text-right">
                                <div class="flex items-center justify-end space-x-2">
                                    <a href="{{ route('admin.kyc.download', $doc->id) }}" class="px-2.5 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-bold rounded-lg border border-slate-700 transition">
                                        Download Stream
                                    </a>

                                    @if(in_array($st, ['pending', 'under_review']))
                                        <form method="POST" action="{{ route('admin.kyc.approve', $doc->id) }}">
                                            @csrf
                                            <button type="submit" class="px-2.5 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs rounded-lg transition" onclick="return confirm('Approve verification document?')">
                                                Approve
                                            </button>
                                        </form>

                                        <form method="POST" action="{{ route('admin.kyc.reject', $doc->id) }}">
                                            @csrf
                                            <input type="hidden" name="reason" value="Document unreadable or invalid format.">
                                            <button type="submit" class="px-2.5 py-1.5 bg-rose-600 hover:bg-rose-500 text-white font-bold text-xs rounded-lg transition" onclick="return confirm('Reject verification document?')">
                                                Reject
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center text-slate-500 text-xs">
                                No pending KYC verification documents.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(isset($documents) && method_exists($documents, 'links'))
            <div class="pt-4 border-t border-slate-800">
                {{ $documents->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
