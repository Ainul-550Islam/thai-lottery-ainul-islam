{{--
    SHARED PUBLIC FOOTER.

    The `pp-footer*` class hooks are the contract between this component and
    resources/css/public-pages.css, which has always defined .pp-footer,
    .pp-footer__inner, .pp-footer__nav and .pp-footer__legal. No view ever
    emitted those class names, so the shipped stylesheet was dead rules and
    the public lanes had no verifiable shared footer. They are restored here
    alongside the utility classes so the appearance is unchanged.
--}}
@php
    $socialFacebook = (string) config('social.facebook', '');
    $socialFacebookParts = parse_url($socialFacebook);
    $socialFacebook = is_array($socialFacebookParts)
        && strtolower((string) ($socialFacebookParts['scheme'] ?? '')) === 'https'
        && in_array(strtolower((string) ($socialFacebookParts['host'] ?? '')), ['facebook.com', 'www.facebook.com'], true)
        && ! isset($socialFacebookParts['user'], $socialFacebookParts['pass'])
        ? $socialFacebook
        : null;
@endphp
<footer class="pp-footer bg-slate-900/60 border-t border-slate-800/80 backdrop-blur-xl py-12 text-xs text-slate-400" role="contentinfo">
    <div class="pp-footer__inner max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
        <!-- Top Section: Brand + Links Grid -->
        <nav class="pp-footer__nav grid grid-cols-1 md:grid-cols-4 gap-8" aria-label="{{ trans('home.footer_navigation') }}">
            <!-- Brand Column -->
            <div class="space-y-3 md:col-span-1">
                <div class="flex items-center space-x-2.5">
                    <div class="w-8 h-8 rounded-lg bg-gradient-to-tr from-amber-500 to-emerald-500 flex items-center justify-center font-black text-sm text-slate-950">
                        TL
                    </div>
                    <a href="{{ route('home') }}" class="font-extrabold text-white text-base tracking-tight hover:text-amber-300 transition">
                        {{ config('app.name', 'Thai Lottery') }}
                    </a>
                </div>
                <p class="text-xs text-slate-400 leading-relaxed">{{ __('public_pages.footer_brand_note') }}</p>
                @if ($socialFacebook !== null)
                    <a href="{{ $socialFacebook }}" rel="noopener noreferrer" target="_blank">Facebook</a>
                @endif
            </div>

            <!-- Lottery Lanes -->
            <div class="space-y-3">
                <h4 class="font-bold text-white text-xs uppercase tracking-wider">{{ __('public_pages.footer_lanes_heading') }}</h4>
                <ul class="space-y-2">
                    <li><a href="{{ route('national-lottery.index') }}" class="hover:text-emerald-400 transition">{{ __('public_pages.footer_lane_l6') }}</a></li>
                    <li><a href="{{ route('weekly-lottery.index') }}" class="hover:text-emerald-400 transition">{{ __('public_pages.footer_lane_weekly') }}</a></li>
                    <li><a href="{{ route('bingo-lottery.index') }}" class="hover:text-emerald-400 transition">{{ __('public_pages.footer_lane_bingo') }}</a></li>
                    <li><a href="{{ route('pcso-lottery.index') }}" class="hover:text-emerald-400 transition">{{ __('public_pages.footer_lane_pcso') }}</a></li>
                    <li><a href="{{ route('results.index') }}" class="hover:text-emerald-400 transition">{{ __('public_pages.footer_lane_results') }}</a></li>
                </ul>
            </div>

            <!-- Public Information -->
            <div class="space-y-3">
                <h4 class="font-bold text-white text-xs uppercase tracking-wider">{{ __('public_pages.footer_information_heading') }}</h4>
                <ul class="space-y-2">
                    <li><a href="{{ route('services') }}" class="hover:text-emerald-400 transition">{{ __('public_pages.footer_services') }}</a></li>
                    <li><a href="{{ route('about') }}" class="hover:text-emerald-400 transition">{{ __('public_pages.footer_about') }}</a></li>
                    <li><a href="{{ route('vision') }}" class="hover:text-emerald-400 transition">{{ __('public_pages.footer_vision') }}</a></li>
                    <li><a href="{{ route('how-to-play') }}" class="hover:text-emerald-400 transition">{{ __('public_pages.footer_how_to_play') }}</a></li>
                    <li><a href="{{ route('faq') }}" class="hover:text-emerald-400 transition">{{ __('public_pages.footer_faq') }}</a></li>
                    <li><a href="{{ route('ticket-check') }}" class="hover:text-emerald-400 transition">{{ __('public_pages.footer_ticket_verification') }}</a></li>
                    <li><a href="{{ route('sales-points') }}" class="hover:text-emerald-400 transition">{{ __('public_pages.footer_sales_points') }}</a></li>
                    <li><a href="{{ route('fees') }}" class="hover:text-emerald-400 transition">{{ __('public_pages.footer_fees') }}</a></li>
                    <li><a href="{{ route('discounts') }}" class="hover:text-emerald-400 transition">{{ __('public_pages.footer_discounts') }}</a></li>
                </ul>
            </div>

            <!-- Legal & Compliance -->
            <div class="space-y-3">
                <h4 class="font-bold text-white text-xs uppercase tracking-wider">{{ __('public_pages.footer_legal_support_heading') }}</h4>
                <ul class="space-y-2">
                    <li><a href="{{ route('terms') }}" class="hover:text-emerald-400 transition">{{ __('public_pages.footer_terms') }}</a></li>
                    <li><a href="{{ route('privacy') }}" class="hover:text-emerald-400 transition">{{ __('public_pages.footer_privacy') }}</a></li>
                    <li><a href="{{ route('account-verification-guide') }}" class="hover:text-emerald-400 transition">{{ __('public_pages.footer_kyc_guide') }}</a></li>
                    <li><a href="{{ route('account-grades') }}" class="hover:text-emerald-400 transition">{{ __('public_pages.footer_account_grades') }}</a></li>
                    <li><a href="{{ route('prize-verification') }}" class="hover:text-emerald-400 transition">{{ __('public_pages.footer_prize_verification') }}</a></li>
                    <li><a href="{{ route('contact') }}" class="hover:text-emerald-400 transition">{{ __('public_pages.footer_contact') }}</a></li>
                </ul>
            </div>
        </nav>

        <!-- Legal Disclaimer & Copyright -->
        <div class="pt-8 border-t border-slate-800 flex flex-col sm:flex-row items-center justify-between gap-4 text-center sm:text-left">
            <p class="pp-footer__legal text-[11px] text-slate-500 max-w-2xl">
                &copy; {{ date('Y') }} {{ config('app.name', 'Thai Lottery Enterprise Wagering Platform') }}.
                {{ __('public_pages.footer_all_rights_reserved') }} {{ __('public_pages.footer_disclaimer') }}
            </p>
            {{--
                The removed element here read "System Operational" next to a
                pulsing green dot on every public page. It was a literal in the
                template: it was not wired to the health endpoint, to the queue
                heartbeat, or to anything else, so it rendered an all-clear
                during a total outage. A status claim must come from a health
                check or not be made at all.
            --}}
            <div class="flex items-center space-x-4 shrink-0 text-[11px]">
                <span class="text-slate-600">{{ __('public_pages.footer_version_label') }} v{{ config('app.version', '1.0.0') }}</span>
            </div>
        </div>
    </div>
</footer>
