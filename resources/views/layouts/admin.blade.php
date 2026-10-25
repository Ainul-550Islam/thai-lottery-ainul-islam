<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
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

    <title>@yield('title', 'Executive Analytics Dashboard') — LOTTOFIN ADMIN</title>

    <!-- Compiled Tailwind utilities and the admin design system -->
    @vite(['resources/css/tailwind-utility.css', 'resources/css/admin-lottofin.css'])

    @stack('styles')
</head>
<body class="lf-admin-body">
@php
    /*
     * The console chrome is derived, never restated.
     *
     * AdminConsoleNav holds every sidebar item as data - label, route, panel, group
     * and icon - and AdminConsoleNav::visibleFor() asks AdminAccess::mayOpenPanel(),
     * the very predicate LottoFinExecutiveDashboardController::authorizePanel() uses,
     * whether each item may be rendered. The navigation and the authorizer therefore
     * cannot disagree, because they are no longer two implementations of one rule.
     *
     * $panel is nullable on purpose: resources/views/admin/release-operations.blade.php
     * extends this layout from a different controller that passes no panel, and on that
     * screen nothing in the sidebar is current. That is the truth, so the panel is not
     * defaulted here - a default would put the hardcoded-active bug back.
     */
    $navItems = \App\Support\Admin\AdminConsoleNav::visibleFor(auth()->user(), $panel ?? null);
    $navGroups = \App\Support\Admin\AdminConsoleNav::groupsPresent($navItems);

    /*
     * The header names the operator who is actually logged in. The initials are
     * derived from that name rather than stored, so a different operator necessarily
     * sees different initials. The role is read from the permission tables rather
     * than written as a literal, because the console is role-aware by design.
     */
    $operator = auth()->user();
    $operatorName = trim((string) ($operator?->name ?? ''));
    $operatorName = $operatorName !== '' ? $operatorName : 'Operator';
    $nameParts = preg_split('/\s+/', $operatorName) ?: [];
    $nameParts = array_values(array_filter($nameParts, static fn (string $part): bool => $part !== ''));
    $operatorInitials = strtoupper(
        mb_substr((string) ($nameParts[0] ?? ''), 0, 1)
            .(count($nameParts) > 1 ? mb_substr((string) $nameParts[count($nameParts) - 1], 0, 1) : '')
    );
    $operatorRole = (string) ($operator?->getRoleNames()->first() ?? '');
    $operatorRole = $operatorRole !== '' ? $operatorRole : 'Operator';
@endphp
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

            <!--
                Every item below is offered only if this operator may actually open it,
                and the current panel - and only the current panel - is marked current.
            -->
            <div class="lf-nav-section">
                @foreach ($navGroups as $navGroup)
                    @if (! $loop->first)
                        <span class="lf-nav-header-label">{{ $navGroup }}</span>
                    @endif

                    @foreach ($navItems as $navItem)
                        @continue ($navItem['group'] !== $navGroup)

                        <a href="{{ route($navItem['route']) }}" class="lf-nav-item{{ $navItem['active'] ? ' active' : '' }}"@if ($navItem['active']) aria-current="page"@endif>
                            <div class="lf-nav-item-content">
                                <svg class="lf-nav-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $navItem['icon'] }}"/>
                                </svg>
                                <span>{{ $navItem['label'] }}</span>
                            </div>
                            @if ($navItem['active'])
                                <span class="lf-active-badge">Active</span>
                            @endif
                        </a>
                    @endforeach
                @endforeach
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
                    <!--
                        Operator identity. The name, the initials derived from it and the
                        role are all read from the authenticated operator, so no
                        fabricated identity can appear here.
                    -->
                    <div class="lf-user-profile">
                        <span class="lf-user-avatar" aria-hidden="true">{{ $operatorInitials }}</span>
                        <div class="lf-user-info">
                            <span class="lf-user-role">{{ $operatorRole }}</span>
                            <span class="lf-user-name">{{ $operatorName }}</span>
                        </div>
                    </div>

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

    <!-- Admin Interactive Script -->
    @vite(['resources/js/admin-lottofin.js'])
    @stack('scripts')
</body>
</html>
