<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Executive Analytics Dashboard') — LOTTOFIN ADMIN</title>

    {{--
        Design system roots plus the Tailwind utility layer, built locally. The
        <script src="https://cdn.tailwindcss.com"> that used to sit here is a
        development tool that compiles CSS in the browser at runtime; it was being
        served to every visitor of the executive dashboard and release-operations
        screens, and it was the only reason script-src could not be 'self'.
        resources/css/admin-tailwind.css imports admin-lottofin.css and adds the
        directives in the order the CDN's injected <style> already had.
    --}}
    @vite(['resources/css/admin-tailwind.css'])

    @stack('styles')
</head>
<body class="lf-admin-body">
    {{--
        Everything the chrome shows about WHERE you are and WHO you are is derived here
        rather than written into the markup.

        $panel comes from LottoFinExecutiveDashboardController, which resolves it from the
        route name and passes it to the view; it is the same value the controller used to
        pick the panel's data and to authorize it. It is nullable because
        admin/release-operations extends this layout and is rendered by a different
        controller that passes no panel - on that screen nothing here is current, which is
        true, and is why the default must not be 'dashboard'.

        AdminConsoleNav::visibleFor() asks AdminAccess::mayOpenPanel(), the exact
        predicate the controller applies before serving a panel, so a link rendered here
        cannot lead to a 403.
    --}}
    @php
        $operator = \App\Support\Admin\AdminAccess::operator();
        $activePanel = $panel ?? null;
        $navItems = \App\Support\Admin\AdminConsoleNav::visibleFor($operator, $activePanel);

        $operatorName = $operator?->name ?? '';
        $operatorRoles = $operator?->getRoleNames()?->all() ?? [];
        $operatorRole = implode(', ', $operatorRoles);

        // Initials for the avatar, taken from the operator's own name. The previous
        // markup showed a stock photograph of a person and the name "Somchai" to every
        // operator who logged in, which in an audit-sensitive console is worse than no
        // identity at all: it asserts who is acting when it does not know.
        $initials = collect(preg_split('/\s+/', trim($operatorName)) ?: [])
            ->filter(fn ($part) => $part !== '')
            ->take(2)
            ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
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

            <!-- Primary Nav Items -->
            <div class="lf-nav-section">
                {{--
                    Rendered from AdminConsoleNav so that the items, the group headers,
                    the active state and the permission gate all come from one place.
                    `aria-current="page"` marks the current item for assistive tech, which
                    the hardcoded `active` class alone did not do.
                --}}
                @foreach($navItems as $navItem)
                    @if(! $loop->first && $navItem['group'] !== $navItems[$loop->index - 1]['group'])
                        <span class="lf-nav-header-label">{{ $navItem['group'] }}</span>
                    @endif

                    <a href="{{ route($navItem['route']) }}" class="lf-nav-item{{ $navItem['active'] ? ' active' : '' }}"@if($navItem['active']) aria-current="page"@endif>
                        <div class="lf-nav-item-content">
                            <svg class="lf-nav-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $navItem['icon'] }}"/>
                            </svg>
                            <span>{{ $navItem['label'] }}</span>
                        </div>
                        @if($navItem['active'])
                            <span class="lf-active-badge">Active</span>
                        @endif
                    </a>
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
                    <!-- User Profile Dropdown -->
                    {{--
                        The operator's own identity. There is no dropdown behind this: the
                        chevron and `cursor: pointer` that used to sit here promised one
                        that no markup and no script implements, so both are gone.
                    --}}
                    <div class="lf-user-profile">
                        <span class="lf-user-avatar" aria-hidden="true">{{ $initials }}</span>
                        <div class="lf-user-info">
                            @if($operatorRole !== '')
                                <span class="lf-user-role">{{ $operatorRole }}</span>
                            @endif
                            <span class="lf-user-name">{{ $operatorName }}</span>
                        </div>
                    </div>

                    {{--
                        The notification bell is gone rather than wired up. Its badge was a
                        literal `1` that never changed: this application has no operator
                        notification feed. The Notification, NotificationTemplate and
                        NotificationDeliveryAttempt models are about telling WINNERS their
                        results, not about an inbox for staff, so there was nothing for the
                        bell to open. A control that always reports exactly one unread item
                        teaches the operator to ignore it.
                    --}}

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

    {{--
        The floating help button is gone rather than wired up. It was a <button type="button">
        with no handler: resources/js/admin-lottofin.js binds only #sidebarToggle,
        #reconciliationSearch and #mainActionsBtn, and none of those is this. The nearest
        real destination is route('support.index'), but that is the authenticated user's own
        support-ticket portal - a player-facing surface - and pointing an operator's "help"
        control at it would be a guess about intent rather than a fix.
    --}}

    <!-- Admin Interactive Script -->
    @vite(['resources/js/admin-lottofin.js'])
    @stack('scripts')
</body>
</html>
