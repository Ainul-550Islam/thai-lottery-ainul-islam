<div id="mobile-menu-drawer" class="fixed inset-0 z-50 transform translate-x-full transition-transform duration-300 ease-in-out md:hidden flex flex-col bg-slate-950/95 backdrop-blur-xl border-l border-slate-800" role="dialog" aria-modal="true" aria-label="{{ __('player.navigation_label') }}">
    <div class="flex items-center justify-between p-4 border-b border-slate-800">
        <div class="flex items-center space-x-2">
            <div class="w-8 h-8 rounded-lg bg-gradient-to-tr from-amber-500 to-emerald-500 flex items-center justify-center font-black text-sm text-slate-950">TL</div>
            <span class="font-bold text-white tracking-tight">Thai Lottery</span>
        </div>
        <button type="button" id="btn-close-mobile-menu" class="p-2 text-slate-400 hover:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500" aria-label="{{ __('player.close_menu') }}">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    <div class="flex-1 overflow-y-auto px-4 py-6 space-y-2">
        @auth
            <a href="{{ route('player.dashboard') }}" class="block px-4 py-3 rounded-xl text-base font-semibold text-slate-200 hover:bg-slate-800 transition">{{ __('player.nav_dashboard') }}</a>
            <a href="{{ route('player.bet') }}" class="block px-4 py-3 rounded-xl text-base font-bold text-emerald-400 bg-emerald-950/40 border border-emerald-500/30 transition">{{ __('player.nav_place_bet') }}</a>
            <a href="{{ route('player.draws') }}" class="block px-4 py-3 rounded-xl text-base font-semibold text-slate-200 hover:bg-slate-800 transition">{{ __('player.nav_draws') }}</a>
            <a href="{{ route('player.bets') }}" class="block px-4 py-3 rounded-xl text-base font-semibold text-slate-200 hover:bg-slate-800 transition">{{ __('player.nav_bets') }}</a>
            <a href="{{ route('player.wallet') }}" class="block px-4 py-3 rounded-xl text-base font-semibold text-slate-200 hover:bg-slate-800 transition">{{ __('player.nav_wallet') }}</a>
            <a href="{{ route('player.deposit') }}" class="block px-4 py-3 rounded-xl text-base font-semibold text-amber-400 bg-amber-950/30 border border-amber-500/20 transition">{{ __('player.nav_deposit') }}</a>
            <a href="{{ route('glo-l6.index') }}" class="block px-4 py-3 rounded-xl text-base font-semibold text-amber-300 hover:bg-slate-800 transition">{{ __('player.nav_glo_l6') }}</a>
            <a href="{{ route('player.profile') }}" class="block px-4 py-3 rounded-xl text-base font-semibold text-slate-200 hover:bg-slate-800 transition">{{ __('player.nav_profile') }}</a>
        @else
            <a href="{{ route('lotteries.index') }}" class="block px-4 py-3 rounded-xl text-base font-semibold text-slate-200 hover:bg-slate-800 transition">{{ __('player.nav_lotteries') }}</a>
            <a href="{{ route('glo-l6.index') }}" class="block px-4 py-3 rounded-xl text-base font-semibold text-amber-300 hover:bg-slate-800 transition">{{ __('player.nav_glo_l6') }}</a>
            <a href="{{ route('results.index') }}" class="block px-4 py-3 rounded-xl text-base font-semibold text-slate-200 hover:bg-slate-800 transition">{{ __('player.nav_results') }}</a>
            <a href="{{ route('ticket-check') }}" class="block px-4 py-3 rounded-xl text-base font-semibold text-slate-200 hover:bg-slate-800 transition">{{ __('player.nav_check_ticket') }}</a>
            <a href="{{ route('contact') }}" class="block px-4 py-3 rounded-xl text-base font-semibold text-slate-200 hover:bg-slate-800 transition">{{ __('player.nav_contact') }}</a>
        @endauth
    </div>

    <div class="p-4 border-t border-slate-800 bg-slate-900/50 space-y-3">
        <div class="flex items-center justify-between">
            <span class="text-xs text-slate-400 font-bold uppercase tracking-wider">{{ __('player.language_label') }}</span>
            <div class="flex items-center space-x-1 border border-slate-700 rounded-lg p-0.5 bg-slate-950">
                <a href="{{ route('locale.switch', 'en') }}" class="px-3 py-1 rounded text-xs font-bold {{ app()->getLocale() === 'en' ? 'bg-emerald-600 text-white' : 'text-slate-400' }}">EN</a>
                <a href="{{ route('locale.switch', 'th') }}" class="px-3 py-1 rounded text-xs font-bold {{ app()->getLocale() === 'th' ? 'bg-emerald-600 text-white' : 'text-slate-400' }}">TH</a>
            </div>
        </div>

        @auth
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="w-full py-2.5 bg-slate-800 hover:bg-rose-950/50 text-slate-300 hover:text-rose-300 font-bold text-sm rounded-xl transition border border-slate-700">{{ __('player.logout') }}</button>
            </form>
        @else
            <div class="grid grid-cols-2 gap-2">
                <a href="{{ route('login') }}" class="py-2.5 text-center bg-slate-800 text-slate-200 font-bold text-sm rounded-xl border border-slate-700">{{ __('player.sign_in') }}</a>
                <a href="{{ route('register') }}" class="py-2.5 text-center bg-emerald-600 text-white font-bold text-sm rounded-xl">{{ __('player.register') }}</a>
            </div>
        @endauth
    </div>
</div>
