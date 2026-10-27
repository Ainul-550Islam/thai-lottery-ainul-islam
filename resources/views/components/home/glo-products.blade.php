{{-- GLO product summary: L6 80.00 full-sale, N3 20.00 draw-calculated — config-driven only. --}}
<section class="home-card" aria-labelledby="products-title" id="products">
    <div class="home-card__head">
        <h2 id="products-title">{{ $text['products_title'] ?? 'GLO products' }}</h2>
        @if (!empty($products['status']))
            <span class="home-badge home-badge--{{ \Illuminate\Support\Str::slug($products['status'], '-') }}">{{ $products['status'] }}</span>
        @endif
    </div>

    @if (empty($products['products']))
        <p class="home-empty">Product summary unavailable.</p>
    @else
        <div class="home-products">
            @foreach ($products['products'] as $product)
                <article class="home-product" aria-label="{{ $product['name'] ?? $product['code'] }}">
                    <h3>{{ $product['code'] }} — {{ $product['name'] ?? '' }}</h3>
                    <p class="home-product__price">
                        <strong>{{ $product['ticket_price'] }} {{ $product['currency'] }}</strong>
                        / ticket
                    </p>
                    <p class="home-muted">{{ $product['digits'] }} digits · model: {{ $product['prize_model'] }}</p>
                    @if (!empty($product['headline_prize']))
                        <p class="home-product__prize">1st prize {{ $product['headline_prize'] }} THB</p>
                    @endif
                    @if (!empty($product['notes']))
                        <p class="home-help">{{ $product['notes'] }}</p>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</section>
