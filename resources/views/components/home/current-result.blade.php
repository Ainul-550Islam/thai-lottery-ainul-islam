{{-- Current verified (or honestly labeled) result. Digit strings only — never int-cast. --}}
<section class="home-card" aria-labelledby="current-result-title" id="current-result">
    <div class="home-card__head">
        <h2 id="current-result-title">{{ $text['current_result_title'] ?? 'Current verified result' }}</h2>
        @isset($status)
            <span class="home-badge home-badge--{{ \Illuminate\Support\Str::slug($status, '-') }}">{{ $status }}</span>
        @endisset
    </div>

    @if (($result['status'] ?? 'UNAVAILABLE') === 'NO_VERIFIED_RESULT' || empty($result['has_result']))
        <p class="home-empty" role="status">{{ $result['message'] ?? ($text['current_result_none'] ?? 'No verified result available') }}</p>
        @if (!empty($result['source_state']))
            <p class="home-muted">Source: {{ $result['source_state'] }}</p>
        @endif
    @else
        <div class="home-result">
            @if (!empty($result['draw_number']))
                <p class="home-result__meta">Draw <strong>{{ $result['draw_number'] }}</strong>@if(!empty($result['draw_date'])) · {{ $result['draw_date'] }}@endif</p>
            @endif

            <p class="home-result__first">
                <span class="home-result__tier">1st prize</span>
                <span class="home-digits" data-ticket-digit>{{ $result['first_prize'] }}</span>
            </p>

            @if (!empty($result['source_state']))
                <p class="home-badge home-badge--{{ \Illuminate\Support\Str::slug($result['source_state'], '-') }}">
                    Source: {{ $result['source_state'] }}
                    @if (!empty($result['result_version']))
                        · v{{ $result['result_version'] }}
                    @endif
                </p>
            @endif

            @if (!empty($result['second_prize']))
                <div class="home-result__row">
                    <h3>2nd prize</h3>
                    <ul class="home-digits-list">
                        @foreach ($result['second_prize'] as $n)
                            <li><span data-ticket-digit>{{ $n }}</span></li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (!empty($result['last_two']))
                <div class="home-result__row">
                    <h3>Last 2 digits</h3>
                    <ul class="home-digits-list">
                        @foreach ($result['last_two'] as $n)
                            <li><span data-ticket-digit>{{ $n }}</span></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <p class="home-card__actions">
            <a href="{{ route('results.index') }}">{{ $text['cta_results'] ?? 'Full results' }}</a>
            ·
            <a href="{{ route('ticket-check') }}">{{ $text['cta_check'] ?? 'Check your ticket' }}</a>
        </p>
    @endif
</section>
