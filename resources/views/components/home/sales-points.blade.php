{{-- Sales-point CTA — links to the public search page backed by GloSalesPointService. --}}
<section class="home-card" aria-labelledby="sales-points-title" id="sales-points">
    <div class="home-card__head">
        <h2 id="sales-points-title">{{ $text['sales_title'] ?? 'Sales points' }}</h2>
    </div>
    <p class="home-muted">{{ $text['sales_help'] ?? 'Find published GLO sales points near you.' }}</p>
    <p>
        <a class="home-btn home-btn--secondary" href="{{ route('sales-points') }}">
            {{ $text['cta_sales'] ?? 'Find a Sales Point' }}
        </a>
    </p>
</section>
