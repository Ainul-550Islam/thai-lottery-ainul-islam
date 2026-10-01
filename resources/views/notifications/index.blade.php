@extends('layouts.app')

@section('title', trans('notifications.title'))
@section('meta_description', trans('notifications.meta_description'))
@section('robots', 'noindex, nofollow')

@section('content')
<main class="next-shell next-content" id="notifications-main" tabindex="-1" aria-labelledby="notifications-title">
    <a class="pp-skip-link" href="#notifications-main">{{ trans('public_pages.skip_to_content') }}</a>
    <header class="next-page-header">
        <div class="next-shell next-page-header__inner">
            <a class="next-brand" href="{{ route('player.dashboard') }}" aria-label="{{ trans('notifications.home_aria') }}">
                <span class="next-brand__mark">TL</span>
                <span>THAILOTTO<small>{{ trans('notifications.brand_subtitle') }}</small></span>
            </a>
            <nav class="next-nav" aria-label="{{ trans('notifications.primary_nav') }}">
                <a href="{{ route('player.dashboard') }}">{{ trans('notifications.nav_dashboard') }}</a>
                <a class="is-active" href="{{ route('notifications.index') }}" aria-current="page">{{ trans('notifications.nav_notifications') }}</a>
                <a href="{{ route('support.index') }}">{{ trans('notifications.nav_support') }}</a>
            </nav>
        </div>
    </header>

    <section class="next-hero" aria-labelledby="notifications-title">
        <div>
            <p class="next-eyebrow">{{ trans('notifications.eyebrow') }}</p>
            <h1 id="notifications-title">{{ trans('notifications.title') }}</h1>
            <p>{{ trans('notifications.description') }}</p>
        </div>
        <div class="next-hero-object" aria-hidden="true"><span>NTF</span></div>
    </section>

    <section class="next-panel" aria-labelledby="notification-list-title">
        <div class="next-panel-header">
            <h2 id="notification-list-title">{{ trans('notifications.records') }}</h2>
            <span>{{ $state }}</span>
        </div>
        <div class="next-table-wrap">
            <table class="next-table">
                <caption class="sr-only">{{ trans('notifications.records') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ trans('notifications.type') }}</th>
                        <th scope="col">{{ trans('notifications.subject') }}</th>
                        <th scope="col">{{ trans('notifications.status') }}</th>
                        <th scope="col">{{ trans('notifications.created') }}</th>
                        <th scope="col">{{ trans('notifications.read') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($records as $record)
                        <tr>
                            <td>{{ $record['type'] ?? trans('notifications.no_data') }}</td>
                            <td>{{ $record['subject'] ?? trans('notifications.no_data') }}</td>
                            <td>{{ $record['status'] ?? trans('notifications.no_data') }}</td>
                            <td>{{ $record['created_at'] ?? trans('notifications.no_data') }}</td>
                            <td>{{ $record['read_at'] ?? trans('notifications.unread') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" role="status">{{ trans('notifications.no_records') }} · {{ $state }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</main>
@endsection
