{{-- Support: configured contact only — never invents phone/email/address. --}}
<section class="home-card" aria-labelledby="support-title" id="support">
    <div class="home-card__head">
        <h2 id="support-title">{{ $text['support_title'] ?? 'Support' }}</h2>
        <span class="home-badge home-badge--{{ \Illuminate\Support\Str::slug($support['status'] ?? 'NOT_CONFIGURED', '-') }}">
            {{ $support['status'] ?? 'NOT_CONFIGURED' }}
        </span>
    </div>

    <p class="home-muted">{{ $text['support_help'] ?? 'We are here to help with tickets, draws and accounts.' }}</p>

    @if (($support['status'] ?? 'NOT_CONFIGURED') === 'NOT_CONFIGURED' && empty($support['route_url']))
        <p class="home-empty" role="status">{{ $support['message'] ?? 'Support contact details are not configured yet.' }}</p>
    @else
        <ul class="home-support" role="list">
            @if (!empty($support['email']))
                <li>
                    <span>Email:</span>
                    <a href="mailto:{{ $support['email'] }}">{{ $support['email'] }}</a>
                </li>
            @endif
            @if (!empty($support['phone']))
                <li>
                    <span>Phone:</span>
                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $support['phone']) }}">{{ $support['phone'] }}</a>
                </li>
            @endif
            @if (!empty($support['hours']))
                <li><span>Hours:</span> {{ $support['hours'] }}</li>
            @endif
            @if (!empty($support['route_url']))
                <li><a class="home-btn home-btn--secondary" href="{{ $support['route_url'] }}">{{ $text['support_contact'] ?? 'Contact support' }}</a></li>
            @endif
        </ul>
    @endif
</section>
