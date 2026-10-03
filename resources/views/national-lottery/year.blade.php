@extends('layouts.app')

@section('title', $meta['title'].'')
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')@vite(['resources/css/national-lottery.css'])@endpush

@section('content')
@php $rows = is_array($history['rows'] ?? null) ? $history['rows'] : []; $historyStatus = strtolower((string) ($history['status'] ?? 'no_public_data')); @endphp
<main class="min-h-screen bg-[#0B0904] px-4 pb-20 pt-24 text-white sm:px-6 lg:px-8" data-national-year-archive="{{ $active_year ?? '' }}"><div class="mx-auto max-w-7xl space-y-8"><header class="space-y-3"><p class="text-xs font-semibold uppercase tracking-[0.18em] text-[#D4AF37]">{{ trans('national_lottery.year_nav_heading') }}</p><h1 class="text-4xl font-black text-[#F5E6B8]">{{ trans('national_lottery.history_heading') }}@if ($active_year !== null) — {{ $active_year }}@endif</h1><p class="max-w-3xl text-sm leading-7 text-gray-400">{{ trans('national_lottery.meta_description_year', ['year' => $active_year ?? '']) }}</p></header>@if ($rows === [])<section class="rounded-3xl border border-amber-400/30 bg-[#141007] p-8" role="status"><p class="text-lg font-bold text-amber-200">{{ trans('national_lottery.'.($historyStatus === 'invalid_query' ? 'status.invalid_query' : 'empty_year')) }}</p></section>@else<section class="space-y-4"><x-national-lottery.result-table :rows="$rows" :is-thai="$is_thai" /><p class="text-sm text-gray-500">{{ trans('national_lottery.pagination_total', ['total' => (int) ($history['pagination']['total'] ?? count($rows))]) }}</p></section>@endif<x-national-lottery.year-nav :years="$years" :active-year="$active_year ?? null" /></div></main>
@endsection
