<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-900 text-slate-100">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Thai Lottery Enterprise Wagering Platform') }} - @yield('title', 'Player Portal')</title>

    {{-- Optional SEO yields — set only by public informational/legal pages.
         Home and the player app leave these sections unset so nothing changes for them. --}}
    @hasSection('meta_description')
        <meta name="description" content="@yield('meta_description')">
    @endif
    @hasSection('meta_canonical')
        <link rel="canonical" href="@yield('meta_canonical')">
    @endif
    {{-- PROMPT 5: pages that must not be indexed (query-dependent search
         results, empty-state pages) set this section. Pages that do not set
         it are unaffected, exactly as before. --}}
    @hasSection('meta_robots')
        <meta name="robots" content="@yield('meta_robots')">
    @endif
    @hasSection('meta_og_title')
        <meta property="og:title" content="@yield('meta_og_title')">
    @endif
    @hasSection('meta_og_description')
        <meta property="og:description" content="@yield('meta_og_description')">
    @endif
    @hasSection('meta_og_type')
        <meta property="og:type" content="@yield('meta_og_type')">
    @endif
    @hasSection('meta_og_url')
        <meta property="og:url" content="@yield('meta_og_url')">
    @endif

    {{-- Vite-built assets only (no CDN). --}}
    @vite([
        'resources/css/app.css',
        'resources/css/lottery.css',
        'resources/css/home.css',
        'resources/css/public-pages.css',
        'resources/css/account-services.css',
        'resources/js/app.js',
        'resources/js/wallet/wallet-balance.js',
        'resources/js/public-pages.js',
        'resources/js/account-verification.js',
        'resources/js/account-grade.js',
    ])
    @stack('styles')
