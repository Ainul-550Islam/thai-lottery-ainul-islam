<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-950 text-slate-100">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{--
        Real-time bridge identity + client block (resources/js/echo.js).

        The viewer id is emitted ONLY for an authenticated viewer. This layout also
        renders public pages that the public.legal middleware stores in a shared
        cache; an identity tag on one of those would hand one visitor's id to the
        next. Guests therefore get no tag at all, and authenticated responses are
        never publicly cacheable (the middleware answers no-store for them).

        The client block carries public facts only - the Reverb APP KEY is a public
        identifier, the secret lives in config/broadcasting.php and is never here.
    --}}
    @auth
        <meta name="user-id" content="{{ auth()->id() }}">
    @endauth
    <meta name="realtime-enabled" content="{{ config('realtime.enabled') ? '1' : '0' }}">
    @if (config('realtime.enabled'))
        <meta name="realtime-key" content="{{ config('realtime.client.key') }}">
        <meta name="realtime-host" content="{{ config('realtime.client.host') }}">
        <meta name="realtime-port" content="{{ config('realtime.client.port') }}">
        <meta name="realtime-scheme" content="{{ config('realtime.client.scheme') }}">
        <meta name="realtime-auth-endpoint" content="{{ config('realtime.client.auth_endpoint') }}">
        <meta name="realtime-reconnect-min" content="{{ config('realtime.client.reconnect_min_ms') }}">
        <meta name="realtime-reconnect-max" content="{{ config('realtime.client.reconnect_max_ms') }}">
    @endif
    {{-- Read by script bundles that render the brand, so the name lives
         in configuration rather than in compiled JavaScript. --}}
    <meta name="app-name" content="{{ config('app.name') }}">
    <meta name="theme-color" content="#059669">

    <title>@yield('title', 'Player Portal') — {{ config('app.name', 'Thai Lottery Enterprise Wagering Platform') }}</title>

    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">

    {{-- SEO yields --}}
    @hasSection('meta_description')
        <meta name="description" content="@yield('meta_description')">
    @endif
    @hasSection('meta_canonical')
        <link rel="canonical" href="@yield('meta_canonical')">
    @endif
    {{--
        Robots directive.

        `meta_robots` is the documented name; `robots` is accepted as well because
        several account pages (notifications, support, …) had always declared
        @section('robots', 'noindex, nofollow') and this layout only ever read
        `meta_robots`, so their noindex was silently dropped and private account
        pages were served as indexable. Reading both means neither spelling can
        disappear; new views should use `meta_robots`.
    --}}
    @hasSection('meta_robots')
        <meta name="robots" content="@yield('meta_robots')">
    @else
        @hasSection('robots')
            <meta name="robots" content="@yield('robots')">
        @endif
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

    {{-- Batch 08: localized realtime toast copy. Keys are dot-free so no raw
         translation key can ever be printed by the notification bridge. --}}
    @php
        $realtimeL10n = [
            'dismiss' => __('deposit.toast_dismiss'),
            'walletTitle' => __('deposit.toast_wallet_title'),
            'walletMessage' => __('deposit.toast_wallet_message'),
            'depositTitle' => __('deposit.toast_deposit_title'),
            'withdrawalTitle' => __('deposit.toast_withdrawal_title'),
            'payoutTitle' => __('deposit.toast_payout_title'),
            'betTitle' => __('deposit.toast_bet_title'),
            'resyncedTitle' => __('deposit.toast_resynced_title'),
            'resyncedMessage' => __('deposit.toast_resynced_message'),
            'statusPrefix' => __('deposit.toast_status_prefix'),
            'pending' => __('deposit.toast_status_pending'),
            'processing' => __('deposit.toast_status_processing'),
            'confirmed' => __('deposit.toast_status_confirmed'),
            'approved' => __('deposit.toast_status_approved'),
            'completed' => __('deposit.toast_status_completed'),
            'failed' => __('deposit.toast_status_failed'),
            'rejected' => __('deposit.toast_status_rejected'),
            'cancelled' => __('deposit.toast_status_cancelled'),
            'canceled' => __('deposit.toast_status_cancelled'),
            'expired' => __('deposit.toast_status_expired'),
            'paid' => __('deposit.toast_status_paid'),
            'burstSuffix' => __('deposit.toast_burst_suffix'),
        ];
    @endphp
    <script type="application/json" id="realtime-l10n">@json($realtimeL10n)</script>

    {{-- Vite-built assets --}}
    @vite([
        'resources/css/app.css',
        'resources/css/lottery.css',
        'resources/css/home.css',
        'resources/css/public-pages.css',
        'resources/css/account-services.css',
        'resources/js/app.js',
        'resources/js/layout/mobile-menu.js',
        'resources/js/wallet/wallet-balance.js',
        'resources/js/public-pages.js',
        'resources/js/account-verification.js',
        'resources/js/account-grade.js',
    ])
    @stack('styles')
