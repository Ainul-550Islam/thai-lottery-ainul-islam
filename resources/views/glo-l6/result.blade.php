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
@php
    $payload = is_array($result ?? null) ? $result : [];
    $hasResult = (bool) ($payload['has_result'] ?? false);
    $sourceState = strtolower((string) ($payload['source_state'] ?? 'unavailable'));
    $sourceKey = 'glo_l6.source_state.' . $sourceState;
    $categories = [
        'second_prize' => 'second_prize_label',
        'third_prize' => 'third_prize_label',
        'fourth' => 'fourth_prize_label',
        'fifth' => 'fifth_prize_label',
        'front_three' => 'front_three_label',
        'last_three' => 'last_three_label',
        'last_two' => 'last_two_label',
        'adjacent_first' => 'adjacent_label',
    ];
@endphp

<div class="glo-l6-page">
    <a class="glo-l6-skip" href="#glo-l6-result-main">{{ trans('glo_l6.skip_to_content') }}</a>
    <main id="glo-l6-result-main" class="glo-l6-main" tabindex="-1">
        <header class="glo-l6-header">
            <p class="glo-l6-kicker">{{ trans('glo_l6.product_label') }}</p>
            <h1>{{ $pageHeading }}</h1>
            <p class="glo-l6-lede">{{ $pageLead }}</p>
            <p class="glo-l6-disclaimer">{{ trans('glo_l6.provenance_notice') }}</p>
        </header>

        <section class="glo-l6-panel glo-l6-result-page-panel" aria-labelledby="glo-l6-result-heading">
            <div class="glo-l6-panel__heading">
                <div>
                    <p class="glo-l6-kicker">{{ trans('glo_l6.latest_result_label') }}</p>
                    <h2 id="glo-l6-result-heading">
                        {{ trans('glo_l6.result_heading') }}
                        @if (($payload['draw_number'] ?? null) !== null)
                            <span class="glo-l6-heading-ref">#{{ $payload['draw_number'] }}</span>
                        @endif
                    </h2>
                </div>
                <span class="glo-l6-source glo-l6-source--{{ $sourceState }}">
                    {{ trans($sourceKey) }}
                </span>
            </div>

            @if (($payload['draw_number'] ?? null) !== null || ($payload['draw_date'] ?? null) !== null)
                <div class="glo-l6-result-meta">
                    @if (($payload['draw_number'] ?? null) !== null)
                        <span>{{ trans('glo_l6.draw_number_label') }}: {{ $payload['draw_number'] }}</span>
                    @endif
                    @if (($payload['draw_date'] ?? null) !== null)
                        <time datetime="{{ $payload['draw_date'] }}">{{ $payload['draw_date'] }}</time>
                    @endif
                    @if (($payload['published_at'] ?? null) !== null)
                        <time datetime="{{ $payload['published_at'] }}">{{ $payload['published_at'] }}</time>
                    @endif
                </div>
            @endif

            @if ($hasResult)
                <div class="glo-l6-number-row" aria-label="{{ trans('glo_l6.first_prize_label') }}">
                    <span class="glo-l6-number-label">{{ trans('glo_l6.first_prize_label') }}</span>
                    <strong class="glo-l6-number">{{ $payload['first_prize'] ?? trans('glo_l6.not_published') }}</strong>
                </div>

                <div class="glo-l6-tier-grid glo-l6-tier-grid--wide">
                    @foreach ($categories as $field => $labelKey)
                        @php
                            $values = $payload[$field] ?? [];
                            $values = is_array($values) ? array_values(array_map('strval', $values)) : [$values];
                            $values = array_values(array_filter($values, static fn ($value): bool => $value !== ''));
                        @endphp
                        <div class="glo-l6-tier">
                            <span>{{ trans('glo_l6.'.$labelKey) }}</span>
                            <strong>{{ $values === [] ? trans('glo_l6.not_published') : implode(', ', $values) }}</strong>
                        </div>
                    @endforeach
                </div>

                <dl class="glo-l6-detail-list">
                    @if (($payload['result_version'] ?? null) !== null)
                        <div><dt>{{ trans('glo_l6.result_version_label') }}</dt><dd>{{ $payload['result_version'] }}</dd></div>
                    @endif
                    @if (($payload['source_state'] ?? null) !== null)
                        <div><dt>{{ trans('glo_l6.source_state_label') }}</dt><dd>{{ trans($sourceKey) }}</dd></div>
                    @endif
                </dl>
            @else
                <div class="glo-l6-empty" role="status">
                    <strong>{{ trans('glo_l6.no_result') }}</strong>
                    <p>{{ trans('glo_l6.result_unavailable') }}</p>
                </div>
            @endif
        </section>

        <div class="glo-l6-actions">
            <a class="glo-l6-button glo-l6-button--quiet" href="{{ $backRoute }}">{{ trans('glo_l6.back_to_l6') }}</a>
            <a class="glo-l6-button" href="{{ route('glo-l6.history') }}">{{ trans('glo_l6.open_history') }}</a>
        </div>
    </main>
</div>
@endsection
