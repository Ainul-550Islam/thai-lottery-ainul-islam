@extends('layouts.app')

@section('title', trans('home.footer_contact'))

@section('content')
    <div class="home-page home-page--narrow">
        <h1 class="home-page__title">{{ $home['text']['support_title'] ?? 'Support' }}</h1>

        <x-home.support
            :text="$home['text']"
            :support="$home['support']"
        />
    </div>
@endsection