</head>
<body class="h-full flex flex-col font-sans antialiased bg-slate-950 text-slate-100 selection:bg-emerald-500 selection:text-slate-950">
    <!-- Header -->
    <header class="sticky top-0 z-40 bg-slate-900/80 backdrop-blur-xl border-b border-slate-800/80 shadow-lg shadow-black/20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Brand Logo -->
                <div class="flex items-center space-x-3">
                    <a href="{{ auth()->check() ? route('player.dashboard') : route('home') }}" class="flex items-center space-x-2.5 group">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-amber-500 via-emerald-500 to-teal-400 flex items-center justify-center font-black text-xl text-slate-950 shadow-md group-hover:scale-105 transition-transform duration-200">
                            TL
                        </div>
                        <div class="hidden sm:block">
                            <span class="text-lg font-black tracking-tight bg-gradient-to-r from-amber-400 via-emerald-400 to-teal-300 bg-clip-text text-transparent">
                                THAI LOTTERY
                            </span>
                            <span class="block text-[10px] text-slate-400 uppercase tracking-widest font-bold">Enterprise Wagering</span>
                        </div>
                    </a>
                </div>

                <!-- Desktop Navigation -->
                <x-navigation.main-nav />

                <!-- Action Controls (Wallet, Auth, Mobile Menu Trigger) -->
                <div class="flex items-center space-x-2.5 sm:space-x-4">
                    <!-- Language Selector (Desktop) -->
                    <div class="hidden sm:flex items-center space-x-0.5 border border-slate-700/80 rounded-xl p-0.5 text-xs font-bold bg-slate-950/60 shadow-inner">
                        <a href="{{ route('locale.switch', 'en') }}" class="px-2.5 py-1 rounded-lg transition {{ app()->getLocale() === 'en' ? 'bg-emerald-600 text-white shadow' : 'text-slate-400 hover:text-slate-200' }}">EN</a>
                        <a href="{{ route('locale.switch', 'th') }}" class="px-2.5 py-1 rounded-lg transition {{ app()->getLocale() === 'th' ? 'bg-emerald-600 text-white shadow' : 'text-slate-400 hover:text-slate-200' }}">TH</a>
                        <a href="{{ route('locale.switch', 'bn') }}" lang="bn" class="px-2.5 py-1 rounded-lg transition {{ app()->getLocale() === 'bn' ? 'bg-emerald-600 text-white shadow' : 'text-slate-400 hover:text-slate-200' }}" aria-label="বাংলা">বাংলা</a>
                    </div>

                    @auth
                        <!-- Live Wallet Indicator -->
                        <div id="live-wallet-pill" class="flex items-center space-x-2 bg-slate-900/90 border border-slate-700/80 rounded-full px-3.5 py-1.5 text-xs sm:text-sm shadow-inner">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                            <span class="text-slate-400 font-medium">Balance:</span>
                            <span id="player-balance-display" class="font-mono font-bold text-amber-400" data-live-balance="wallet" data-currency="THB" aria-live="polite" aria-atomic="true">
                                {{ \App\Services\Finance\Money::of((string) (Auth::user()->wallet?->balance ?? '0'), \App\Enums\Currency::THB)->format() }}
                            </span>
                        </div>

                        <a href="{{ route('player.deposit') }}" class="hidden sm:inline-flex items-center px-3.5 py-1.5 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-400 hover:to-amber-500 text-slate-950 font-black text-xs rounded-xl shadow-md transition">
                            + Deposit
                        </a>

                        <!-- User Profile Menu -->
                        <div class="relative flex items-center space-x-2">
                            <a href="{{ route('player.profile') }}" class="flex items-center space-x-2 p-1.5 rounded-xl hover:bg-slate-800 transition" title="Profile">
                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-emerald-600 to-teal-800 flex items-center justify-center font-black text-sm text-white border border-emerald-400/30">
                                    {{ strtoupper(substr(Auth::user()->name ?? 'P', 0, 1)) }}
                                </div>
                            </a>

                            <form method="POST" action="{{ route('logout') }}" class="inline">
                                @csrf
                                <button type="submit" class="p-2 text-slate-400 hover:text-rose-400 transition" title="Log Out">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                                </button>
                            </form>
                        </div>
                    @else
                        <div class="hidden sm:flex items-center space-x-2">
                            <a href="{{ route('login') }}" class="px-3.5 py-2 text-sm font-bold text-slate-300 hover:text-white transition">
                                Sign In
                            </a>
                            <a href="{{ route('register') }}" class="px-4 py-2 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white text-sm font-bold rounded-xl shadow-md transition">
                                Register
                            </a>
                        </div>
                    @endauth

                    <!-- Mobile Menu Hamburger Button -->
                    <button type="button"
                            id="btn-open-mobile-menu"
                            class="xl:hidden p-2 text-slate-400 hover:text-white rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            aria-label="Open Navigation Menu">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                </div>
            </div>
        </div>
    </header>

    <!-- Mobile Navigation Drawer -->
    <x-navigation.mobile-nav />

    <!-- Global Notifications / Flash Feedback -->
    @if (session('status'))
        <div class="bg-emerald-950/80 border-b border-emerald-500/30 text-emerald-200 px-4 py-3 text-sm text-center font-medium backdrop-blur">
            {{ session('status') }}
        </div>
    @endif
    @if (session('error'))
        <div class="bg-rose-950/80 border-b border-rose-500/30 text-rose-200 px-4 py-3 text-sm text-center font-medium backdrop-blur">
            {{ session('error') }}
        </div>
    @endif

    <!-- Main Content Container -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">
        @yield('content')
    </main>

    <!-- Global Platform Footer -->
    <x-public-page.footer />

    <!-- Core Scripts -->
    @stack('scripts')
</body>
</html>
