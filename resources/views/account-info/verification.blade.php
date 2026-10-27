@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_robots', 'index,follow')
@section('canonical', $meta['canonical'])

{{--
    Public verification guide.

    IT DESCRIBES THE PROCESS, NEVER A SUBMISSION. No document, no example
    identifier and no sample image appears here: a page showing what an
    accepted document looks like is a template for forging one. The actual
    upload stays on /account/verification behind a login.
--}}

@section('content')
    <div class="pp-page ai-page">
        <header class="pp-header">
            <h1 class="pp-header__title">{{ trans('account_info.verification_title') }}</h1>
            <p class="pp-header__lead">{{ trans('account_info.verification_lead') }}</p>
        </header>

        <section class="pp-section" aria-labelledby="ai-steps-title">
            <div class="pp-section__inner">
                <h2 class="pp-section__title" id="ai-steps-title">{{ trans('account_info.steps_heading') }}</h2>

                <ol class="ai-steps">
                    @foreach ($guide['steps'] as $step)
                        <li class="ai-steps__item">
                            <h3 class="ai-steps__title">{{ trans('account_info.step_'.$step['key'].'_title') }}</h3>
                            <p class="ai-steps__text">{{ trans('account_info.step_'.$step['key'].'_text') }}</p>
                        </li>
                    @endforeach
                </ol>

                <p class="pp-section__text">{{ trans('account_info.verification_privacy_note') }}</p>

                @if (Route::has('account.verification'))
                    <p class="pp-section__text">
                        <a class="pp-links__anchor" href="{{ route('account.verification') }}">
                            {{ trans('account_info.verification_cta') }}
                        </a>
                    </p>
                @endif
            </div>
        </section>

        <x-public-page.footer />
    </div>
@endsection
