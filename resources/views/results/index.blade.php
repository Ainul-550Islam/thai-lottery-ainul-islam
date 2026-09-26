@extends('layouts.app')

@section('title', 'Results')

@php
    use App\Enums\GloSourceState;
    use App\Models\Draw;
    use App\Models\DrawResult;
    use Illuminate\Support\Facades\Cache;

    // Public projection only: latest ResultPublished/Completed draw with a result row.
    $latestQuery = Draw::query()
        ->whereIn('status', ['result_published', 'completed'])
        ->orderByDesc('scheduled_at')
        ->limit(12);

    $rows = [];
    foreach ($latestQuery->get() as $draw) {
        $result = DrawResult::query()->where('draw_id', $draw->getKey())->first();
        if ($result === null) {
            continue;
        }
        $meta = is_array($result->metadata) ? $result->metadata : [];
        $lane = is_array($meta['glo'] ?? null) ? $meta['glo'] : [];
        $provider = (string) ($lane['import_provider'] ?? 'unknown');
        $sourceState = $provider === 'fixture'
            ? GloSourceState::FixtureOnly
            : GloSourceState::OfficialSourceVerified;

        $rows[] = [
            'draw_number' => $draw->draw_number,
            'draw_date' => $draw->scheduled_at?->toDateString(),
            'first_prize' => $result->first_prize,
            'second_prize' => $result->second_prize ?? [],
            'third_prize' => $result->third_prize ?? [],
            'consolation_prizes' => $result->consolation_prizes ?? [],
            'source_state' => $sourceState->value,
            'fixture_sample' => $sourceState === GloSourceState::FixtureOnly,
            'result_version' => (string) ($lane['import_fingerprint'] ?? ('pub-'.$result->getKey())),
        ];
    }

    $currentStatus = Draw::query()->orderByDesc('scheduled_at')->first();
@endphp

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 space-y-8">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <h1 class="text-2xl font-bold text-white">Government Lottery results</h1>
        <a href="{{ route('home') }}" class="text-sm text-emerald-400 underline">← Home</a>
    </div>

    <section class="rounded-xl border border-slate-800 bg-slate-900/60 p-5">
        <h2 class="font-semibold text-slate-200 mb-1">Current draw status</h2>
        @if ($currentStatus !== null)
            <p class="text-sm text-slate-400">
                Latest scheduled draw:
                <span class="font-mono text-emerald-300">{{ $currentStatus->draw_number }}</span>
                · status <span class="uppercase tracking-wide text-amber-300">{{ $currentStatus->status->value }}</span>
                · {{ $currentStatus->scheduled_at?->toDateString() }}
            </p>
        @else
            <p class="text-sm text-slate-400">No draw scheduled yet.</p>
        @endif
    </section>

    <section class="rounded-xl border border-slate-800 bg-slate-900/60 overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-slate-800/80 text-slate-300">
                <tr>
                    <th class="p-3">Draw</th>
                    <th class="p-3">Date</th>
                    <th class="p-3">1st</th>
                    <th class="p-3">2nd</th>
                    <th class="p-3">3rd</th>
                    <th class="p-3">Source</th>
                    <th class="p-3">Version</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr class="border-t border-slate-800">
                        <td class="p-3 font-mono text-slate-100">{{ $row['draw_number'] }}</td>
                        <td class="p-3 text-slate-400">{{ $row['draw_date'] }}</td>
                        <td class="p-3 font-mono text-emerald-300">{{ $row['first_prize'] }}</td>
                        <td class="p-3 font-mono text-slate-300">
                            @if (is_array($row['second_prize']))
                                {{ implode(', ', $row['second_prize']) }}
                            @else
                                {{ $row['second_prize'] }}
                            @endif
                        </td>
                        <td class="p-3 font-mono text-slate-300">
                            @if (is_array($row['third_prize']))
                                {{ implode(', ', array_slice($row['third_prize'], 0, 3)) }}…
                            @else
                                {{ $row['third_prize'] }}
                            @endif
                        </td>
                        <td class="p-3">
                            <span class="px-2 py-0.5 rounded text-xs font-semibold
                                {{ $row['fixture_sample'] ? 'bg-amber-500/20 text-amber-300 border border-amber-600/40' : 'bg-emerald-600/20 text-emerald-300 border border-emerald-700/40' }}">
                                {{ $row['source_state'] }}
                            </span>
                        </td>
                        <td class="p-3 text-xs font-mono text-slate-500">{{ $row['result_version'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="p-4 text-slate-400" colspan="7">
                            No verified results published yet. Unverified or fixture rows are labeled
                            <code>FIXTURE_ONLY</code> and are never called official.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="rounded-xl border border-slate-800 bg-slate-900/40 p-5 text-sm text-slate-400">
        <p>
            History API (2-year window, rate-limited):
            <a class="underline text-emerald-300" href="/api/v1/glo/results/history">/api/v1/glo/results/history</a>
            · Six-digit check:
            <a class="underline text-emerald-300" href="/api/v1/glo/results/check/000001">/api/v1/glo/results/check/{number}</a>
        </p>
        <p class="mt-2 text-xs">
            Result versions are immutable once published; corrections create a new versioned row with audit.
        </p>
    </section>
</div>
@endsection
