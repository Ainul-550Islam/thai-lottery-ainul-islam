@props(['productKey', 'productTitle', 'productDescription', 'purchaseState', 'backUrl'])

<div class="min-h-[70vh] bg-[#0B0904] px-4 pb-16 pt-8 text-[#FAF6EC] sm:px-6 lg:px-8" data-lottery-purchase="{{ $productKey }}">
    <div class="mx-auto max-w-4xl space-y-8">
        <nav aria-label="{{ trans('lottery_hub.breadcrumb') }}" class="text-sm text-[#D4CAB3]">
            <a class="hover:text-[#D4AF37] underline underline-offset-4" href="{{ $backUrl }}">{{ $productTitle }}</a>
            <span class="px-2" aria-hidden="true">/</span>
            <span class="text-[#FFF6D6] font-semibold">{{ trans('lottery_hub.buy_title') }}</span>
        </nav>

        <header class="space-y-4">
            <p class="tl-v2-eyebrow">{{ trans('lottery_hub.buy_eyebrow') }}</p>
            <h1 class="text-3xl font-black text-[#FFF6D6] sm:text-5xl">{{ $productTitle }} — {{ trans('lottery_hub.buy_title') }}</h1>
            <p class="max-w-2xl text-base leading-8 text-[#D4CAB3]">{{ $productDescription }}</p>
        </header>

        <section class="tl-v2-card border-[#D4AF37]/40" role="status" aria-live="polite">
            <div class="flex items-start gap-4">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-[#D4AF37]/45 bg-[#D4AF37]/15 text-xl font-black text-[#FFF6D6]" aria-hidden="true">!</div>
                <div class="space-y-3">
                    <h2 class="text-xl font-bold text-[#FFF6D6]">{{ trans('lottery_hub.purchase_unavailable_heading') }}</h2>
                    <p class="text-sm leading-7 text-[#D4CAB3]">{{ trans('lottery_hub.purchase_unavailable_body') }}</p>
                    <p class="font-mono text-xs uppercase tracking-wider text-[#F5E6B8]">{{ $purchaseState }}</p>
                </div>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-3" aria-label="{{ trans('lottery_hub.purchase_safety_heading') }}">
            @foreach (['price', 'wallet', 'success'] as $item)
                <div class="tl-v2-subcard">
                    <h2 class="text-sm font-bold text-[#FFF6D6]">{{ trans('lottery_hub.purchase_safety.'.$item.'.heading') }}</h2>
                    <p class="mt-2 text-xs leading-6 text-[#D4CAB3]">{{ trans('lottery_hub.purchase_safety.'.$item.'.body') }}</p>
                </div>
            @endforeach
        </section>

        <a class="tl-v2-btn-outline" href="{{ $backUrl }}">← {{ trans('lottery_hub.back_to_results') }}</a>
    </div>
</div>
