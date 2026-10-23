@props([
    'activeRoute' => null,
])

<nav class="hidden xl:flex items-center space-x-1 lg:space-x-1.5" aria-label="{{ __('player.navigation_label') }}">
    @auth
        <a href="{{ route('player.dashboard') }}" class="inline-flex items-center min-h-[40px] px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('player.dashboard') ? 'bg-[#D4AF37]/15 text-[#FFF6D6] border border-[#D4AF37]/40 shadow-sm' : 'text-[#D4CAB3] hover:bg-white/5 hover:text-[#FFF6D6]' }}" @if(request()->routeIs('player.dashboard')) aria-current="page" @endif>
            {{ __('player.nav_dashboard') }}
        </a>
        <a href="{{ route('player.bet') }}" class="inline-flex items-center min-h-[40px] px-3.5 py-2 rounded-xl text-sm font-black transition space-x-1.5 {{ request()->routeIs('player.bet') ? 'bg-gradient-to-r from-[#FFF6D6] via-[#F5E6B8] to-[#D4AF37] text-[#0B0904] shadow-md' : 'bg-[#D4AF37]/15 text-[#F5E6B8] hover:bg-[#D4AF37]/25 border border-[#D4AF37]/35' }}" @if(request()->routeIs('player.bet')) aria-current="page" @endif>
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 6v6m0 0v6m6-6H6"/></svg>
            <span>{{ __('player.nav_place_bet') }}</span>
        </a>
        <a href="{{ route('player.draws') }}" class="inline-flex items-center min-h-[40px] px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('player.draws*') ? 'bg-[#D4AF37]/15 text-[#FFF6D6] border border-[#D4AF37]/40 shadow-sm' : 'text-[#D4CAB3] hover:bg-white/5 hover:text-[#FFF6D6]' }}" @if(request()->routeIs('player.draws*')) aria-current="page" @endif>
            {{ __('player.nav_draws') }}
        </a>
        <a href="{{ route('player.bets') }}" class="inline-flex items-center min-h-[40px] px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('player.bets') ? 'bg-[#D4AF37]/15 text-[#FFF6D6] border border-[#D4AF37]/40 shadow-sm' : 'text-[#D4CAB3] hover:bg-white/5 hover:text-[#FFF6D6]' }}" @if(request()->routeIs('player.bets')) aria-current="page" @endif>
            {{ __('player.nav_bets') }}
        </a>
        <a href="{{ route('player.wallet') }}" class="inline-flex items-center min-h-[40px] px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('player.wallet', 'player.wallet.*') ? 'bg-[#D4AF37]/15 text-[#FFF6D6] border border-[#D4AF37]/40 shadow-sm' : 'text-[#D4CAB3] hover:bg-white/5 hover:text-[#FFF6D6]' }}" @if(request()->routeIs('player.wallet', 'player.wallet.*')) aria-current="page" @endif>
            {{ __('player.nav_wallet') }}
        </a>
        <a href="{{ route('glo-l6.index') }}" class="inline-flex items-center min-h-[40px] px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('glo-l6.*') ? 'bg-[#D4AF37]/15 text-[#FFF6D6] border border-[#D4AF37]/40 shadow-sm' : 'text-[#D4CAB3] hover:bg-white/5 hover:text-[#FFF6D6]' }}" @if(request()->routeIs('glo-l6.*')) aria-current="page" @endif>
            {{ __('player.nav_glo_l6') }}
        </a>
    @else
        <a href="{{ route('lotteries.index') }}" class="inline-flex items-center min-h-[40px] px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('lotteries.*') ? 'bg-[#D4AF37]/15 text-[#FFF6D6] border border-[#D4AF37]/40 shadow-sm' : 'text-[#D4CAB3] hover:bg-white/5 hover:text-[#FFF6D6]' }}" @if(request()->routeIs('lotteries.*')) aria-current="page" @endif>
            {{ __('player.nav_lotteries') }}
        </a>
        <a href="{{ route('glo-l6.index') }}" class="inline-flex items-center min-h-[40px] px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('glo-l6.*') ? 'bg-[#D4AF37]/15 text-[#FFF6D6] border border-[#D4AF37]/40 shadow-sm' : 'text-[#D4CAB3] hover:bg-white/5 hover:text-[#FFF6D6]' }}" @if(request()->routeIs('glo-l6.*')) aria-current="page" @endif>
            {{ __('player.nav_glo_l6') }}
        </a>
        <a href="{{ route('results.index') }}" class="inline-flex items-center min-h-[40px] px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('results.*') ? 'bg-[#D4AF37]/15 text-[#FFF6D6] border border-[#D4AF37]/40 shadow-sm' : 'text-[#D4CAB3] hover:bg-white/5 hover:text-[#FFF6D6]' }}" @if(request()->routeIs('results.*')) aria-current="page" @endif>
            {{ __('player.nav_results') }}
        </a>
        <a href="{{ route('services') }}" class="hidden xl:inline-flex items-center min-h-[40px] px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('services*', 'our-services') ? 'bg-[#D4AF37]/15 text-[#FFF6D6] border border-[#D4AF37]/40 shadow-sm' : 'text-[#D4CAB3] hover:bg-white/5 hover:text-[#FFF6D6]' }}">{{ __('player.nav_services') }}</a>
        <a href="{{ route('about') }}" class="hidden xl:inline-flex items-center min-h-[40px] px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('about') ? 'bg-[#D4AF37]/15 text-[#FFF6D6] border border-[#D4AF37]/40 shadow-sm' : 'text-[#D4CAB3] hover:bg-white/5 hover:text-[#FFF6D6]' }}">{{ __('player.nav_about') }}</a>
        <a href="{{ route('lotto-discount') }}" class="hidden xl:inline-flex items-center min-h-[40px] px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('lotto-discount', 'discounts') ? 'bg-[#D4AF37]/15 text-[#FFF6D6] border border-[#D4AF37]/40 shadow-sm' : 'text-[#D4CAB3] hover:bg-white/5 hover:text-[#FFF6D6]' }}">{{ __('player.nav_lotto_discount') }}</a>
        <a href="{{ route('ticket-check') }}" class="inline-flex items-center min-h-[40px] px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('ticket-check*') ? 'bg-[#D4AF37]/15 text-[#FFF6D6] border border-[#D4AF37]/40 shadow-sm' : 'text-[#D4CAB3] hover:bg-white/5 hover:text-[#FFF6D6]' }}" @if(request()->routeIs('ticket-check*')) aria-current="page" @endif>
            {{ __('player.nav_check_ticket') }}
        </a>
        <a href="{{ route('contact') }}" class="inline-flex items-center min-h-[40px] px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('contact*') ? 'bg-[#D4AF37]/15 text-[#FFF6D6] border border-[#D4AF37]/40 shadow-sm' : 'text-[#D4CAB3] hover:bg-white/5 hover:text-[#FFF6D6]' }}" @if(request()->routeIs('contact*')) aria-current="page" @endif>
            {{ __('player.nav_contact') }}
        </a>
    @endauth
</nav>
