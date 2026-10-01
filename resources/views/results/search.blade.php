@extends('layouts.app')

@section('title', 'Search Results — Government Lottery')
@section('meta_robots', 'noindex, follow')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 space-y-8">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <h1 class="text-2xl font-bold text-white">Search Lottery Results</h1>
        <a href="{{ route('results.index') }}" class="text-sm text-emerald-400 underline">← All Results</a>
    </div>

    <!-- Search Form -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
        <form method="GET" action="{{ route('results.search') }}" class="flex flex-col sm:flex-row gap-3">
            <div class="flex-1 relative">
                <input type="text" name="q" value="{{ $query ?? '' }}" placeholder="Enter 6-digit number, draw number, or date (YYYY-MM-DD)"
                       maxlength="30"
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white font-mono text-sm focus:outline-none focus:border-emerald-500">
            </div>
            <button type="submit" class="px-6 py-3 bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-sm rounded-xl transition">
                Search
            </button>
        </form>
    </div>

    @if (! empty($query))
        <section class="rounded-xl border border-slate-800 bg-slate-900/60 p-5">
            <h2 class="text-base font-semibold text-slate-200 mb-1">
                Search Results for: <span class="font-mono text-emerald-300">{{ e($query) }}</span>
            </h2>
            <p class="text-xs text-slate-400">
                Found {{ count($results) }} matching published record(s).
            </p>
        </section>

        <section class="rounded-xl border border-slate-800 bg-slate-900/60 overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-slate-800/80 text-slate-300">
                    <tr>
                        <th class="p-3">Draw</th>
                        <th class="p-3">Date</th>
                        <th class="p-3">1st Prize</th>
                        <th class="p-3">2-Digit Bottom</th>
                        <th class="p-3">Source</th>
                        <th class="p-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($results as $row)
                        <tr class="border-t border-slate-800">
                            <td class="p-3 font-mono text-slate-100">{{ $row['draw_number'] }}</td>
                            <td class="p-3 text-slate-400">{{ $row['draw_date'] }}</td>
                            <td class="p-3 font-mono text-emerald-300 font-bold">{{ $row['first_prize'] }}</td>
                            <td class="p-3 font-mono text-slate-300">{{ $row['bottom_two'] ?? '—' }}</td>
                            <td class="p-3">
                                <span class="px-2 py-0.5 rounded text-xs font-semibold {{ $row['fixture_sample'] ? 'bg-amber-500/20 text-amber-300' : 'bg-emerald-600/20 text-emerald-300' }}">
                                    {{ $row['source_state'] }}
                                </span>
                            </td>
                            <td class="p-3 text-xs text-emerald-400 font-semibold uppercase">Published</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="p-6 text-center text-slate-400" colspan="6">
                                No matching published results found for &ldquo;{{ e($query) }}&rdquo;.
                                Please verify the number and try again.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    @endif
</div>
@endsection
