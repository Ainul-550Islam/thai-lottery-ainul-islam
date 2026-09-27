@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_robots', 'index,follow')
@section('canonical', $meta['canonical'])

{{--
    Public grade ladder.

    IT SHOWS THE PROGRAMME, NEVER A PERSON. There is no spend, no grade and no
    history for anybody here - a visitor deciding whether to register can see
    what the grades are without an account, and a signed-in visitor's own
    figures stay on /account/grade behind their login.

    THE RATES ARE THE APPLIED RATES. Both this page and the live calculation
    read config/account_grades.php, so what is advertised and what is charged
    cannot drift apart.
--}}

@section('content')
    <div class="pp-page ai-page">
        <header class="pp-header">
            <h1 class="pp-header__title">{{ trans('account_info.grades_title') }}</h1>
            <p class="pp-header__lead">{{ trans('account_info.grades_lead') }}</p>
        </header>

        @if ($ladder['status'] !== 'CONFIGURED')
            <p class="pp-section__text" role="status">{{ trans('account_info.grades_unavailable') }}</p>
        @else
            <section class="pp-section" aria-labelledby="ai-ladder-title">
                <div class="pp-section__inner">
                    <h2 class="pp-section__title" id="ai-ladder-title">{{ trans('account_info.ladder_heading') }}</h2>

                    <div class="ai-table-wrap">
                        <table class="ai-table">
                            <caption class="ai-table__caption">{{ trans('account_info.ladder_caption') }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">{{ trans('account_info.col_grade') }}</th>
                                    <th scope="col">{{ trans('account_info.col_min_spend') }}</th>
                                    <th scope="col">{{ trans('account_info.col_discount') }}</th>
                                    <th scope="col">{{ trans('account_info.col_scope') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($ladder['tiers'] as $tier)
                                    <tr>
                                        <th scope="row" data-label="{{ trans('account_info.col_grade') }}">{{ $tier['name'] }}</th>
                                        <td data-label="{{ trans('account_info.col_min_spend') }}">
                                            {{ $tier['min_spend'] }} {{ $ladder['currency'] }}
                                        </td>
                                        <td data-label="{{ trans('account_info.col_discount') }}">{{ $tier['discount_display'] }}</td>
                                        <td data-label="{{ trans('account_info.col_scope') }}">
                                            {{ trans('account_info.scope_'.$tier['scope']) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- The discount never touches GLO products. Saying so on
                         the page it is advertised on is the point: a reader
                         must not infer a discount on a government-priced
                         ticket. --}}
                    <p class="pp-section__text">{{ trans('account_info.discount_scope_note') }}</p>

                    @if ($ladder['max_discount_display'] !== null)
                        <p class="pp-section__text">
                            {{ trans('account_info.max_discount_note', ['max' => $ladder['max_discount_display']]) }}
                        </p>
                    @endif

                    <p class="pp-section__text pp-muted">
                        {{ trans('account_info.rule_version_note', ['version' => $ladder['rule_version']]) }}
                    </p>
                </div>
            </section>
        @endif

        <x-public-page.footer />
    </div>
@endsection
