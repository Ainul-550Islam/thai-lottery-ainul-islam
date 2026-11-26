{{-- Include on login.blade + member-register.blade:  @include('partials.google-button') --}}
@if (app(\App\Services\Auth\Google\GoogleOidcClient::class)->enabled())
    <div class="space-y-4" id="google-signin-block">
        <a id="google-signin" href="{{ route('social.redirect', ['provider' => 'google']) }}"
           class="group relative flex w-full items-center justify-center gap-3 rounded-xl border border-amber-400/40 bg-white px-4 py-3.5 text-sm font-bold text-slate-900 shadow-lg transition hover:shadow-amber-400/20 focus:outline-none focus:ring-2 focus:ring-amber-400 focus:ring-offset-2 focus:ring-offset-slate-900">
            <svg class="h-5 w-5" viewBox="0 0 48 48" aria-hidden="true">
                <path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.5l6.8-6.8C35.8 2.4 30.3 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.9 6.1C12.4 13.6 17.7 9.5 24 9.5z"/>
                <path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.5 5.8c4.4-4.1 7.1-10.1 7.1-17.5z"/>
                <path fill="#FBBC05" d="M10.5 28.7a14.5 14.5 0 0 1 0-9.4l-7.9-6.1a24 24 0 0 0 0 21.6l7.9-6.1z"/>
                <path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.5-5.8c-2.1 1.4-4.9 2.3-8.4 2.3-6.3 0-11.6-4.1-13.5-9.8l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/>
            </svg>
            <span>{{ __('public_pages.google_signin_button') }}</span>
        </a>
        <p class="text-center text-xs text-slate-400">{{ __('public_pages.google_signin_hint') }}</p>

        {{-- Shown on click while the browser navigates to Google. Honest copy: nothing is fetched yet. --}}
        <div id="google-signin-overlay" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/90 backdrop-blur" role="status" aria-live="polite">
            <div class="w-72 space-y-4 rounded-2xl border border-slate-700 bg-slate-900 p-6 text-center shadow-2xl">
                <div class="mx-auto h-1.5 w-full overflow-hidden rounded-full bg-slate-800">
                    <div class="h-full w-1/3 animate-pulse rounded-full bg-gradient-to-r from-amber-400 to-emerald-400"></div>
                </div>
                <p class="text-sm font-semibold text-white">{{ __('public_pages.google_signin_redirecting') }}</p>
                <p class="text-xs text-slate-400">{{ __('public_pages.google_signin_redirecting_hint') }}</p>
            </div>
        </div>
    </div>
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        (function () {
            var link = document.getElementById('google-signin');
            var overlay = document.getElementById('google-signin-overlay');
            if (!link || !overlay) { return; }
            link.addEventListener('click', function () {
                overlay.classList.remove('hidden'); overlay.classList.add('flex');
            });
            // Back-button (bfcache) must not leave the overlay stuck on screen.
            window.addEventListener('pageshow', function (e) {
                if (e.persisted) { overlay.classList.add('hidden'); overlay.classList.remove('flex'); }
            });
        })();
    </script>
@endif
