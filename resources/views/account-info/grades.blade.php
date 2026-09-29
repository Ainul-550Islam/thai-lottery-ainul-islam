@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_robots', 'index,follow')
@section('canonical', $meta['canonical'])

@push('styles')
    @vite(['resources/css/public-pages.css'])
@endpush

{{--
    Public grade ladder.

    IT SHOWS THE PROGRAMME, NEVER A PERSON. There is no spend, no grade and no
    history for anybody here - a visitor deciding whether to register can see
    what the grades are without an account, and a signed-in visitor's own
    figures stay on /account/grade behind their login.

    THE RATES ARE THE APPLIED RATES. The tiers, thresholds and entitlements
    are projected by PublicAccountInfoService through GradeTierCatalog — the
    same authority the evaluator resolves real users against — so what is
    advertised and what is applied cannot drift apart.

    GRADE PARITY BATCH: the ladder renders the five canonical programme
    tiers (SL 1..5) with SL, grade, 30-day minimum spend, grade icon,
    discount % and the accessible "Discount Of Game" entitlement panel.
    The base/no-grade state below the first threshold is a real state,
    but it is not a programme tier and never renders here.
--}}

@section('content')
    <div class="pp-page ai-page" data-pp-page="account-grades">
        <a class="pp-skip-link" href="#pp-main">{{ trans('public_pages.skip_to_content') }}</a>

        <main id="pp-main" class="pp-main" tabindex="-1">
            <header class="pp-header">
                <h1 class="pp-header__title">{{ trans('account_info.grades_title') }}</h1>
                <p class="pp-header__lead">{{ trans('account_info.grades_lead') }}</p>
            </header>

            @if ($ladder['status'] !== 'CONFIGURED')
                <p class="pp-section__text" role="status">{{ trans('account_info.grades_unavailable') }}</p>
            @else
                <section class="pp-section" aria-labelledby="ai-ladder-title" data-pp-section="grade-ladder">
                    <div class="pp-section__inner">
                        <h2 class="pp-section__title" id="ai-ladder-title">{{ trans('account_info.ladder_heading') }}</h2>

                        <div class="ai-table-wrap grade-ladder" tabindex="0" role="region" aria-label="{{ trans('account_info.ladder_caption') }}">
                            <table class="ai-table grade-ladder__table">
                                <caption class="ai-table__caption">{{ trans('account_info.ladder_caption') }}</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">{{ trans('account_info.col_sl') }}</th>
                                        <th scope="col">{{ trans('account_info.col_grade') }}</th>
                                        <th scope="col">{{ trans('account_info.col_min_spend') }}</th>
                                        <th scope="col">{{ trans('account_info.col_discount') }}</th>
                                        <th scope="col">{{ trans('account_info.col_discount_of_game') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($ladder['tiers'] as $tier)
                                        <x-account-grade.grade-tier :tier="$tier" :ladder="$ladder" />
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @foreach ($ladder['tiers'] as $tier)
                            <x-account-grade.discount-games :tier="$tier" />
                        @endforeach

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
        </main>
    </div>
@endsection
