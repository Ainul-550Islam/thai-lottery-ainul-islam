<footer class="bg-slate-900/60 border-t border-slate-800/80 backdrop-blur-xl py-12 text-xs text-slate-400" role="contentinfo">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
        <!-- Top Section: Brand + Links Grid -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
            <!-- Brand Column -->
            <div class="space-y-3 md:col-span-1">
                <div class="flex items-center space-x-2.5">
                    <div class="w-8 h-8 rounded-lg bg-gradient-to-tr from-amber-500 to-emerald-500 flex items-center justify-center font-black text-sm text-slate-950">
                        TL
                    </div>
                    <span class="font-extrabold text-white text-base tracking-tight">Thai Lottery</span>
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
                    <li><a href="{{ route('contact') }}" class="hover:text-emerald-400 transition">Contact &amp; Support</a></li>
                </ul>
            </div>
        </div>

        <!-- Legal Disclaimer & Copyright -->
        <div class="pt-8 border-t border-slate-800 flex flex-col sm:flex-row items-center justify-between gap-4 text-center sm:text-left">
            <p class="text-[11px] text-slate-500 max-w-2xl">
                &copy; {{ date('Y') }} {{ config('app.name', 'Thai Lottery Enterprise Wagering Platform') }}.
                All rights reserved. Independent wagering platform. Government Lottery Office (GLO) rules are referenced solely for compatibility and prize calculation accuracy; this site is not operated by or affiliated with the Government Lottery Office.
            </p>
            <div class="flex items-center space-x-4 shrink-0 text-[11px]">
                <span class="inline-flex items-center text-emerald-400 font-semibold">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 mr-1.5 animate-pulse"></span>
                    System Operational
                </span>
                <span class="text-slate-600">v{{ config('app.version', '1.0.0') }}</span>
            </div>
        </div>
    </div>
</footer>
