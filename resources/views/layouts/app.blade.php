{{--
    The site's one layout: the gold/black shell (sidebar, header, footer). Every page extends it,
    public pages included.

    The layout, not the page, picks the chrome:
      signed-in viewer  player sidebar, user chip in the header, live wallet pill
      guest             public sidebar, Sign In / Register in the header

    Two page modes, declared by the page itself:
      page       (default) any page. Its @section('content') sits in .gd-page and the site's core
                 bundle (Tailwind + app.js) is loaded for it; css/gold-theme.css paints it gold/black.
      dashboard  @section('gd_mode', 'dashboard'). Its content is the fixed gold widget grid;
                 gold-dashboard.js fills it from #gd-state or @section('gd_endpoint'). Only the gold
                 bundle is loaded there.

    Optional: a view or composer may pass $gdUser (name, initials, tier, avatar, notifications) for the
    header chip; the dashboard already carries that shape in $state['user'].
--}}
@php
    $gdDashboard = trim($__env->yieldContent('gd_mode')) === 'dashboard';
    $gdEndpoint = trim(html_entity_decode($__env->yieldContent('gd_endpoint'), ENT_QUOTES));
    $gdGuest = ! auth()->check();

    // ONE footer per page, and this layout is its only owner: a page never renders a footer of its
    // own. The shared public footer (lanes, information, legal links) belongs to the public pages;
    // routes behind the `auth` middleware are the signed-in member area and, like the dashboard,
    // keep the gold footer instead. The decision comes from the matched route, never from the
    // viewer, so a publicly cached page renders the same footer for every visitor.
    $inMemberArea = collect(request()->route()?->gatherMiddleware() ?? [])
        ->contains(static fn ($middleware): bool => is_string($middleware)
            && ($middleware === 'auth'
                || str_starts_with($middleware, 'auth:')
                || str_starts_with($middleware, \Illuminate\Auth\Middleware\Authenticate::class)));
    $gdPublicFooter = ! $gdDashboard && ! $inMemberArea;

    // Lookups that never throw: an unknown route or lang key yields the fallback instead of a 500.
    $gdRoute = static function (array|string $names, string $fallback = '#'): string {
        foreach ((array) $names as $name) {
            if (\Illuminate\Support\Facades\Route::has($name)) {
                return route($name);
            }
        }

        return $fallback;
    };
    $gdLabel = static fn (string $key, string $fallback): string => \Illuminate\Support\Facades\Lang::has($key)
        ? (string) __($key)
        : $fallback;

    $gdUser = $gdUser ?? data_get($state ?? null, 'user');
    $gdName = (string) (data_get($gdUser, 'name') ?? auth()->user()?->name ?? '');
    $gdTier = data_get($gdUser, 'tier');
    $gdAvatar = data_get($gdUser, 'avatar');
    $gdInitials = (string) (data_get($gdUser, 'initials') ?: mb_strtoupper(mb_substr($gdName !== '' ? $gdName : '?', 0, 1)));
    $gdUnread = (int) data_get($gdUser, 'notifications', 0);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head')

    {{-- Vite-built assets. The dashboard keeps its own self-contained gold bundle; every other page also needs the core bundle. --}}
    @unless ($gdDashboard)
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
    @endunless
    @vite(['resources/css/gold-dashboard.css', 'resources/js/gold-dashboard.js'])
    @stack('styles')
</head>
<body class="gd-body">
@include('layouts.partials.gold-icons')

<div class="gd{{ $gdDashboard ? '' : ' gd--page' }}{{ $gdGuest ? ' gd--guest' : '' }}{{ $gdPublicFooter ? ' gd--nofoot' : '' }}" id="gd-root" data-state="ready" data-img="{{ asset('images/gd') }}"@if ($gdEndpoint !== '') data-endpoint="{{ $gdEndpoint }}"@endif>
    @if ($gdDashboard)
        <script type="application/json" id="gd-state">@json($state ?? null)</script>
    @endif
    @include('layouts.partials.gold-sidebar')
    @include('layouts.partials.gold-header')

    @if ($gdDashboard)
        <main class="gd-main">
            @yield('content')
        </main>
    @else
        <main class="gd-page" id="main-content">
            <div class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                @if (session('status'))
                    <div class="gd-flash gd-flash--ok">{{ session('status') }}</div>
                @endif
                @if (session('error'))
                    <div class="gd-flash gd-flash--err">{{ session('error') }}</div>
                @endif
                @yield('content')
            </div>
            @if ($gdPublicFooter)
                <x-public-page.footer />
            @endif
        </main>
    @endif

    @unless ($gdPublicFooter)
        @include('layouts.partials.gold-footer')
    @endunless
</div>
@stack('scripts')
</body>
</html>
