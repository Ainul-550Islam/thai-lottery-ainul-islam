@props([
    'activeRoute' => null,
])

<nav class="hidden md:flex items-center space-x-1 lg:space-x-2" aria-label="{{ __('player.navigation_label') }}">
    @auth
        <a href="{{ route('player.dashboard') }}" class="px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('player.dashboard') ? 'bg-slate-800 text-emerald-400 border border-slate-700/80 shadow-sm' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white' }}">
            {{ __('player.nav_dashboard') }}
        </a>
        <a href="{{ route('player.bet') }}" class="px-3.5 py-2 rounded-xl text-sm font-bold transition flex items-center space-x-1.5 {{ request()->routeIs('player.bet') ? 'bg-emerald-600 text-white shadow-lg shadow-emerald-900/30' : 'bg-emerald-500/10 text-emerald-400 hover:bg-emerald-500/20 border border-emerald-500/20' }}">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 6v6m0 0v6m6-6H6"/></svg>
            <span>{{ __('player.nav_place_bet') }}</span>
        </a>
        <a href="{{ route('player.draws') }}" class="px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('player.draws*') ? 'bg-slate-800 text-emerald-400 border border-slate-700/80 shadow-sm' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white' }}">
            {{ __('player.nav_draws') }}
        </a>
        <a href="{{ route('player.bets') }}" class="px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('player.bets') ? 'bg-slate-800 text-emerald-400 border border-slate-700/80 shadow-sm' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white' }}">
            {{ __('player.nav_bets') }}
        </a>
        <a href="{{ route('player.wallet') }}" class="px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('player.wallet') ? 'bg-slate-800 text-emerald-400 border border-slate-700/80 shadow-sm' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white' }}">
            {{ __('player.nav_wallet') }}
        </a>
        <a href="{{ route('glo-l6.index') }}" class="px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('glo-l6.*') ? 'bg-slate-800 text-amber-300 border border-amber-500/30 shadow-sm' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white' }}">
            {{ __('player.nav_glo_l6') }}
        </a>
    @else
        <a href="{{ route('lotteries.index') }}" class="px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('lotteries.*') ? 'bg-slate-800 text-emerald-400 border border-slate-700/80 shadow-sm' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white' }}">
            {{ __('player.nav_lotteries') }}
        </a>
        <a href="{{ route('glo-l6.index') }}" class="px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('glo-l6.*') ? 'bg-slate-800 text-amber-300 border border-amber-500/30 shadow-sm' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white' }}">
            {{ __('player.nav_glo_l6') }}
        </a>
        <a href="{{ route('results.index') }}" class="px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('results.index') ? 'bg-slate-800 text-emerald-400 border border-slate-700/80 shadow-sm' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white' }}">
            {{ __('player.nav_results') }}
        </a>
        <a href="{{ route('ticket-check') }}" class="px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('ticket-check*') ? 'bg-slate-800 text-emerald-400 border border-slate-700/80 shadow-sm' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white' }}">
            {{ __('player.nav_check_ticket') }}
        </a>
        <a href="{{ route('contact') }}" class="px-3 py-2 rounded-xl text-sm font-semibold transition {{ request()->routeIs('contact*') ? 'bg-slate-800 text-emerald-400 border border-slate-700/80 shadow-sm' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white' }}">
            {{ __('player.nav_contact') }}
        </a>
    @endauth
</nav>
