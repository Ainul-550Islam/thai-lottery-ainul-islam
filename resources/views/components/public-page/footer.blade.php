{{--
    SHARED PUBLIC FOOTER.

    The `pp-footer*` class hooks are the contract between this component and
    resources/css/public-pages.css, which has always defined .pp-footer,
    .pp-footer__inner, .pp-footer__nav and .pp-footer__legal. No view ever
    emitted those class names, so the shipped stylesheet was dead rules and
    the public lanes had no verifiable shared footer. They are restored here
    alongside the utility classes so the appearance is unchanged.
--}}
<footer class="pp-footer bg-slate-900/60 border-t border-slate-800/80 backdrop-blur-xl py-12 text-xs text-slate-400" role="contentinfo">
    <div class="pp-footer__inner max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
        <!-- Top Section: Brand + Links Grid -->
        <div class="pp-footer__nav grid grid-cols-1 md:grid-cols-4 gap-8">
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
                <p class="text-xs text-slate-400 leading-relaxed">
                    Independent enterprise wagering platform. High-precision lottery results, automated prize calculations, and fintech-grade account management.
                </p>
            </div>

            <!-- Lottery Lanes -->
            <div class="space-y-3">
                <h4 class="font-bold text-white text-xs uppercase tracking-wider">Lottery Lanes</h4>
                <ul class="space-y-2">
                    <li><a href="{{ route('national-lottery.index') }}" class="hover:text-emerald-400 transition">Government Lottery (GLO)</a></li>
                    <li><a href="{{ route('weekly-lottery.index') }}" class="hover:text-emerald-400 transition">Weekly Lottery</a></li>
                    <li><a href="{{ route('bingo-lottery.index') }}" class="hover:text-emerald-400 transition">Mega Lottery</a></li>
                    <li><a href="{{ route('pcso-lottery.index') }}" class="hover:text-emerald-400 transition">PCSO Lottery</a></li>
                    <li><a href="{{ route('results.index') }}" class="hover:text-emerald-400 transition">Official Draw Results</a></li>
                </ul>
            </div>

            <!-- Public Information -->
            <div class="space-y-3">
                <h4 class="font-bold text-white text-xs uppercase tracking-wider">Information</h4>
                <ul class="space-y-2">
                    <li><a href="{{ route('about') }}" class="hover:text-emerald-400 transition">About the Platform</a></li>
                    <li><a href="{{ route('vision') }}" class="hover:text-emerald-400 transition">Vision &amp; Mission</a></li>
                    <li><a href="{{ route('ticket-check') }}" class="hover:text-emerald-400 transition">Ticket Verification</a></li>
                    <li><a href="{{ route('sales-points') }}" class="hover:text-emerald-400 transition">Authorized Sales Points</a></li>
                    <li><a href="{{ route('fees') }}" class="hover:text-emerald-400 transition">Platform Fees</a></li>
                    <li><a href="{{ route('discounts') }}" class="hover:text-emerald-400 transition">Lottery Discounts</a></li>
                </ul>
            </div>

            <!-- Legal & Compliance -->
            <div class="space-y-3">
                <h4 class="font-bold text-white text-xs uppercase tracking-wider">Legal &amp; Support</h4>
                <ul class="space-y-2">
                    <li><a href="{{ route('terms') }}" class="hover:text-emerald-400 transition">Terms of Service</a></li>
                    <li><a href="{{ route('privacy') }}" class="hover:text-emerald-400 transition">Privacy Policy</a></li>
                    <li><a href="{{ route('account-verification-guide') }}" class="hover:text-emerald-400 transition">KYC Verification Guide</a></li>
                    <li><a href="{{ route('account-grades') }}" class="hover:text-emerald-400 transition">Account Grades</a></li>
                    {{-- prize-verification was the one information page with no
                         footer destination, so it was reachable only by typing
                         the URL. --}}
                    <li><a href="{{ route('prize-verification') }}" class="hover:text-emerald-400 transition">Prize Verification</a></li>
                    <li><a href="{{ route('contact') }}" class="hover:text-emerald-400 transition">Contact Us</a></li>
                </ul>
            </div>
        </div>

        <!-- Legal Disclaimer & Copyright -->
        <div class="pt-8 border-t border-slate-800 flex flex-col sm:flex-row items-center justify-between gap-4 text-center sm:text-left">
            <p class="pp-footer__legal text-[11px] text-slate-500 max-w-2xl">
                &copy; {{ date('Y') }} {{ config('app.name', 'Thai Lottery Enterprise Wagering Platform') }}.
                All rights reserved. Independent wagering platform, operated by no state authority and endorsed by none. Published draw rules are referenced solely for prize-calculation compatibility.
                {{-- The full non-affiliation statement, which names the issuing
                     authority, is rendered on the Home page and on each lottery
                     lane page. It is deliberately NOT repeated here: this
                     footer is shared by every public page including /contact,
                     and naming the authority on a support page reads as an
                     endorsement claim rather than a disclaimer. --}}
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
                <span class="text-slate-600">v{{ config('app.version', '1.0.0') }}</span>
            </div>
        </div>
    </div>
</footer>
