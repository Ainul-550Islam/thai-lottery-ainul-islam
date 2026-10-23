<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="@yield('meta_robots', 'noindex, nofollow')">
    <meta name="theme-color" content="#0B0904">

    @php
        $adminBrand = (string) config('app.name', 'Thai Lottery').' '.__('admin.title');
        $rawAdminTitle = trim((string) $__env->yieldContent('title', __('admin.dashboard')));
        $composedAdminTitle = ($rawAdminTitle !== '' && stripos($rawAdminTitle, (string) config('app.name', 'Thai Lottery')) !== false)
            ? $rawAdminTitle
            : ($rawAdminTitle !== '' ? $rawAdminTitle.' — '.$adminBrand : $adminBrand);
    @endphp
    <title>{{ $composedAdminTitle }}</title>

    <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32.png">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">

    @vite([
        'resources/css/app.css',
        'resources/css/admin-lottofin.css',
        'resources/js/app.js',
        'resources/js/admin-lottofin.js',
    ])
    @stack('head_extras')
    @stack('styles')
</head>
<body class="lf-admin-body">
    <a href="#admin-main-content" class="tl-skip-link">{{ __('public_pages.skip_to_content') }}</a>

    <div class="lf-admin-shell">
        <!-- Left Sidebar Navigation -->
        <aside class="lf-sidebar" id="adminSidebar" aria-label="{{ __('frontend_ui.admin_nav') }}">
            <div class="lf-brand-header">
                <svg class="lf-brand-logo-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                </svg>
                <span class="lf-brand-title">LOTTOFIN ADMIN</span>
            </div>

            <nav class="lf-nav-section" aria-label="{{ __('frontend_ui.operations') }}">
                <a href="{{ route('admin.dashboard') }}" class="lf-nav-item {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}" @if(request()->routeIs('admin.dashboard')) aria-current="page" @endif>
                    <div class="lf-nav-item-content">
                        <svg class="lf-nav-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        <span>{{ __('admin.dashboard') }}</span>
                    </div>
                    @if(request()->routeIs('admin.dashboard'))
                        <span class="lf-active-badge">{{ __('frontend_ui.active') }}</span>
                    @endif
                </a>

                <span class="lf-nav-header-label">{{ __('frontend_ui.financial_risk') }}</span>

                <a href="{{ route('admin.draws.index') }}" class="lf-nav-item {{ request()->routeIs('admin.draws*') ? 'active' : '' }}">
                    <div class="lf-nav-item-content">
                        <svg class="lf-nav-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>{{ __('admin.draws') }}</span>
                    </div>
                </a>

                <a href="{{ route('admin.risk.index') }}" class="lf-nav-item {{ request()->routeIs('admin.risk*') ? 'active' : '' }}">
                    <div class="lf-nav-item-content">
                        <svg class="lf-nav-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14v6m-3-3h6M6 10h2a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2zm10 0h2a2 2 0 002-2V6a2 2 0 00-2-2h-2a2 2 0 00-2 2v2a2 2 0 002 2zM6 20h2a2 2 0 002-2v-2a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2z"/>
                        </svg>
                        <span>{{ __('admin.risk') }}</span>
                    </div>
                </a>

                <a href="{{ route('admin.bets.index') }}" class="lf-nav-item {{ request()->routeIs('admin.bets*') ? 'active' : '' }}">
                    <div class="lf-nav-item-content">
                        <svg class="lf-nav-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <span>{{ __('admin.bets') }}</span>
                    </div>
                </a>

                <a href="{{ route('admin.wallets.index') }}" class="lf-nav-item {{ request()->routeIs('admin.wallets*') ? 'active' : '' }}">
                    <div class="lf-nav-item-content">
                        <svg class="lf-nav-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                        </svg>
                        <span>{{ __('admin.wallets') }}</span>
                    </div>
                </a>

                <a href="{{ route('admin.ledger.index') }}" class="lf-nav-item {{ request()->routeIs('admin.ledger*') ? 'active' : '' }}">
                    <div class="lf-nav-item-content">
                        <svg class="lf-nav-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>
                        </svg>
                        <span>{{ __('admin.ledger') }}</span>
                    </div>
                </a>

                <a href="{{ route('admin.reconciliation.index') }}" class="lf-nav-item {{ request()->routeIs('admin.reconciliation*') ? 'active' : '' }}">
                    <div class="lf-nav-item-content">
                        <svg class="lf-nav-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                        </svg>
                        <span>{{ __('admin.reconciliation') }}</span>
                    </div>
                </a>

                <a href="{{ route('admin.audits.index') }}" class="lf-nav-item {{ request()->routeIs('admin.audits*') ? 'active' : '' }}">
                    <div class="lf-nav-item-content">
                        <svg class="lf-nav-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5H7a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                        </svg>
                        <span>{{ __('admin.audits') }}</span>
                    </div>
                </a>
            </nav>
        </aside>

        <!-- Main Workspace -->
        <div class="lf-main-content">
            <header class="lf-top-header" role="banner">
                <div class="lf-header-left">
                    <button type="button" class="lf-menu-toggle-btn min-h-[44px] min-w-[44px]" id="sidebarToggle" aria-label="{{ __('frontend_ui.toggle_nav') }}" aria-controls="adminSidebar" aria-expanded="true">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                </div>

                <div class="lf-header-right">
                    <div class="lf-user-profile">
                        <span class="lf-user-avatar" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 21a8 8 0 10-16 0m8-10a4 4 0 100-8 4 4 0 000 8z"/>
                            </svg>
                        </span>
                        <div class="lf-user-info">
                            <span class="lf-user-role">{{ __('frontend_ui.administrator') }}</span>
                            <span class="lf-user-name">{{ auth()->user()?->name ?? __('frontend_ui.administrator') }}</span>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('logout') }}" class="inline">
                        @csrf
                        <button type="submit" class="lf-logout-btn min-h-[44px] min-w-[44px]" aria-label="{{ __('player.logout') }}" title="{{ __('player.logout') }}">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                            </svg>
                        </button>
                    </form>
                </div>
            </header>

            <main id="admin-main-content" class="lf-page-body" tabindex="-1">
                @yield('content')
            </main>
        </div>
    </div>

    @stack('scripts')
</body>
</html>
