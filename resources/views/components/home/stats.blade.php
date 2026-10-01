{{-- Platform statistics: real DB counts only — never fabricated marketing numbers. --}}
<section class="home-card" aria-labelledby="stats-title" id="stats">
    <div class="home-card__head">
        <h2 id="stats-title">{{ $text['stats_title'] ?? 'Platform statistics' }}</h2>
        @if (!empty($stats['status']))
            <span class="home-badge home-badge--{{ \Illuminate\Support\Str::slug($stats['status'], '-') }}">
                {{ $stats['status'] === 'VERIFIED' ? ($text['stats_database'] ?? 'DATABASE') : $stats['status'] }}
            </span>
        @endif
    </div>

    @if (($stats['status'] ?? 'UNAVAILABLE') !== 'VERIFIED' || empty($stats['metrics']))
        <p class="home-empty" role="status">{{ $stats['message'] ?? ($text['stats_unavailable'] ?? 'Statistics unavailable') }}</p>
    @else
        <ul class="home-stats" role="list">
            @foreach ($stats['metrics'] as $metric)
                <li class="home-stat">
                    <span class="home-stat__value" data-stat="{{ $metric['key'] }}">{{ $metric['value'] }}</span>
                    <span class="home-stat__label">{{ $metric['label'] }}</span>
                </li>
            @endforeach
        </ul>
        @if (!empty($stats['generated_at']))
            <p class="home-muted">{{ $text['generated_label'] ?? 'Generated' }}: {{ $stats['generated_at'] }}</p>
        @endif
    @endif
</section>
