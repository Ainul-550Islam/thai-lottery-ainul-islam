{{-- Payment methods: only enabled + configured + public gateways with visual marks. --}}
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
                <li class="home-payment flex items-center justify-between p-3 bg-slate-950/60 border border-slate-800 rounded-xl">
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 rounded-lg bg-slate-800 flex items-center justify-center font-bold text-xs text-emerald-400 border border-slate-700">
                            @if ($method['code'] === 'stripe')
                                <svg class="w-5 h-5 text-indigo-400" fill="currentColor" viewBox="0 0 24 24"><path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/></svg>
                            @elseif ($method['code'] === 'bkash')
                                <span class="text-pink-500 font-bold text-xs">bK</span>
                            @elseif ($method['code'] === 'nagad')
                                <span class="text-orange-500 font-bold text-xs">Ng</span>
                            @elseif ($method['code'] === 'crypto')
                                <svg class="w-5 h-5 text-amber-400" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 14h-2v-2h-2v-2h4v-2h-4V8h2V6h2v2h2v4h-4v2h4v2z"/></svg>
                            @elseif ($method['code'] === 'bank_transfer')
                                <svg class="w-5 h-5 text-emerald-400" fill="currentColor" viewBox="0 0 24 24"><path d="M4 10v7h3v-7H4zm6 0v7h3v-7h-3zM2 22h19v-3H2v3zm14-12v7h3v-7h-3zm-4.5-9L2 6v2h19V6l-9.5-5z"/></svg>
                            @else
                                <span class="text-slate-400 font-bold text-xs">{{ strtoupper(substr($method['code'], 0, 2)) }}</span>
                            @endif
                        </div>
                        <div>
                            <span class="home-payment__label font-bold text-sm text-slate-100 block">{{ $method['label'] }}</span>
                            @if (!empty($method['currencies']))
                                <span class="home-muted text-xs text-slate-400">{{ implode(', ', $method['currencies']) }}</span>
                            @endif
                        </div>
                    </div>
                    <span class="home-badge home-badge--{{ \Illuminate\Support\Str::slug($method['status'], '-') }} text-xs font-semibold px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                        {{ $method['status'] }}
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</section>
