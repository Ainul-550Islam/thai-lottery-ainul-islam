@php
    $htmlLang = 'en';
    try {
        if (function_exists('app') && app()->bound('translator')) {
            $htmlLang = str_replace('_', '-', app()->getLocale());
        }
    } catch (\Throwable) {
        $htmlLang = 'en';
    }
    $safeTrans = static function (string $key, string $fallback): string {
        try {
            if (function_exists('trans') && function_exists('app') && app()->bound('translator')) {
                $val = trans($key);
                if (is_string($val) && $val !== $key) {
                    return $val;
                }
            }
        } catch (\Throwable) {
            // Fall through when error occurs before translator boots.
        }
        return $fallback;
    };
@endphp
<!DOCTYPE html>
<html lang="{{ $htmlLang }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0B0904">
    <title>@yield('title', 'Service Notice') — Thai Lottery</title>
    <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">
    <style @cspNonce>
        :root {
            color-scheme: dark;
            --bg-primary: #0B0904;
            --bg-elevated: #141007;
            --gold: #D4AF37;
            --gold-soft: #F5E6B8;
            --cream: #FFF6D6;
            --muted: #BFB28C;
            --border: rgba(212, 175, 55, 0.30);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: radial-gradient(circle at 50% 0%, rgba(212, 175, 55, 0.14), transparent 36rem), var(--bg-primary);
            color: var(--cream);
            font-family: Inter, "Noto Sans Thai", "Noto Sans Bengali", system-ui, -apple-system, sans-serif;
            line-height: 1.6;
        }
        .skip-link {
            position: absolute;
            left: -9999px;
            top: 0;
            background: var(--gold);
            color: #0B0904;
            padding: 0.65rem 1rem;
            border-radius: 0.5rem;
            font-weight: 800;
            text-decoration: none;
            z-index: 100;
        }
        .skip-link:focus { left: 1rem; top: 1rem; }
        header {
            border-bottom: 1px solid var(--border);
            background: rgba(11, 9, 4, 0.9);
            padding: 1rem 1.25rem;
        }
        .header-inner {
            max-width: 64rem;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        .brand {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--cream);
            text-decoration: none;
            font-weight: 800;
        }
        .brand-mark {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.75rem;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, var(--gold-soft), var(--gold));
            color: #0B0904;
            font-weight: 900;
            font-size: 0.9rem;
        }
        .brand small {
            display: block;
            color: var(--gold);
            font-size: 0.68rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 1rem;
        }
        .card {
            width: 100%;
            max-width: 38rem;
            background: linear-gradient(160deg, rgba(28, 22, 10, 0.95), rgba(20, 16, 7, 0.98));
            border: 1px solid var(--border);
            border-radius: 1.5rem;
            padding: 2rem 1.5rem;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.65);
        }
        @media (min-width: 640px) {
            .card { padding: 2.5rem; }
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.35rem 0.85rem;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: rgba(212, 175, 55, 0.1);
            color: var(--gold-soft);
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 0.8rem;
            font-weight: 800;
            letter-spacing: 0.08em;
        }
        h1 {
            margin: 1rem 0 0.75rem;
            font-size: clamp(1.65rem, 4vw, 2.25rem);
            line-height: 1.2;
            color: var(--cream);
        }
        p {
            margin: 0 0 1.25rem;
            color: #DDD2B5;
            font-size: 0.98rem;
        }
        .safe-note {
            margin: 1.25rem 0 1.75rem;
            padding: 0.9rem 1rem;
            border-left: 3px solid var(--gold);
            background: rgba(11, 9, 4, 0.65);
            border-radius: 0.5rem;
            color: var(--muted);
            font-size: 0.84rem;
        }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .btn {
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.65rem 1.25rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-weight: 800;
            text-decoration: none;
            border: 1px solid var(--border);
            color: var(--cream);
            background: rgba(20, 16, 7, 0.9);
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--gold-soft), var(--gold));
            color: #0B0904;
            border-color: rgba(255, 246, 214, 0.65);
        }
        :focus-visible {
            outline: 2px solid var(--gold);
            outline-offset: 2px;
        }
    </style>
</head>
<body>
    <a class="skip-link" href="#main-content">{{ $safeTrans('errors.skip_to_content', 'Skip to main content') }}</a>
    <header role="banner">
        <div class="header-inner">
            <a class="brand" href="/">
                <span class="brand-mark" aria-hidden="true">TL</span>
                <span>Thai Lottery <small>{{ $safeTrans('errors.brand_subtitle', 'System Status') }}</small></span>
            </a>
        </div>
    </header>
    <main id="main-content" tabindex="-1">
        <section class="card" aria-labelledby="error-heading">
            <span class="status-pill">HTTP @yield('code', '500')</span>
            <h1 id="error-heading">@yield('heading', 'Service Notice')</h1>
            <p>@yield('message', 'The request could not be completed at this time.')</p>
            <p class="safe-note" role="status">{{ $safeTrans('errors.safe_note', 'No account balance, ticket, or payment action was completed by this error response. Check your account history before retrying any financial action.') }}</p>
            <div class="actions">
                @yield('actions')
                <a class="btn btn-primary" href="/">{{ $safeTrans('errors.back_home', 'Return to Home') }}</a>
                <a class="btn" href="/contact">{{ $safeTrans('errors.contact_support', 'Contact Support') }}</a>
            </div>
        </section>
    </main>
</body>
</html>
