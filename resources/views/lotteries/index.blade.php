@extends('layouts.app')

@section('title', $meta['title'].' | ThaiLotto')
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])

@push('styles')
    @vite(['resources/css/thailotto-theme.css'])
    @vite(['resources/css/lottery.css'])
@endpush

@section('content')
<main class="min-h-screen bg-[#0B0904] px-4 pb-20 pt-24 text-white sm:px-6 lg:px-8" data-lottery-hub>
    <div class="mx-auto max-w-7xl space-y-12">
        <x-lottery-hub.hero />
        @if (($catalog['products'] ?? []) === [])
            <section class="rounded-3xl border border-amber-400/30 bg-[#141007] p-8" role="status"><h2 class="text-xl font-bold text-[#F5E6B8]">{{ trans('lottery_hub.unavailable_heading') }}</h2><p class="mt-2 max-w-2xl text-sm leading-7 text-gray-400">{{ trans('lottery_hub.unavailable_body') }}</p></section>
        @else
            <x-lottery-hub.category-filter :products="$catalog['products']" />
            <x-lottery-hub.featured-lotteries :products="$catalog['products']" />
            <x-lottery-hub.compare :products="$catalog['products']" />
        @endif
        <x-lottery-hub.results-cta />
    </div>
</main>
@endsection
