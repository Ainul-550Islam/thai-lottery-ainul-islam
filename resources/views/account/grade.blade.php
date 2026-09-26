@extends('layouts.app')

@section('title', $meta['title'])

@section('content')
    <div class="account-services" data-page="account-grade">
        <a class="pp-skip-link" href="#pp-main">{{ trans('public_pages.skip_to_content') }}</a>

        <main id="pp-main" class="pp-main" tabindex="-1">
            <header class="acct-header">
                <h1>{{ trans('account_services.grade_title') }}</h1>
                <p class="acct-header__lead">{{ trans('account_services.grade_lead') }}</p>
            </header>

            @if (session('success'))
                <div class="acct-alert acct-alert--success" role="status" data-grade-flash>
                    {{ session('success') }}
                </div>
            @endif

            <div class="acct-grid">
                <x-account.grade-card
                    :title="trans('account_services.grade_current')"
                    :grade="$grade"
                    :can-refresh="true"
                    :refresh-action="route('account.grade.refresh')"
                />

                <section class="acct-card" aria-labelledby="grade-discounts-title">
                    <h2 class="acct-card__title" id="grade-discounts-title">{{ trans('account_services.grade_discounts_title') }}</h2>
                    <ul class="grade-discounts" data-grade-discounts>
                        @foreach (($discounts ?? []) as $row)
                            <li class="grade-discounts__item" data-discount-scope="{{ $row['scope'] }}">
                                <span class="grade-discounts__scope">
                                    {{ $row['scope'] === 'glo_excluded'
                                        ? trans('account_services.grade_discount_glo_excluded')
                                        : trans('account_services.grade_discount_operator_markets') }}
                                </span>
                                <strong class="grade-discounts__rate">{{ $row['percent_display'] }}</strong>
                            </li>
                        @endforeach
                    </ul>
                    <p class="acct-note">{{ trans('account_services.grade_discount_note') }}</p>
                    <p class="acct-note" data-glo-price-guard>
                        L6 = {{ config('glo.l6.ticket_price', '80.00') }} THB ·
                        N3 = {{ config('glo.n3.ticket_price', '20.00') }} THB
                        ({{ trans('account_services.grade_discount_glo_excluded') }})
                    </p>
                </section>
            </div>

            <section class="acct-card" aria-labelledby="grade-history-title">
                <div class="acct-card__head">
                    <h2 class="acct-card__title" id="grade-history-title">{{ trans('account_services.grade_history_title') }}</h2>
                    <div class="acct-field acct-field--inline">
                        <label for="grade-filter">{{ trans('account_services.grade_filter_label') }}</label>
                        <select id="grade-filter" class="acct-input" data-grade-filter>
                            <option value="">{{ trans('account_services.grade_filter_all') }}</option>
                            @foreach (($tiers ?? []) as $tier)
                                <option value="{{ $tier['key'] }}">{{ $tier['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                @if (($history ?? []) === [])
                    <p class="acct-note" data-grade-history-empty>{{ trans('account_services.grade_history_empty') }}</p>
                @else
                    <div class="fee-table-wrap" tabindex="0" role="region" aria-label="{{ trans('account_services.grade_history_title') }}">
                        <table class="fee-table" data-grade-history>
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
                                    <tr data-history-grade="{{ $row['grade_key'] }}">
                                        <td>{{ $row['period_start'] }} → {{ $row['period_end'] }}</td>
                                        <td>{{ $row['qualifying_spend'] }}</td>
                                        <td>{{ $row['grade_key'] }}</td>
                                        <td>{{ $row['previous_grade_key'] ?? '—' }}</td>
                                        <td>{{ $row['calculated_at'] }}</td>
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
