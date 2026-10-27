{{-- Operator-configured platform information; only reviewed statements belong here. --}}
<section class="home-card" aria-labelledby="trust-title" id="trust">
    <div class="home-card__head">
        <h2 id="trust-title">{{ $text['trust_title'] ?? 'Platform information' }}</h2>
        <span class="home-badge home-badge--{{ \Illuminate\Support\Str::slug($trust['status'] ?? 'NOT_CONFIGURED', '-') }}">
            {{ $trust['status'] ?? 'NOT_CONFIGURED' }}
        </span>
    </div>

    @if (empty($trust['bullets']))
        <p class="home-empty">No platform information is configured.</p>
    @else
        <ul class="home-trust" role="list">
            @foreach ($trust['bullets'] as $bullet)
                <li>{{ $bullet }}</li>
            @endforeach
        </ul>
    @endif
</section>
