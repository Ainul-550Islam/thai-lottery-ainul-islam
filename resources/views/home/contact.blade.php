@extends('layouts.app')

@section('title', $home['text']['footer_contact'] ?? 'Contact')

@section('content')
    <div class="home-page home-page--narrow">
        <h1 class="home-page__title">{{ $home['text']['support_title'] ?? 'Support' }}</h1>

        <x-home.support
            :text="$home['text']"
            :support="$home['support']"
        />

        <x-home.footer
            :text="$home['text']"
            :appName="$home['hero']['app_name'] ?? config('app.name')"
        />
    </div>
@endsection
