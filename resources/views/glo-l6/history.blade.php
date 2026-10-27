@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')
    @vite(['resources/css/glo-l6.css'])
@endpush

@section('content')
<div class="glo-l6-page">
    <a class="glo-l6-skip" href="#glo-l6-history-main">{{ trans('glo_l6.skip_to_content') }}</a>
    <main id="glo-l6-history-main" class="glo-l6-main" tabindex="-1">
        <header class="glo-l6-header">
            <p class="glo-l6-kicker">{{ trans('glo_l6.product_label') }}</p>
            <h1>{{ $pageHeading }}</h1>
            <p class="glo-l6-lede">{{ $pageLead }}</p>
            <p class="glo-l6-disclaimer">{{ trans('glo_l6.history_window_notice', ['years' => config('glo.result_experience.history_years', 2)]) }}</p>
        </header>

        <section class="glo-l6-panel" aria-labelledby="glo-l6-history-heading">
            <div class="glo-l6-panel__heading">
                <div>
                    <p class="glo-l6-kicker">{{ trans('glo_l6.history_label') }}</p>
                    <h2 id="glo-l6-history-heading">{{ trans('glo_l6.history_table_heading') }}</h2>
                </div>
                @if ($archiveYear !== null)
                    <span class="glo-l6-source glo-l6-source--internal_reconciled">{{ $archiveYear }}</span>
                @endif
            </div>

            @if ($history->count() === 0)
                <div class="glo-l6-empty" role="status">
                    <strong>{{ trans('glo_l6.history_empty') }}</strong>
                    <p>{{ trans('glo_l6.history_empty_lead') }}</p>
                </div>
            @else
                <div class="glo-l6-table-wrap" tabindex="0" role="region" aria-label="{{ trans('glo_l6.history_table_heading') }}">
                    <table class="glo-l6-table">
                        <caption class="sr-only">{{ trans('glo_l6.history_table_heading') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ trans('glo_l6.draw_number_label') }}</th>
                                <th scope="col">{{ trans('glo_l6.draw_date_label') }}</th>
                                <th scope="col">{{ trans('glo_l6.first_prize_label') }}</th>
                                <th scope="col">{{ trans('glo_l6.source_state_label') }}</th>
                                <th scope="col"><span class="sr-only">{{ trans('glo_l6.actions_heading') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($history as $row)
                                @php $rowSource = strtolower((string) ($row['source_state'] ?? 'unavailable')); @endphp
                                <tr>
                                    <th scope="row">{{ $row['draw_number'] ?? trans('glo_l6.not_recorded') }}</th>
                                    <td>{{ $row['draw_date'] ?? trans('glo_l6.not_recorded') }}</td>
                                    <td class="glo-l6-table-number">{{ $row['first_prize'] ?? trans('glo_l6.not_published') }}</td>
                                    <td><span class="glo-l6-source glo-l6-source--{{ $rowSource }}">{{ trans('glo_l6.source_state.'.$rowSource) }}</span></td>
                                    <td><a class="glo-l6-text-link" href="{{ route('glo-l6.result', ['draw' => $row['draw_number'] ?? $row['draw_id']]) }}">{{ trans('glo_l6.view_result') }}</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="glo-l6-pagination" aria-label="{{ trans('glo_l6.pagination_label') }}">
                    @if ($history->onFirstPage())
                        <span class="glo-l6-button glo-l6-button--disabled">{{ trans('glo_l6.previous_page') }}</span>
                    @else
                        <a class="glo-l6-button glo-l6-button--quiet" href="{{ $history->previousPageUrl() }}">{{ trans('glo_l6.previous_page') }}</a>
                    @endif
                    <span class="glo-l6-pagination__status">{{ trans('glo_l6.page_status', ['current' => $history->currentPage(), 'last' => $history->lastPage()]) }}</span>
                    @if ($history->hasMorePages())
                        <a class="glo-l6-button glo-l6-button--quiet" href="{{ $history->nextPageUrl() }}">{{ trans('glo_l6.next_page') }}</a>
                    @else
                        <span class="glo-l6-button glo-l6-button--disabled">{{ trans('glo_l6.next_page') }}</span>
                    @endif
                </div>
            @endif
        </section>

        <div class="glo-l6-actions">
            <a class="glo-l6-button glo-l6-button--quiet" href="{{ route('glo-l6.index') }}">{{ trans('glo_l6.back_to_l6') }}</a>
            <a class="glo-l6-button" href="{{ route('glo-l6.latest') }}">{{ trans('glo_l6.open_latest') }}</a>
        </div>
    </main>
</div>
@endsection
