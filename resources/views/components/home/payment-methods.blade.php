{{-- Payment methods: only enabled + configured + public gateways. --}}
<section class="home-card" aria-labelledby="payments-title" id="payments">
    <div class="home-card__head">
        <h2 id="payments-title">{{ $text['payments_title'] ?? 'Payment methods' }}</h2>
        <span class="home-badge home-badge--{{ \Illuminate\Support\Str::slug($payments['status'] ?? 'NOT_CONFIGURED', '-') }}">
            {{ $payments['status'] ?? 'NOT_CONFIGURED' }}
        </span>
    </div>

    @if (empty($payments['methods']))
        <p class="home-empty" role="status">{{ $payments['message'] ?? ($text['payments_empty'] ?? 'No payment methods are publicly available yet') }}</p>
    @else
        <ul class="home-payments" role="list">
            @foreach ($payments['methods'] as $method)
                <li class="home-payment">
                    <span class="home-payment__label">{{ $method['label'] }}</span>
                    <span class="home-badge home-badge--{{ \Illuminate\Support\Str::slug($method['status'], '-') }}">{{ $method['status'] }}</span>
                    @if (!empty($method['currencies']))
                        <span class="home-muted">{{ implode(', ', $method['currencies']) }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
