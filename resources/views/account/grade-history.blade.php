@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_robots', 'noindex,nofollow')

@php
    $currency = \App\Enums\Currency::from((string) config('account_grades.currency'));
@endphp

@section('content')
<div class="account-services" data-page="account-grade-history">
    <a class="pp-skip-link" href="#grade-history-main">{{ trans('public_pages.skip_to_content') }}</a>
    <main id="grade-history-main" class="pp-main" tabindex="-1">
        <header class="acct-header">
            <p class="text-xs font-black uppercase tracking-[0.18em] text-amber-400">{{ trans('account_services.grade_title') }}</p>
            <h1>{{ trans('account_services.grade_history_title') }}</h1>
            <p class="acct-header__lead">{{ trans('account_services.grade_meta_description') }}</p>
        </header>

        <section class="acct-card" aria-labelledby="grade-history-heading">
            <div class="acct-card__head">
                <h2 class="acct-card__title" id="grade-history-heading">{{ trans('account_services.grade_history_title') }}</h2>
                <a class="acct-button acct-button--quiet" href="{{ route('account.grade') }}">{{ trans('account_services.back_to_grade') }}</a>
            </div>

            @if ($history === [])
                <p class="acct-note" role="status">{{ trans('account_services.grade_history_empty') }}</p>
            @else
                <div class="fee-table-wrap" tabindex="0" role="region" aria-label="{{ trans('account_services.grade_history_title') }}">
                    <table class="fee-table">
                        <caption class="sr-only">{{ trans('account_services.grade_history_title') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ trans('account_services.grade_col_period') }}</th>
                                <th scope="col">{{ trans('account_services.grade_col_spend') }}</th>
                                <th scope="col">{{ trans('account_services.grade_col_grade') }}</th>
                                <th scope="col">{{ trans('account_services.grade_col_previous') }}</th>
                                <th scope="col">{{ trans('account_services.grade_col_calculated') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($history as $row)
                                <tr>
                                    <td>{{ $row['period_start'] ?? trans('account_services.not_recorded') }} &rarr; {{ $row['period_end'] ?? trans('account_services.not_recorded') }}</td>
                                    <td>{{ \App\Services\Finance\Money::of((string) $row['qualifying_spend'], $currency)->format() }}</td>
                                    <td>{{ $row['grade_key'] }}</td>
                                    <td>{{ $row['previous_grade_key'] ?? trans('account_services.not_recorded') }}</td>
                                    <td>{{ $row['calculated_at'] ?? trans('account_services.not_recorded') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </main>
</div>
@endsection
