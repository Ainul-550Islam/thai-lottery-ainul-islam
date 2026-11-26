{{--
    <head> metadata shared by every shell: charset/viewport/CSRF, the real-time bridge
    identity + client block, brand name, theme colour, title, manifest/icon and the SEO
    sections (meta_description, meta_canonical, meta_robots/robots, meta_og_*).

    Included by layouts/app.blade.php, the site's one layout, which adds the assets after it.
    A new meta tag or SEO section is added here, once.
--}}
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
