@extends('layouts.admin')

@section('title', trans('admin_release.title'))
@section('robots', 'noindex, nofollow')

@section('content')
<div class="flex w-full flex-col gap-6" aria-labelledby="release-operations-title">
    <header>
        <p class="text-xs uppercase tracking-[0.2em] text-emerald-400">{{ trans('admin_release.eyebrow') }}</p>
        <h1 id="release-operations-title" class="lf-page-title">{{ trans('admin_release.surface_'.$surface) }}</h1>
        <p class="mt-2 max-w-4xl text-sm text-slate-400">{{ $projection['note'] }}</p>
    </header>

    <section class="lf-panel-card" role="status" aria-live="polite" aria-labelledby="release-state-title">
        <div class="lf-panel-header">
            <h2 id="release-state-title" class="lf-panel-title">{{ trans('admin_release.state') }}</h2>
            <strong class="lf-kpi-value">{{ $projection['state'] }}</strong>
        </div>
        @if ($reference !== null)
            <p class="mt-2 text-sm text-slate-400"><span class="font-semibold">{{ trans('admin_release.reference') }}:</span> <span class="font-mono">{{ $reference }}</span></p>
        @endif
    </section>

    <section class="lf-panel-card" aria-labelledby="release-evidence-title">
        <div class="lf-panel-header">
            <h2 id="release-evidence-title" class="lf-panel-title">{{ trans('admin_release.evidence') }}</h2>
            <span class="text-xs text-slate-400">{{ count($projection['rows']) }} {{ trans('admin_release.records') }}</span>
        </div>
        <div class="lf-table-container">
            <table class="lf-table">
                <caption class="sr-only">{{ trans('admin_release.evidence') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ trans('admin_release.item') }}</th>
                        <th scope="col">{{ trans('admin_release.value') }}</th>
                        <th scope="col">{{ trans('admin_release.state') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($projection['rows'] as $row)
                        <tr>
                            <th scope="row" class="font-mono text-xs">{{ $row['label'] }}</th>
                            <td class="max-w-3xl break-all font-mono text-xs">{{ $row['value'] }}</td>
                            <td>{{ $row['state'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-8 text-center text-slate-400" role="status">{{ trans('admin_release.no_data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="lf-panel-card" aria-labelledby="release-safety-title">
        <h2 id="release-safety-title" class="lf-panel-title">{{ trans('admin_release.safety_title') }}</h2>
        <p class="mt-3 text-sm text-slate-400">{{ trans('admin_release.safety_body') }}</p>
    </section>
</div>
@endsection
