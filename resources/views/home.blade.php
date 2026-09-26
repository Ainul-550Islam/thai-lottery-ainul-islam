@extends('layouts.app')

@section('title', $home['text']['meta_title'] ?? 'Home')

@section('content')
    <div class="home-page" data-home-page>
        <x-home.hero
            :text="$home['text']"
            :hero="$home['hero']"
            :nextDraw="$home['next_draw']"
        />

        <div class="home-grid home-grid--primary">
            <x-home.current-result
                :text="$home['text']"
                :result="$home['current_result']"
                :status="$home['current_result']['status'] ?? 'UNAVAILABLE'"
            />
            <x-home.next-draw
                :text="$home['text']"
                :nextDraw="$home['next_draw']"
            />
            <x-home.live-draw
                :text="$home['text']"
                :live="$home['live_draw']"
            />
            <x-home.quick-check
                :text="$home['text']"
                :number="''"
            />
        </div>

        <div class="home-grid home-grid--secondary">
            <x-home.sales-points :text="$home['text']" />
            <x-home.glo-products
                :text="$home['text']"
                :products="$home['products']"
            />
            <x-home.prize-highlight
                :text="$home['text']"
                :prize="$home['prize_highlight']"
            />
            <x-home.stats
                :text="$home['text']"
                :stats="$home['stats']"
            />
        </div>

        <div class="home-grid home-grid--tertiary">
            <x-home.bonuses
                :text="$home['text']"
                :bonuses="$home['bonuses']"
            />
            <x-home.payment-methods
                :text="$home['text']"
                :payments="$home['payment_methods']"
            />
            <x-home.support
                :text="$home['text']"
                :support="$home['support']"
            />
            <x-home.app-links
                :text="$home['text']"
                :appLinks="$home['app_links']"
            />
            <x-home.trust
                :text="$home['text']"
                :trust="$home['trust']"
            />
        </div>

        <x-home.footer
            :text="$home['text']"
            :appName="$home['hero']['app_name'] ?? config('app.name')"
        />
    </div>
@endsection

@push('scripts')
    <script type="module" src="{{ Vite::asset('resources/js/home/countdown.js') }}"></script>
    <script type="module" src="{{ Vite::asset('resources/js/home/live-draw.js') }}"></script>
@endpush