</head>
<body class="h-full flex flex-col font-sans antialiased bg-slate-950 text-slate-100">
    <!-- Main Navigation Bar -->
    <header class="sticky top-0 z-40 bg-slate-900/90 backdrop-blur border-b border-slate-800 shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Brand Logo & Platform Title -->
                <div class="flex items-center space-x-3">
                    <a href="{{ auth()->check() ? route('player.dashboard') : route('home') }}" class="flex items-center space-x-2 group">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-amber-500 to-emerald-500 flex items-center justify-center font-black text-xl text-slate-950 shadow-md group-hover:scale-105 transition-transform">
                            TL
                        </div>
                        <div class="hidden sm:block">
                            <span class="text-lg font-bold bg-gradient-to-r from-amber-400 to-emerald-400 bg-clip-text text-transparent">
                                THAI LOTTERY
                            </span>
                            <span class="block text-xs text-slate-400 uppercase tracking-wider font-semibold">Enterprise Wagering</span>
                        </div>
                    </a>
                </div>

                @auth
                <!-- Navigation Links -->
                <nav class="hidden md:flex items-center space-x-1 lg:space-x-2">
                    <a href="{{ route('player.dashboard') }}" class="px-3 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('player.dashboard') ? 'bg-slate-800 text-emerald-400' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }} transition">
                        Dashboard
                    </a>
                    <a href="{{ route('player.bet') }}" class="px-3 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('player.bet') ? 'bg-emerald-600 text-white shadow-md' : 'text-emerald-400 hover:bg-slate-800' }} transition flex items-center space-x-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                        <span>Place Bet</span>
                    </a>
                    <a href="{{ route('player.draws') }}" class="px-3 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('player.draws*') ? 'bg-slate-800 text-emerald-400' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }} transition">
                        Draws & Results
                    </a>
                    <a href="{{ route('player.bets') }}" class="px-3 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('player.bets') ? 'bg-slate-800 text-emerald-400' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }} transition">
                        My Bets
                    </a>
                    <a href="{{ route('player.wallet') }}" class="px-3 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('player.wallet*') ? 'bg-slate-800 text-emerald-400' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }} transition">
                        Wallet
                    </a>
                </nav>

                <!-- User Profile & Wallet Quick Indicator -->
                <div class="flex items-center space-x-3 sm:space-x-4">
                    <div id="live-wallet-pill" class="flex items-center space-x-2 bg-slate-800/80 border border-slate-700/80 rounded-full px-3 py-1.5 text-xs sm:text-sm">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span class="text-slate-400">Balance:</span>
                        <span id="player-balance-display" class="font-bold text-amber-400">
                            {{ \App\Services\Finance\Money::of((string) (Auth::user()->wallet?->balance ?? '0'), \App\Enums\Currency::THB)->format() }}
                        </span>
                    </div>

                    <a href="{{ route('player.deposit') }}" class="hidden sm:inline-flex items-center px-3 py-1.5 bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold text-xs rounded-lg shadow transition">
                        + Deposit
                    </a>

                    <!-- User Dropdown Menu -->
                    <div class="relative flex items-center space-x-2">
                        <a href="{{ route('player.profile') }}" class="flex items-center space-x-2 p-1.5 rounded-lg hover:bg-slate-800 transition">
                            <div class="w-8 h-8 rounded-full bg-slate-700 flex items-center justify-center font-bold text-sm text-slate-200 border border-slate-600">
                                {{ strtoupper(substr(Auth::user()->name, 0, 1)) }}
                            </div>
                            <span class="hidden lg:inline text-sm font-medium text-slate-200">{{ Auth::user()->name }}</span>
                        </a>

                        <form method="POST" action="{{ route('logout') }}" class="inline">
                            @csrf
                            <button type="submit" class="p-2 text-slate-400 hover:text-red-400 transition" title="Log Out">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                            </button>
                        </form>
                    </div>
                </div>
                @else
                <!-- Guest public + auth actions -->
                <div class="flex items-center space-x-1 sm:space-x-3">
                    <nav class="hidden sm:flex items-center space-x-1" aria-label="Public">
                        <a href="{{ route('results.index') }}" class="px-3 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('results.index') ? 'bg-slate-800 text-emerald-400' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }} transition">Results</a>
                        <a href="{{ route('ticket-check') }}" class="px-3 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('ticket-check*') ? 'bg-slate-800 text-emerald-400' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }} transition">Check</a>
                        <a href="{{ route('sales-points') }}" class="px-3 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('sales-points') ? 'bg-slate-800 text-emerald-400' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }} transition">Sales Points</a>
                        {{-- PROMPT 7: the National Lottery lane shipped routed,
                             rendered and tested, but linked from nothing. It
                             sits between Results and Weekly Lottery because
                             that is the product order, and it is inside the
                             guest branch so anonymous visitors reach it
                             without a login. routeIs('national-lottery*')
                             keeps it highlighted across the landing, search,
                             year and draw-detail routes. --}}
                        <a href="{{ route('national-lottery.index') }}" class="px-3 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('national-lottery*') ? 'bg-slate-800 text-emerald-400' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }} transition">National Lottery</a>
                        {{-- PROMPT 6: the Weekly Lottery result surface is a
                             public product page, so it needs a way in. A page
                             that only exists at a URL nobody is given is a
                             half-delivered feature. routeIs('weekly-lottery*')
                             keeps it highlighted across the landing, year,
                             search and draw-detail routes. --}}
                        <a href="{{ route('weekly-lottery.index') }}" class="px-3 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('weekly-lottery*') ? 'bg-slate-800 text-emerald-400' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }} transition">Weekly Lottery</a>
                        {{-- PROMPT 8: the Mega Lottery lane. The code calls it
                             "bingo" because that is the roadmap's name for the
                             slot; the LABEL says Mega Lottery because that is
                             what the page actually publishes. National and
                             Weekly keep their positions - this is appended
                             after them, not inserted among them. --}}
                        <a href="{{ route('bingo-lottery.index') }}" class="px-3 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('bingo-lottery*') ? 'bg-slate-800 text-emerald-400' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }} transition">Mega Lottery</a>
                        {{-- PROMPT 9: the PCSO lane. Appended after the three
                             existing lanes rather than inserted among them, so
                             no existing link moves. --}}
                        <a href="{{ route('pcso-lottery.index') }}" class="px-3 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('pcso-lottery*') ? 'bg-slate-800 text-emerald-400' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }} transition">PCSO Lottery</a>
                        {{-- PROMPT 10: Contact. Appended after the four result
                             lanes, so no existing link moves. --}}
                        <a href="{{ route('contact') }}" class="px-3 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('contact*') ? 'bg-slate-800 text-emerald-400' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }} transition">Contact Us</a>
                        {{-- AUDIT FIX 8: information pages. The replaced site
                             exposed About, Vision & Mission, Terms, Fees,
                             Account Verify, Account Grade, Prize Verification
                             and Lotto Discount as first-class navigation
                             destinations. They are grouped in a CSS-only
                             dropdown (group-hover + focus-within, no
                             JavaScript) so the guest nav stays one row. --}}
                        <div class="relative group">
                            <button type="button" aria-haspopup="true" class="px-3 py-2 rounded-lg text-sm font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition flex items-center">
                                <span>Information</span>
                                <svg class="w-3.5 h-3.5 ml-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div class="invisible opacity-0 group-hover:visible group-hover:opacity-100 focus-within:visible focus-within:opacity-100 transition-all duration-150 absolute right-0 top-full pt-2 w-52 z-30">
                                <div class="rounded-xl border border-slate-700/80 bg-slate-900 shadow-xl py-1.5">
                                    <a href="{{ route('about') }}" class="block px-4 py-2 text-sm text-slate-300 hover:bg-slate-800 hover:text-white transition">About</a>
                                    <a href="{{ route('vision') }}" class="block px-4 py-2 text-sm text-slate-300 hover:bg-slate-800 hover:text-white transition">Vision &amp; Mission</a>
                                    <a href="{{ route('terms') }}" class="block px-4 py-2 text-sm text-slate-300 hover:bg-slate-800 hover:text-white transition">Terms</a>
                                    <a href="{{ route('fees') }}" class="block px-4 py-2 text-sm text-slate-300 hover:bg-slate-800 hover:text-white transition">Fees</a>
                                    <a href="{{ route('account-verification-guide') }}" class="block px-4 py-2 text-sm text-slate-300 hover:bg-slate-800 hover:text-white transition">Account Verify</a>
                                    <a href="{{ route('account-grades') }}" class="block px-4 py-2 text-sm text-slate-300 hover:bg-slate-800 hover:text-white transition">Account Grade</a>
                                    <a href="{{ route('prize-verification') }}" class="block px-4 py-2 text-sm text-slate-300 hover:bg-slate-800 hover:text-white transition">Prize Verification</a>
                                    <a href="{{ route('discounts') }}" class="block px-4 py-2 text-sm text-slate-300 hover:bg-slate-800 hover:text-white transition">Lotto Discount</a>
                                </div>
                            </div>
                        </div>
                    </nav>
                    <a href="{{ route('register') }}" class="px-3 py-2 text-sm font-semibold text-emerald-400 hover:text-emerald-300 transition">Register</a>
                    <a href="{{ route('login') }}" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-lg shadow transition">
                        Sign In
                    </a>
                </div>
                @endauth
            </div>
        </div>

        <!-- Mobile Bottom Nav -->
        @auth
        <div class="md:hidden flex items-center justify-around bg-slate-900 border-t border-slate-800 py-2 px-2 text-xs">
            <a href="{{ route('player.dashboard') }}" class="flex flex-col items-center py-1 px-2 {{ request()->routeIs('player.dashboard') ? 'text-emerald-400' : 'text-slate-400' }}">
                <svg class="w-5 h-5 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                <span>Home</span>
            </a>
            <a href="{{ route('player.bet') }}" class="flex flex-col items-center py-1 px-2 {{ request()->routeIs('player.bet') ? 'text-emerald-400 font-bold' : 'text-slate-400' }}">
                <svg class="w-5 h-5 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span>Play</span>
            </a>
            <a href="{{ route('player.draws') }}" class="flex flex-col items-center py-1 px-2 {{ request()->routeIs('player.draws*') ? 'text-emerald-400' : 'text-slate-400' }}">
                <svg class="w-5 h-5 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <span>Draws</span>
            </a>
            <a href="{{ route('player.bets') }}" class="flex flex-col items-center py-1 px-2 {{ request()->routeIs('player.bets') ? 'text-emerald-400' : 'text-slate-400' }}">
                <svg class="w-5 h-5 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                <span>Bets</span>
            </a>
            <a href="{{ route('player.wallet') }}" class="flex flex-col items-center py-1 px-2 {{ request()->routeIs('player.wallet*') ? 'text-emerald-400' : 'text-slate-400' }}">
                <svg class="w-5 h-5 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                <span>Wallet</span>
            </a>
        </div>
        @endauth
    </header>

    <!-- Global Notifications / Alerts -->
    @if (session('status'))
        <div class="bg-emerald-900/50 border-b border-emerald-500/30 text-emerald-200 px-4 py-3 text-sm text-center">
            {{ session('status') }}
        </div>
    @endif
    @if (session('error'))
        <div class="bg-rose-900/50 border-b border-rose-500/30 text-rose-200 px-4 py-3 text-sm text-center">
            {{ session('error') }}
        </div>
    @endif

    <!-- Main Content Area -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
        @yield('content')
    </main>

    <!-- Platform Footer -->
    <footer class="bg-slate-900/60 border-t border-slate-800/80 py-6 text-center text-xs text-slate-500">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div>
                &copy; {{ date('Y') }} {{ config('app.name', 'Thai Lottery') }}. All rights reserved.
            </div>
            <div class="flex items-center space-x-4">
                <span class="inline-flex items-center text-emerald-400">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 mr-1.5 animate-pulse"></span>
                    API & Ledger Healthy
                </span>
                <span>Version 5.3.8</span>
            </div>
        </div>
    </footer>

    <!-- Core Scripts -->
    @stack('scripts')
</body>
</html>
