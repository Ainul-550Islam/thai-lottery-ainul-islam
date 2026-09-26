{{-- Live / replay: honest state only. NEVER renders a fake video. --}}
<section class="home-card" aria-labelledby="live-draw-title" id="live-draw"
         data-home-live-draw
         data-live-status="{{ $live['live_status'] ?? 'not_configured' }}"
         data-replay-status="{{ $live['replay_status'] ?? 'not_configured' }}">
    <div class="home-card__head">
        <h2 id="live-draw-title">{{ $text['live_title'] ?? 'Live draw' }}</h2>
        <span class="home-badge home-badge--{{ \Illuminate\Support\Str::slug($live['status'] ?? 'NOT_CONFIGURED', '-') }}">
            {{ $live['status'] ?? 'NOT_CONFIGURED' }}
        </span>
    </div>

    @if (($live['live_status'] ?? 'not_configured') === 'configured' && !empty($live['embed_url']))
        <div class="home-live-frame" data-live-frame-host>
            <p class="home-muted">Authorized live stream configured via {{ $live['provider'] ?? 'provider' }}.</p>
            {{-- Embed URL is only rendered when the service reports configured; provider failures degrade to text. --}}
            <p><a class="home-btn home-btn--secondary" href="{{ $live['embed_url'] }}" rel="noopener noreferrer" target="_blank">Open live stream</a></p>
        </div>
    @else
        <p class="home-empty home-live-not-configured" role="status">
            {{ $text['live_not_configured'] ?? 'LIVE DRAW NOT_CONFIGURED' }}
        </p>
    @endif

    @if (($live['replay_status'] ?? 'not_configured') === 'configured')
        <p class="home-muted">Replay catalogue: CONFIGURED</p>
    @else
        <p class="home-muted">{{ $text['replay_not_configured'] ?? 'REPLAY NOT_CONFIGURED' }}</p>
    @endif

    @if (!empty($live['source_state']))
        <p class="home-badge home-badge--neutral">Source: {{ $live['source_state'] }}</p>
    @endif
</section>
