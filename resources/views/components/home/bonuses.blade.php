{{-- Bonuses / campaigns: only active configured windows; empty → "No active promotions". --}}
<section class="home-card" aria-labelledby="bonuses-title" id="bonuses">
    <div class="home-card__head">
        <h2 id="bonuses-title">{{ $text['bonuses_title'] ?? 'Bonuses & campaigns' }}</h2>
        @if (!empty($bonuses['status']))
            <span class="home-badge home-badge--{{ \Illuminate\Support\Str::slug($bonuses['status'], '-') }}">{{ $bonuses['status'] }}</span>
        @endif
    </div>

    @if (($bonuses['status'] ?? 'UNAVAILABLE') !== 'VERIFIED' || empty($bonuses['campaigns']))
        <p class="home-empty" role="status">{{ $bonuses['message'] ?? ($text['bonuses_empty'] ?? 'No active promotions') }}</p>
    @else
        <ul class="home-campaigns" role="list">
            @foreach ($bonuses['campaigns'] as $campaign)
                <li class="home-campaign" data-campaign-id="{{ $campaign['id'] }}">
                    <h3>{{ $campaign['title'] }}</h3>
                    @if (!empty($campaign['description']))
                        <p>{{ $campaign['description'] }}</p>
                    @endif
                    <p class="home-muted">
                        @if (!empty($campaign['valid_from']))
                            From {{ \Illuminate\Support\Carbon::parse($campaign['valid_from'])->toDateString() }}
                        @endif
                        @if (!empty($campaign['valid_until']))
                            until {{ \Illuminate\Support\Carbon::parse($campaign['valid_until'])->toDateString() }}
                        @endif
                    </p>
                    @if (!empty($campaign['cta_url']) && !empty($campaign['cta_label']))
                        <p><a class="home-btn home-btn--ghost" href="{{ $campaign['cta_url'] }}">{{ $campaign['cta_label'] }}</a></p>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
