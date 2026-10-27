@extends('layouts.app')

@section('title', __('conversion.page_title'))
@section('meta_description', __('conversion.meta_description'))
@section('meta_robots', 'noindex, nofollow')

@push('head_extras')
    @vite(['resources/css/pages/rewards.css', 'resources/js/pages/rewards.js'])
@endpush

@section('content')
<div class="next-public-page rewards-hub" data-next-public-page="member-rewards" data-rewards-hub>
    <header class="next-hero rewards-hero">
        <div class="next-hero__copy">
            <p class="next-eyebrow">{{ __('conversion.eyebrow') }}</p>
            <h1 class="next-hero__title">{{ __('conversion.heading') }}</h1>
            <p class="next-hero__lead">{{ __('conversion.subheading') }}</p>
        </div>
    </header>

    @if ($selfExcluded)
        <div class="rewards-rg-banner" role="alert" data-rg-blocked>
            {{ __('conversion.rg_blocked_notice') }}
        </div>
    @endif

    <div class="rewards-grid">
        {{-- D6.1: Promo / Coupon Redemption --}}
        <section class="next-card rewards-card" aria-labelledby="coupon-heading">
            <h2 id="coupon-heading" class="next-card__title">{{ __('conversion.coupon_section_title') }}</h2>
            <p class="next-card__body">{{ __('conversion.coupon_section_subtitle') }}</p>

            @if (! $selfExcluded)
                <form method="POST" action="{{ route('member.rewards.coupons.redeem') }}" class="rewards-form">
                    @csrf
                    <label for="promo-code-input" class="rewards-label">{{ __('conversion.coupon_label') }}</label>
                    <div class="rewards-inline-field">
                        <input id="promo-code-input"
                               name="code"
                               type="text"
                               maxlength="32"
                               autocomplete="off"
                               required
                               value="{{ old('code') }}"
                               placeholder="{{ __('conversion.coupon_placeholder') }}"
                               class="rewards-input">
                        <button type="submit" class="rewards-btn rewards-btn--primary">
                            {{ __('conversion.coupon_submit') }}
                        </button>
                    </div>
                </form>
            @endif

            <h3 class="rewards-subheading">{{ __('conversion.coupon_history_title') }}</h3>
            @if ($couponHistory->isEmpty())
                <p class="rewards-empty">{{ __('conversion.coupon_history_empty') }}</p>
            @else
                <ul class="rewards-list">
                    @foreach ($couponHistory as $row)
                        <li class="rewards-list__item">
                            <span>#{{ $row->coupon_id }} · {{ $row->redeemed_at?->toDateString() }}</span>
                            <strong class="rewards-amount">+{{ $row->granted_amount }} {{ $row->currency }}</strong>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- D6.5: Loyalty Points & Bonus Rewards --}}
        <section class="next-card rewards-card" aria-labelledby="loyalty-heading">
            <div class="rewards-balance-row">
                <div>
                    <h2 id="loyalty-heading" class="next-card__title">{{ __('conversion.loyalty_section_title') }}</h2>
                    <p class="rewards-label">{{ __('conversion.loyalty_balance_label') }}</p>
                </div>
                <div class="rewards-points-pill" data-loyalty-balance>
                    {{ number_format($pointsBalance) }} <span>{{ __('conversion.loyalty_points_unit') }}</span>
                </div>
            </div>

            @if (! $selfExcluded && $rewardsCatalogue !== [])
                <h3 class="rewards-subheading">{{ __('conversion.loyalty_rewards_title') }}</h3>
                <div class="rewards-catalogue">
                    @foreach ($rewardsCatalogue as $rewardKey => $reward)
                        <form method="POST" action="{{ route('member.rewards.loyalty.redeem') }}" class="rewards-tier-item">
                            @csrf
                            <input type="hidden" name="reward_key" value="{{ $rewardKey }}">
                            <div>
                                <div class="rewards-tier-name">{{ __($reward['name_key']) }}</div>
                                <div class="rewards-tier-cost">{{ number_format($reward['points_cost']) }} {{ __('conversion.loyalty_points_unit') }}</div>
                            </div>
                            @if ($pointsBalance >= $reward['points_cost'])
                                <button type="submit" class="rewards-btn rewards-btn--primary">
                                    {{ __('conversion.loyalty_redeem_button') }}
                                </button>
                            @else
                                <button type="button" disabled class="rewards-btn rewards-btn--disabled">
                                    {{ __('conversion.loyalty_insufficient') }}
                                </button>
                            @endif
                        </form>
                    @endforeach
                </div>
            @endif

            <h3 class="rewards-subheading">{{ __('conversion.loyalty_history_title') }}</h3>
            @if ($pointsHistory->isEmpty())
                <p class="rewards-empty">{{ __('conversion.loyalty_history_empty') }}</p>
            @else
                <ul class="rewards-list">
                    @foreach ($pointsHistory as $entry)
                        <li class="rewards-list__item">
                            <span>{{ $entry->description }}</span>
                            <strong>{{ $entry->points > 0 ? '+' . $entry->points : $entry->points }} {{ __('conversion.loyalty_points_unit') }}</strong>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- D6.4: Public Referral Link & Attribution Stats --}}
        <section class="next-card rewards-card" aria-labelledby="referral-heading">
            <h2 id="referral-heading" class="next-card__title">{{ __('conversion.referral_section_title') }}</h2>

            @if ($referralSummary['has_agent'] && $referralSummary['share_url'] !== null)
                <label for="referral-share-url" class="rewards-label">{{ __('conversion.referral_share_label') }}</label>
                <div class="rewards-inline-field">
                    <input id="referral-share-url"
                           type="url"
                           readonly
                           value="{{ $referralSummary['share_url'] }}"
                           class="rewards-input"
                           data-referral-url>
                    <button type="button"
                            class="rewards-btn rewards-btn--secondary"
                            data-copy-referral
                            data-copied-text="{{ __('conversion.referral_copied_feedback') }}">
                        {{ __('conversion.referral_copy_button') }}
                    </button>
                </div>
                <p class="rewards-live-status" role="status" aria-live="polite" data-copy-status></p>

                <dl class="rewards-stats-grid">
                    <div>
                        <dt>{{ __('conversion.referral_clicks_label') }}</dt>
                        <dd>{{ number_format($referralSummary['total_clicks']) }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('conversion.referral_link_signups_label') }}</dt>
                        <dd>{{ number_format($referralSummary['link_conversions']) }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('conversion.referral_signups_label') }}</dt>
                        <dd>{{ number_format($referralSummary['total_referrals']) }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('conversion.referral_commission_label') }}</dt>
                        <dd>{{ $referralSummary['total_commission_earned'] }}</dd>
                    </div>
                </dl>
            @else
                <p class="rewards-empty">{{ __('conversion.referral_no_agent_notice') }}</p>
            @endif

            @if ($referralSummary['referred_by_code'] !== null)
                <p class="rewards-footnote">
                    {{ __('conversion.referral_referred_by', ['code' => $referralSummary['referred_by_code']]) }}
                </p>
            @endif
        </section>

        {{-- D6.2: Browser Push Notification Registration & D6.6 Member Testimonial --}}
        <section class="next-card rewards-card" aria-labelledby="push-heading">
            <h2 id="push-heading" class="next-card__title">{{ __('conversion.push_section_title') }}</h2>
            <p class="next-card__body">{{ __('conversion.push_section_subtitle') }}</p>
            <p class="rewards-footnote">
                {{ __('conversion.push_active_devices', ['count' => $activePushCount]) }}
            </p>

            @if ($pushEnabled)
                <div class="rewards-push-controls"
                     data-push-endpoint="{{ route('member.rewards.push.register') }}"
                     data-vapid-key="{{ $vapidPublicKey }}"
                     data-csrf="{{ csrf_token() }}">
                    <button type="button" class="rewards-btn rewards-btn--secondary" data-enable-push>
                        {{ __('conversion.push_enable_button') }}
                    </button>
                    <p class="rewards-live-status" role="status" aria-live="polite" data-push-status></p>
                </div>
            @else
                <p class="rewards-empty">{{ __('conversion.push_unavailable') }}</p>
            @endif

            @if (! $selfExcluded)
                <hr class="rewards-divider">
                <h3 class="rewards-subheading">{{ __('conversion.testimonial_form_title') }}</h3>
                <form method="POST" action="{{ route('member.rewards.testimonials.submit') }}" class="rewards-form">
                    @csrf
                    <label for="testimonial-quote" class="rewards-label">{{ __('conversion.testimonial_quote_label') }}</label>
                    <textarea id="testimonial-quote"
                              name="quote"
                              rows="3"
                              minlength="20"
                              maxlength="280"
                              required
                              class="rewards-input">{{ old('quote') }}</textarea>

                    <div class="rewards-inline-field">
                        <label for="testimonial-rating" class="rewards-label">{{ __('conversion.testimonial_rating_label') }}</label>
                        <select id="testimonial-rating" name="rating" class="rewards-select">
                            @for ($stars = 5; $stars >= 1; $stars--)
                                <option value="{{ $stars }}" @selected((int) old('rating', 5) === $stars)>
                                    {{ str_repeat('★', $stars) }} ({{ $stars }}/5)
                                </option>
                            @endfor
                        </select>
                        <button type="submit" class="rewards-btn rewards-btn--secondary">
                            {{ __('conversion.testimonial_submit') }}
                        </button>
                    </div>
                </form>
            @endif
        </section>
    </div>
</div>
@endsection
