<div id="mobile-menu-drawer" class="fixed inset-0 z-50 transform translate-x-full invisible pointer-events-none transition-transform duration-300 ease-in-out xl:hidden flex flex-col bg-[#0B0904]/98 backdrop-blur-xl border-l border-[#D4AF37]/25" role="dialog" aria-modal="true" aria-hidden="true" inert aria-label="{{ __('player.navigation_label') }}">
    <div class="flex items-center justify-between p-4 border-b border-[#D4AF37]/20 bg-[#141007]">
        <div class="flex items-center space-x-2.5">
            <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-[#D4AF37] via-[#F5E6B8] to-emerald-400 flex items-center justify-center font-black text-sm text-[#0B0904] shadow">TL</div>
            <span class="font-extrabold text-[#FFF6D6] tracking-tight">{{ config('app.name', 'Thai Lottery') }}</span>
        </div>
        <button type="button" id="btn-close-mobile-menu" class="inline-flex items-center justify-center min-h-[44px] min-w-[44px] p-2 text-[#F5E6B8] hover:text-white rounded-xl border border-[#D4AF37]/25 bg-[#0B0904]/70" aria-label="{{ __('player.close_menu') }}">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    <div class="flex-1 overflow-y-auto px-4 py-6 space-y-2">
        @auth
            <a href="{{ route('player.dashboard') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#FAF6EC] hover:bg-[#141007] border border-transparent hover:border-[#D4AF37]/25 transition">{{ __('player.nav_dashboard') }}</a>
            <a href="{{ route('player.bet') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-black text-[#0B0904] bg-gradient-to-r from-[#FFF6D6] via-[#F5E6B8] to-[#D4AF37] shadow transition">{{ __('player.nav_place_bet') }}</a>
            <a href="{{ route('player.draws') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#FAF6EC] hover:bg-[#141007] border border-transparent hover:border-[#D4AF37]/25 transition">{{ __('player.nav_draws') }}</a>
            <a href="{{ route('player.bets') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#FAF6EC] hover:bg-[#141007] border border-transparent hover:border-[#D4AF37]/25 transition">{{ __('player.nav_bets') }}</a>
            <a href="{{ route('player.wallet') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#FAF6EC] hover:bg-[#141007] border border-transparent hover:border-[#D4AF37]/25 transition">{{ __('player.nav_wallet') }}</a>
            <a href="{{ route('player.wallet.conversion') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#F5E6B8] bg-[#141007] border border-[#D4AF37]/25 transition">{{ __('wallet_conversion.page_title') }}</a>
            <a href="{{ route('player.deposit') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#F5E6B8] bg-[#141007] border border-[#D4AF37]/30 transition">{{ __('player.nav_deposit') }}</a>
            <a href="{{ route('glo-l6.index') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#F5E6B8] hover:bg-[#141007] transition">{{ __('player.nav_glo_l6') }}</a>
            <a href="{{ route('player.profile') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#FAF6EC] hover:bg-[#141007] transition">{{ __('player.nav_profile') }}</a>
            <a href="{{ route('account.verification') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#D4CAB3] hover:bg-[#141007] transition">{{ __('account_services.verification_heading') }}</a>
            <a href="{{ route('account.grade') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#D4CAB3] hover:bg-[#141007] transition">{{ __('account_services.grade_title') }}</a>
            <a href="{{ route('settings.index') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#D4CAB3] hover:bg-[#141007] transition">{{ __('player.responsible_gaming_title') }}</a>
            <a href="{{ route('support.index') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#D4CAB3] hover:bg-[#141007] transition">{{ __('support.nav_support') }}</a>
        @else
            <a href="{{ route('lotteries.index') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#FAF6EC] hover:bg-[#141007] transition">{{ __('player.nav_lotteries') }}</a>
            <a href="{{ route('national-lottery.index') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#F5E6B8] hover:bg-[#141007] transition">{{ __('public_pages.footer_lane_l6') }}</a>
            <a href="{{ route('weekly-lottery.index') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#FAF6EC] hover:bg-[#141007] transition">{{ __('public_pages.footer_lane_weekly') }}</a>
            <a href="{{ route('bingo-lottery.index') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#FAF6EC] hover:bg-[#141007] transition">{{ __('public_pages.footer_lane_bingo') }}</a>
            <a href="{{ route('pcso-lottery.index') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#FAF6EC] hover:bg-[#141007] transition">{{ __('public_pages.footer_lane_pcso') }}</a>
            <a href="{{ route('glo-l6.index') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#F5E6B8] hover:bg-[#141007] transition">{{ __('player.nav_glo_l6') }}</a>
            <a href="{{ route('results.index') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#FAF6EC] hover:bg-[#141007] transition">{{ __('player.nav_results') }}</a>
            <a href="{{ route('services') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#D4CAB3] hover:bg-[#141007] transition">{{ __('player.nav_services') }}</a>
            <a href="{{ route('about') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#D4CAB3] hover:bg-[#141007] transition">{{ __('player.nav_about') }}</a>
            <a href="{{ route('lotto-discount') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#D4CAB3] hover:bg-[#141007] transition">{{ __('player.nav_lotto_discount') }}</a>
            <a href="{{ route('ticket-check') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#FAF6EC] hover:bg-[#141007] transition">{{ __('player.nav_check_ticket') }}</a>
            <a href="{{ route('contact') }}" class="block min-h-[44px] px-4 py-3 rounded-xl text-base font-semibold text-[#D4CAB3] hover:bg-[#141007] transition">{{ __('player.nav_contact') }}</a>
        @endauth
    </div>

    <div class="p-4 border-t border-[#D4AF37]/20 bg-[#141007] space-y-3">
        <div class="flex items-center justify-between gap-2">
            <span class="text-xs text-[#D4CAB3] font-bold uppercase tracking-wider">{{ __('player.language_label') }}</span>
            <div class="flex items-center space-x-1 border border-[#D4AF37]/30 rounded-xl p-0.5 bg-[#0B0904]">
                <a href="{{ route('locale.switch', 'en') }}" class="inline-flex items-center justify-center min-h-[44px] min-w-[44px] px-3 py-1 rounded-lg text-xs font-bold {{ app()->getLocale() === 'en' ? 'bg-[#D4AF37] text-[#0B0904]' : 'text-[#D4CAB3]' }}">EN</a>
                <a href="{{ route('locale.switch', 'th') }}" class="inline-flex items-center justify-center min-h-[44px] min-w-[44px] px-3 py-1 rounded-lg text-xs font-bold {{ app()->getLocale() === 'th' ? 'bg-[#D4AF37] text-[#0B0904]' : 'text-[#D4CAB3]' }}">TH</a>
                <a href="{{ route('locale.switch', 'bn') }}" lang="bn" hreflang="bn" class="inline-flex items-center justify-center min-h-[44px] min-w-[44px] px-3 py-1 rounded-lg text-xs font-bold {{ app()->getLocale() === 'bn' ? 'bg-[#D4AF37] text-[#0B0904]' : 'text-[#D4CAB3]' }}" aria-label="বাংলা">বাংলা</a>
            </div>
        </div>

        @auth
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="w-full min-h-[44px] py-2.5 bg-[#0B0904] hover:bg-rose-950/60 text-[#FAF6EC] hover:text-rose-200 font-bold text-sm rounded-xl transition border border-[#D4AF37]/25">{{ __('player.logout') }}</button>
            </form>
        @else
            <div class="grid grid-cols-2 gap-2">
                <a href="{{ route('login') }}" class="inline-flex items-center justify-center min-h-[44px] py-2.5 text-center bg-[#0B0904] text-[#FFF6D6] font-bold text-sm rounded-xl border border-[#D4AF37]/35">{{ __('player.sign_in') }}</a>
                <a href="{{ route('register') }}" class="inline-flex items-center justify-center min-h-[44px] py-2.5 text-center bg-gradient-to-r from-[#FFF6D6] via-[#F5E6B8] to-[#D4AF37] text-[#0B0904] font-black text-sm rounded-xl">{{ __('player.register') }}</a>
            </div>
        @endauth
    </div>
</div>
