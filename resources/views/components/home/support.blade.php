{{-- Support: configured contact only — never invents phone/email/address. --}}
<section class="home-card" aria-labelledby="support-title" id="support">
    <div class="home-card__head">
        <h2 id="support-title">{{ $text['support_title'] ?? trans('home.support_title') }}</h2>
        <span class="home-badge home-badge--{{ \Illuminate\Support\Str::slug($support['status'] ?? 'NOT_CONFIGURED', '-') }}">
            {{ ($support['status'] ?? 'NOT_CONFIGURED') === 'CONFIGURED' ? trans('home.section_status.configured') : trans('home.section_status.not_configured') }}
        </span>
    </div>

    <p class="home-muted">{{ trans('home.support_help') }}</p>

    @if (($support['status'] ?? 'NOT_CONFIGURED') === 'NOT_CONFIGURED' && empty($support['route_url']))
        <p class="home-empty" role="status">{{ trans('home.support_not_configured') }}</p>
    @else
        <ul class="home-support" role="list">
            @if (!empty($support['email']))
                <li>
                    <span>{{ trans('home.email_label') }}:</span>
                    <a href="mailto:{{ $support['email'] }}">{{ $support['email'] }}</a>
                </li>
            @endif
            @if (!empty($support['phone']))
                <li>
                    <span>{{ trans('home.phone_label') }}:</span>
                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $support['phone']) }}">{{ $support['phone'] }}</a>
                </li>
            @endif
            @if (!empty($support['hours']))
                <li><span>{{ trans('home.hours_label') }}:</span> {{ $support['hours'] }}</li>
            @endif
            @if (!empty($support['route_url']))
                <li><a class="home-btn home-btn--secondary" href="{{ $support['route_url'] }}">{{ trans('home.support_contact') }}</a></li>
            @endif
        </ul>
    @endif
</section>
