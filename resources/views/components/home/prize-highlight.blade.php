{{-- Prize / jackpot highlight: verified or catalogue-only; never fabricates a rolling jackpot. --}}
<section class="home-card" aria-labelledby="prize-title" id="prize">
    <div class="home-card__head">
        <h2 id="prize-title">{{ $text['prize_title'] ?? 'Prize highlight' }}</h2>
        @if (!empty($prize['status']))
            <span class="home-badge home-badge--{{ \Illuminate\Support\Str::slug($prize['status'], '-') }}">{{ $prize['status'] }}</span>
        @endif
    </div>

    @if (($prize['status'] ?? 'UNAVAILABLE') === 'UNAVAILABLE')
        <p class="home-empty" role="status">{{ $prize['message'] ?? ($text['prize_unavailable'] ?? 'Prize summary unavailable') }}</p>
    @else
        @if (!empty($prize['label']))
            <p class="home-prize__label">{{ $prize['label'] }}</p>
        @endif
        @if (!empty($prize['amount']))
            <p class="home-prize__amount">
                <span data-money-thb>{{ $prize['amount'] }}</span> THB
            </p>
        @endif
        @if (!empty($prize['draw_date']))
            <p class="home-muted">
                Draw {{ $prize['draw_number'] ?? '' }} {{ $prize['draw_date'] }}
            </p>
        @endif
        <p class="home-badge home-badge--{{ \Illuminate\Support\Str::slug($prize['source_state'] ?? 'UNAVAILABLE', '-') }}">
            Source: {{ $prize['source_state'] ?? 'UNAVAILABLE' }}
        </p>
        @if (!empty($prize['fixture_sample']))
            <p class="home-help">Fixture sample — not an official government announcement.</p>
        @endif
        @if (($prize['status'] ?? '') === 'CATALOGUE_ONLY')
            <p class="home-help">{{ $prize['message'] ?? '' }}</p>
        @endif
        {{-- Internal number is never labeled "Official GLO Jackpot". --}}
    @endif
</section>
