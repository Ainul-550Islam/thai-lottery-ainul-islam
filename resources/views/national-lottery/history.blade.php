@extends('layouts.app')

@section('title', $meta['title'].'')
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])

@push('styles')@vite(['resources/css/national-lottery.css'])@endpush

@section('content')
@php $rows = is_array($history['rows'] ?? null) ? $history['rows'] : []; @endphp
<main class="min-h-screen bg-[#0B0904] px-4 pb-20 pt-24 text-white sm:px-6 lg:px-8"><div class="mx-auto max-w-7xl space-y-8"><header><p class="text-xs font-semibold uppercase tracking-[0.18em] text-[#D4AF37]">{{ trans('national_lottery.history_heading') }}</p><h1 class="mt-2 text-4xl font-black text-[#F5E6B8]">{{ trans('national_lottery.history_heading') }}</h1><p class="mt-3 max-w-3xl text-sm leading-7 text-gray-400">{{ trans('national_lottery.intro') }}</p></header><x-national-lottery.search-form :action="$routes['search']" :max-length="$max_search_length" />@if ($history === null || $rows === [])<section class="rounded-3xl border border-amber-400/30 bg-[#141007] p-8" role="status"><p class="text-lg font-bold text-amber-200">{{ trans('national_lottery.empty_year') }}</p></section>@else<x-national-lottery.result-table :rows="$rows" :is-thai="$is_thai" />@endif<x-national-lottery.year-nav :years="$years" :active-year="$active_year ?? null" /></div></main>
@endsection
