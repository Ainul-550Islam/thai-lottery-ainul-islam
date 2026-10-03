@extends('layouts.app')

@section('title', $meta['title'].'')
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])

@push('styles')@vite(['resources/css/national-lottery.css'])@endpush

@section('content')
<main class="min-h-screen bg-[#0B0904] px-4 pb-20 pt-24 text-white sm:px-6 lg:px-8"><div class="mx-auto max-w-6xl space-y-8"><header class="space-y-3"><p class="text-xs font-semibold uppercase tracking-[0.18em] text-[#D4AF37]">{{ trans('national_lottery.current_result_heading') }}</p><h1 class="text-4xl font-black text-[#F5E6B8]">{{ trans('national_lottery.current_result_heading') }}</h1><p class="max-w-3xl text-sm leading-7 text-gray-400">{{ trans('national_lottery.intro') }}</p></header><x-national-lottery.result-card :result="$current" :is-thai="$is_thai" /><a class="inline-flex rounded-xl border border-[#D4AF37]/40 bg-[#221B0E] px-4 py-3 text-sm font-bold text-[#F5E6B8]" href="{{ route('prize-verification') }}">{{ trans('national_lottery.view_detail') }}</a><x-national-lottery.year-nav :years="$years" :active-year="null" /></div></main>
@endsection
