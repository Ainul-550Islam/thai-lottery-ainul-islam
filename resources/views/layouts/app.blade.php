<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-[#0B0904] text-[#FFF6D6]">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="app-name" content="{{ config('app.name') }}">
    <meta name="theme-color" content="#0B0904">
    <meta name="ui-search-matches" content="{{ __('frontend_ui.search_matches', ['count' => '__COUNT__']) }}">
    @auth
        <meta name="user-id" content="{{ auth()->id() }}">
    @endauth

    @php
        $brandTitle = (string) config('app.name', 'Thai Lottery Platform');
        $rawPageTitle = trim((string) $__env->yieldContent('title', 'Player Portal'));
        $composedTitle = ($rawPageTitle !== '' && stripos($rawPageTitle, $brandTitle) !== false)
            ? $rawPageTitle
            : ($rawPageTitle !== '' ? $rawPageTitle.' — '.$brandTitle : $brandTitle);
    @endphp
    <title>{{ $composedTitle }}</title>

    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/icons/favicon-16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/icons/apple-touch-icon.png">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">

    {{-- SEO & Robots Directives (private/authenticated views default to noindex,nofollow) --}}
    @hasSection('meta_description')
        <meta name="description" content="@yield('meta_description')">
    @endif
    @hasSection('meta_canonical')
        <link rel="canonical" href="@yield('meta_canonical')">
    @endif
    @if (View::hasSection('meta_robots'))
        <meta name="robots" content="@yield('meta_robots')">
    @elseif (View::hasSection('robots'))
        <meta name="robots" content="@yield('robots')">
    @elseif (auth()->check())
        <meta name="robots" content="noindex, nofollow">
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
    @stack('head_extras')
    @stack('styles')
    @stack('structured_data')
</head>
<body class="min-h-full flex flex-col font-sans antialiased bg-[#0B0904] text-[#FFF6D6] selection:bg-[#D4AF37] selection:text-[#0B0904]">
    <a href="#main-content" class="tl-skip-link pp-skip-link">{{ __('public_pages.skip_to_content') }}</a>

    <!-- Header -->
    <header role="banner" class="sticky top-0 z-40 bg-[#141007]/90 backdrop-blur-xl border-b border-[#D4AF37]/20 shadow-lg shadow-black/40">
        <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16 gap-2 min-w-0">
                <!-- Brand Logo -->
                <div class="flex items-center min-w-0 shrink-0">
                    <a href="{{ auth()->check() ? route('player.dashboard') : route('home') }}" class="flex items-center space-x-2.5 group focus:outline-none">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-[#D4AF37] via-[#F5E6B8] to-emerald-400 flex items-center justify-center font-black text-lg text-[#0B0904] shadow-md shrink-0">
                            TL
                        </div>
                        <div class="hidden sm:block min-w-0">
                            <span class="text-base lg:text-lg font-black tracking-tight bg-gradient-to-r from-[#FFF6D6] via-[#F5E6B8] to-[#D4AF37] bg-clip-text text-transparent">
                                {{ strtoupper(config('app.name', 'THAI LOTTERY')) }}
                            </span>
                            <span class="block text-[10px] text-[#F5E6B8]/75 uppercase tracking-widest font-bold">{{ __('frontend_ui.platform_tagline') }}</span>
                        </div>
                    </a>
                </div>

                <!-- Desktop Navigation -->
                <x-navigation.main-nav />

                <!-- Action Controls (Locale, Wallet, Auth, Mobile Menu Trigger) -->
                <div class="flex items-center gap-1.5 sm:gap-3 shrink-0">
                    <!-- Language Selector (Desktop) -->
                    <div class="hidden md:flex items-center space-x-0.5 border border-[#D4AF37]/25 rounded-xl p-0.5 text-xs font-bold bg-[#0B0904]/80 shadow-inner" role="group" aria-label="{{ __('player.language_label') }}">
                        <a href="{{ route('locale.switch', 'en') }}" hreflang="en" class="px-2.5 py-1 rounded-lg transition {{ app()->getLocale() === 'en' ? 'bg-[#D4AF37] text-[#0B0904] shadow' : 'text-slate-300 hover:text-[#FFF6D6]' }}" @if(app()->getLocale() === 'en') aria-current="true" @endif>EN</a>
                        <a href="{{ route('locale.switch', 'th') }}" hreflang="th" class="px-2.5 py-1 rounded-lg transition {{ app()->getLocale() === 'th' ? 'bg-[#D4AF37] text-[#0B0904] shadow' : 'text-slate-300 hover:text-[#FFF6D6]' }}" @if(app()->getLocale() === 'th') aria-current="true" @endif>TH</a>
                        <a href="{{ route('locale.switch', 'bn') }}" lang="bn" hreflang="bn" class="px-2.5 py-1 rounded-lg transition {{ app()->getLocale() === 'bn' ? 'bg-[#D4AF37] text-[#0B0904] shadow' : 'text-slate-300 hover:text-[#FFF6D6]' }}" aria-label="বাংলা" @if(app()->getLocale() === 'bn') aria-current="true" @endif>বাংলা</a>
                    </div>

                    @auth
                        <!-- Authoritative Server Wallet Indicator -->
                        <div id="live-wallet-pill" class="flex items-center space-x-1.5 bg-[#0B0904]/90 border border-[#D4AF37]/30 rounded-full px-2.5 sm:px-3.5 py-1.5 text-xs shadow-inner max-w-[11rem] sm:max-w-none truncate">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 shrink-0" aria-hidden="true"></span>
                            <span class="hidden sm:inline text-slate-300 font-medium">{{ __('frontend_ui.balance') }}</span>
                            <span id="player-balance-display" class="font-mono font-bold text-[#F5E6B8] truncate" data-tabular-nums>
                                {{ \App\Services\Finance\Money::of((string) (Auth::user()->wallet?->balance ?? '0'), \App\Enums\Currency::THB)->format() }}
                            </span>
                        </div>

                        <a href="{{ route('player.deposit') }}" class="hidden sm:inline-flex items-center min-h-[44px] px-3.5 py-2 bg-[#D4AF37] bg-gradient-to-r from-[#F5E6B8] to-[#D4AF37] hover:brightness-105 text-[#0B0904] font-black text-xs rounded-xl shadow-md transition">
                            + {{ __('player.nav_deposit') }}
                        </a>

                        <!-- User Profile & Logout -->
                        <div class="relative flex items-center space-x-1">
                            <a href="{{ route('player.profile') }}" class="flex items-center justify-center min-h-[44px] min-w-[44px] p-1.5 rounded-xl hover:bg-white/5 transition" aria-label="{{ __('player.nav_profile') }}" title="{{ __('player.nav_profile') }}">
                                <div class="w-8 h-8 rounded-full bg-[#141007] flex items-center justify-center font-black text-sm text-[#FFF6D6] border border-[#D4AF37]/45">
                                    {{ strtoupper(substr(Auth::user()->name ?? 'P', 0, 1)) }}
                                </div>
                            </a>

                            <form method="POST" action="{{ route('logout') }}" class="inline">
                                @csrf
                                <button type="submit" class="inline-flex items-center justify-center min-h-[44px] min-w-[44px] p-2 text-[#D4CAB3] hover:text-rose-300 rounded-xl transition" aria-label="{{ __('player.logout') }}" title="{{ __('player.logout') }}">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                                </button>
                            </form>
                        </div>
                    @else
                        <div class="hidden sm:flex items-center space-x-2">
                            <a href="{{ route('login') }}" class="inline-flex items-center min-h-[44px] px-3.5 py-2 text-sm font-bold text-[#F5E6B8] hover:text-white rounded-xl transition">
                                {{ __('player.sign_in') }}
                            </a>
                            <a href="{{ route('register') }}" class="inline-flex items-center min-h-[44px] px-4 py-2 bg-[#D4AF37] bg-gradient-to-r from-[#F5E6B8] to-[#D4AF37] hover:brightness-105 text-[#0B0904] text-sm font-extrabold rounded-xl shadow-md transition">
                                {{ __('player.register') }}
                            </a>
                        </div>
                    @endauth

                    <!-- Mobile Menu Hamburger Button -->
                    <button type="button"
                            id="btn-open-mobile-menu"
                            class="xl:hidden inline-flex items-center justify-center min-h-[44px] min-w-[44px] p-2 text-[#F5E6B8] hover:text-white rounded-xl border border-[#D4AF37]/25 bg-[#0B0904]/60"
                            aria-label="{{ __('frontend_ui.open_nav') }}"
                            aria-controls="mobile-menu-drawer"
                            aria-expanded="false">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                </div>
            </div>
        </div>

        @guest
            <!-- Public Result-Lane Quick Bar (Desktop & Tablet) -->
            <div class="hidden md:block border-t border-[#D4AF37]/15 bg-[#0B0904]/75">
                <nav class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-1.5 flex flex-wrap items-center gap-x-5 gap-y-1 text-xs font-semibold text-[#F5E6B8]/85" aria-label="{{ __('public_pages.footer_lanes_heading') }}">
                    <a href="{{ route('national-lottery.index') }}" class="hover:text-[#FFF6D6] transition py-1">{{ __('public_pages.footer_lane_l6') }}</a>
                    <a href="{{ route('weekly-lottery.index') }}" class="hover:text-[#FFF6D6] transition py-1">{{ __('public_pages.footer_lane_weekly') }}</a>
                    <a href="{{ route('bingo-lottery.index') }}" class="hover:text-[#FFF6D6] transition py-1">{{ __('public_pages.footer_lane_bingo') }}</a>
                    <a href="{{ route('pcso-lottery.index') }}" class="hover:text-[#FFF6D6] transition py-1">{{ __('public_pages.footer_lane_pcso') }}</a>
                    <a href="{{ route('contact') }}" class="ml-auto hover:text-[#FFF6D6] transition py-1">{{ __('public_pages.footer_contact') }}</a>
                </nav>
            </div>
        @endguest
    </header>

    <!-- Mobile Navigation Drawer -->
    <x-navigation.mobile-nav />

    <!-- Global Notifications / Flash Feedback -->
    @if (session('status'))
        <div role="status" aria-live="polite" class="bg-emerald-950/85 border-b border-emerald-500/40 text-emerald-100 px-4 py-3 text-sm text-center font-medium backdrop-blur">
            {{ session('status') }}
        </div>
    @endif
    @if (session('error'))
        <div role="alert" aria-live="assertive" class="bg-rose-950/85 border-b border-rose-500/40 text-rose-100 px-4 py-3 text-sm text-center font-medium backdrop-blur">
            {{ session('error') }}
        </div>
    @endif

    <!-- Main Content Landmark -->
    <main id="main-content" class="flex-1 max-w-7xl w-full min-w-0 mx-auto px-4 sm:px-6 lg:px-8 py-8" tabindex="-1">
        @yield('content')
    </main>

    <!-- Global Platform Footer -->
    <x-public-page.footer />

    <!-- Page Scripts -->
    @stack('scripts')
</body>
</html>
