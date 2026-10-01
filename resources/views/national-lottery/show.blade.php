@extends('layouts.app')

@section('title', $meta['title'].'')
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')<link rel="stylesheet" href="{{ asset('css/national-lottery.css') }}">@endpush

@section('content')<main class="min-h-screen bg-[#0B0904] px-4 pb-20 pt-24 text-white sm:px-6 lg:px-8"><div class="mx-auto max-w-6xl"><x-national-lottery.detail-content :projection="$current" :years="$years" :is-thai="$is_thai" detail-mode="legacy-show" /></div></main>@endsection

@push('scripts')@vite(['resources/js/national-lottery.js'])@endpush
