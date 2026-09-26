{{-- Trust / security: factual claims only from the real implementation (config home.trust.bullets). --}}
<section class="home-card" aria-labelledby="trust-title" id="trust">
    <div class="home-card__head">
        <h2 id="trust-title">{{ $text['trust_title'] ?? 'Security & trust' }}</h2>
        <span class="home-badge home-badge--{{ \Illuminate\Support\Str::slug($trust['status'] ?? 'VERIFIED', '-') }}">
            {{ $trust['status'] ?? 'VERIFIED' }}
        </span>
    </div>

    @if (empty($trust['bullets']))
        <p class="home-empty">No public trust statements configured.</p>
    @else
        <ul class="home-trust" role="list">
            @foreach ($trust['bullets'] as $bullet)
                <li>{{ $bullet }}</li>
            @endforeach
        </ul>
    @endif
</section>
