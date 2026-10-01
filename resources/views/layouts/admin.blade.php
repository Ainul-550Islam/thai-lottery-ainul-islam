<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Executive Analytics Dashboard') — LOTTOFIN ADMIN</title>

    <!-- Tailwind & Design System Roots -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="{{ asset('css/admin-lottofin.css') }}">

    @stack('styles')
</head>
<body class="lf-admin-body">
    <div class="lf-admin-shell">
        <!-- Left Sidebar Navigation -->
        <aside class="lf-sidebar" id="adminSidebar">
            <!-- Brand Logo Header -->
            <div class="lf-brand-header">
                <svg class="lf-brand-logo-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                </svg>
                <span class="lf-brand-title">LOTTOFIN ADMIN</span>
            </div>

            <!-- Primary Nav Items -->
            <div class="lf-nav-section">
                <!-- Dashboard Active Item -->
                <a href="{{ route('admin.dashboard') }}" class="lf-nav-item active">
                    <div class="lf-nav-item-content">
                        <svg class="lf-nav-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        <span>Dashboard</span>
                    </div>
                    <span class="lf-active-badge">Active</span>
                </a>

                <!-- Financial Section Header -->
                <span class="lf-nav-header-label">Financial</span>

                <!-- Draw Lifecycle -->
                <a href="{{ route('admin.draws.index') }}" class="lf-nav-item">
                    <div class="lf-nav-item-content">
                        <svg class="lf-nav-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Draw Lifecycle</span>
                    </div>
                </a>

                <!-- Number Limits & Risk -->
                <a href="{{ route('admin.risk.index') }}" class="lf-nav-item">
                    <div class="lf-nav-item-content">
                        <svg class="lf-nav-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14v6m-3-3h6M6 10h2a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2zm10 0h2a2 2 0 002-2V6a2 2 0 00-2-2h-2a2 2 0 00-2 2v2a2 2 0 002 2zM6 20h2a2 2 0 002-2v-2a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2z"/>
                        </svg>
                        <span>Number Limits &amp; Risk</span>
                    </div>
                </a>

                <!-- Bets Ledger -->
                <a href="{{ route('admin.bets.index') }}" class="lf-nav-item">
                    <div class="lf-nav-item-content">
                        <svg class="lf-nav-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <span>Bets Ledger</span>
                    </div>
                </a>

                <!-- Wallet Controls -->
                <a href="{{ route('admin.wallets.index') }}" class="lf-nav-item">
                    <div class="lf-nav-item-content">
                        <svg class="lf-nav-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                        </svg>
                        <span>Wallet Controls</span>
                    </div>
                </a>

                <!-- Ledger Accounts -->
                <a href="{{ route('admin.ledger.index') }}" class="lf-nav-item">
                    <div class="lf-nav-item-content">
                        <svg class="lf-nav-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>
                        </svg>
                        <span>Ledger Accounts</span>
                    </div>
                </a>

                <!-- Double-Entry Reconciliation -->
                <a href="{{ route('admin.reconciliation.index') }}" class="lf-nav-item">
                    <div class="lf-nav-item-content">
                        <svg class="lf-nav-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                        </svg>
                        <span>Double-Entry Reconciliation</span>
                    </div>
                </a>

                <!-- Audit Logs -->
                <a href="{{ route('admin.audits.index') }}" class="lf-nav-item">
                    <div class="lf-nav-item-content">
                        <svg class="lf-nav-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                        </svg>
                        <span>Audit Logs</span>
                    </div>
                </a>
            </div>
        </aside>

        <!-- Main Workspace -->
        <main class="lf-main-content">
            <!-- Top Header Navbar -->
            <header class="lf-top-header">
                <div class="lf-header-left">
                    <button type="button" class="lf-menu-toggle-btn" id="sidebarToggle" aria-label="Toggle navigation">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                </div>

                <div class="lf-header-right">
                    <!-- User Profile Dropdown -->
                    <div class="lf-user-profile">
                        <img src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=100&auto=format&fit=crop&q=80" alt="Admin" class="lf-user-avatar">
                        <div class="lf-user-info">
                            <span class="lf-user-role">Admin</span>
                            <span class="lf-user-name">Somchai</span>
                        </div>
                        <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </div>

                    <!-- Notification Bell with Badge -->
                    <button type="button" class="lf-notification-btn" aria-label="Notifications">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                        </svg>
                        <span class="lf-notification-badge">1</span>
                    </button>

                    <!-- Logout / Exit -->
                    <form method="POST" action="{{ route('logout') }}" class="inline">
                        @csrf
                        <button type="submit" class="lf-logout-btn" title="Sign Out">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                            </svg>
                        </button>
                    </form>
                </div>
            </header>

            <!-- Page Body Slot -->
            <div class="lf-page-body">
                @yield('content')
            </div>
        </main>
    </div>

    <!-- Floating Help Button -->
    <button type="button" class="lf-floating-help" title="Platform Help Desk">
        ?
    </button>

    <!-- Admin Interactive Script -->
    <script src="{{ asset('js/admin-lottofin.js') }}"></script>
    @stack('scripts')
</body>
</html>
