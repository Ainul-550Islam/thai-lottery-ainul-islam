@extends('layouts.app')

@section('title', trans('results_search.title'))
@section('meta_robots', 'noindex, follow')

@section('content')
<div class="max-w-6xl mx-auto py-4 space-y-8">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <span class="tl-v2-eyebrow">{{ __('results.published_results') }}</span>
            <h1 class="mt-1 text-3xl font-black text-[#FFF6D6]">{{ trans('results_search.title') }}</h1>
        </div>
        <a href="{{ route('results.index') }}" class="tl-v2-btn-outline text-xs">
            {{ trans('results_search.all_results') }}
        </a>
    </div>

    <div class="tl-v2-card">
        <form method="GET" action="{{ route('results.search') }}" class="flex flex-col sm:flex-row gap-3">
            <div class="flex-1 relative">
                <label for="results-search-q" class="sr-only">{{ trans('results_search.title') }}</label>
                <input id="results-search-q" type="text" name="q" value="{{ $query ?? '' }}" placeholder="{{ trans('results_search.placeholder') }}"
                       maxlength="30"
                       class="tl-v2-input font-mono">
            </div>
            <button type="submit" class="tl-v2-btn-gold">
                {{ trans('results_search.search') }}
            </button>
        </form>
    </div>

    @if (! empty($query))
        <section class="tl-v2-subcard" role="status">
            <h2 class="text-base font-bold text-[#FFF6D6] mb-1">
                {{ trans('results_search.results_for', ['query' => $query]) }}
            </h2>
            <p class="text-xs text-[#D4CAB3]">
                {{ trans('results_search.found', ['count' => count($results)]) }}
            </p>
        </section>

        <section class="tl-v2-table-wrap">
            <table class="tl-v2-table">
                <caption class="sr-only">{{ trans('results_search.title') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ trans('results_search.draw') }}</th>
                        <th scope="col">{{ trans('results_search.date') }}</th>
                        <th scope="col">{{ trans('results_search.first_prize') }}</th>
                        <th scope="col">{{ trans('results_search.bottom_two') }}</th>
                        <th scope="col">{{ trans('results_search.source') }}</th>
                        <th scope="col">{{ trans('results_search.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($results as $row)
                        <tr>
                            <td class="font-mono font-bold text-[#FFF6D6]">{{ $row['draw_number'] }}</td>
                            <td class="text-[#D4CAB3]">{{ $row['draw_date'] }}</td>
                            <td class="font-mono text-[#F5E6B8] font-black tracking-widest">{{ $row['first_prize'] }}</td>
                            <td class="font-mono text-[#FAF6EC]">{{ $row['bottom_two'] ?? '—' }}</td>
                            <td>
                                <span class="tl-v2-pill {{ $row['fixture_sample'] ? 'tl-v2-pill--warning' : 'tl-v2-pill--verified' }}">
                                    {{ $row['source_state'] }}
                                </span>
                            </td>
                            <td class="text-xs text-[#86EFAC] font-bold uppercase">{{ trans('results_search.published') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="p-6 text-center text-[#D4CAB3]" colspan="6">
                                {{ trans('results_search.empty', ['query' => $query]) }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    @endif
</div>
@endsection
