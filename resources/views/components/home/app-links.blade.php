{{-- App links: only configured store/PWA URLs — never fake store buttons. --}}
<section class="home-card" aria-labelledby="app-title" id="app-links">
    <div class="home-card__head">
        <h2 id="app-title">{{ $text['app_title'] ?? 'Get the app' }}</h2>
        <span class="home-badge home-badge--{{ \Illuminate\Support\Str::slug($appLinks['status'] ?? 'NOT_CONFIGURED', '-') }}">
            {{ $appLinks['status'] ?? 'NOT_CONFIGURED' }}
        </span>
    </div>

    @if (($appLinks['status'] ?? 'NOT_CONFIGURED') === 'NOT_CONFIGURED')
        <p class="home-empty" role="status">{{ $appLinks['message'] ?? 'App download links are not configured.' }}</p>
    @else
        <div class="home-app-links">
            @if (!empty($appLinks['android']))
                <a class="home-btn home-btn--secondary" href="{{ $appLinks['android'] }}" rel="noopener noreferrer">Android app</a>
            @endif
            @if (!empty($appLinks['ios']))
                <a class="home-btn home-btn--secondary" href="{{ $appLinks['ios'] }}" rel="noopener noreferrer">iOS app</a>
            @endif
            @if (!empty($appLinks['pwa']))
                <a class="home-btn home-btn--ghost" href="{{ $appLinks['pwa'] }}" rel="noopener noreferrer">Install PWA</a>
            @endif
        </div>
    @endif
</section>
