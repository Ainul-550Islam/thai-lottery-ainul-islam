@extends('layouts.app')

@section('title', trans('support.title'))
@section('meta_description', trans('support.meta_description'))
@section('robots', 'noindex, nofollow')

@section('content')
<main class="next-shell next-content" id="support-main" tabindex="-1" aria-labelledby="support-title">
    <a class="pp-skip-link" href="#support-main">{{ trans('public_pages.skip_to_content') }}</a>
    <header class="next-page-header">
        <div class="next-shell next-page-header__inner">
            <a class="next-brand" href="{{ route('home') }}" aria-label="{{ trans('support.home_aria') }}">
                <span class="next-brand__mark">TL</span>
                <span>{{ config('app.name') }}<small>{{ trans('support.brand_subtitle') }}</small></span>
            </a>
            <nav class="next-nav" aria-label="{{ trans('support.primary_nav') }}">
                <a href="{{ route('player.dashboard') }}">{{ trans('support.nav_dashboard') }}</a>
                <a class="is-active" href="{{ route('support.index') }}" aria-current="page">{{ trans('support.nav_support') }}</a>
                <a href="{{ route('contact') }}">{{ trans('support.nav_contact') }}</a>
            </nav>
        </div>
    </header>

    <section class="next-hero" aria-labelledby="support-title">
        <div>
            <p class="next-eyebrow">{{ trans('support.eyebrow') }}</p>
            <h1 id="support-title">{{ $surface === 'detail' ? trans('support.detail_title') : trans('support.title') }}</h1>
            <p>{{ trans('support.description') }}</p>
        </div>
        <div class="next-hero-object" aria-hidden="true"><span>SUP</span></div>
    </section>

    @if (session('status'))
        <section class="next-panel" role="status" aria-live="polite">
            <p>{{ session('status') }}</p>
        </section>
    @endif

    @if ($errors->any())
        <section class="next-panel" role="alert" aria-labelledby="support-errors-title">
            <h2 id="support-errors-title">{{ trans('support.validation_title') }}</h2>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($surface === 'detail' && $case !== null)
        <section class="next-panel" aria-labelledby="support-case-title">
            <p class="next-eyebrow">{{ trans('support.case_eyebrow') }}</p>
            <h2 id="support-case-title">{{ $case->subject }}</h2>
            <dl>
                <div><dt>{{ trans('support.reference') }}</dt><dd class="font-mono">{{ $case->public_reference }}</dd></div>
                <div><dt>{{ trans('support.status_label') }}</dt><dd>{{ $case->status }}</dd></div>
                <div><dt>{{ trans('support.priority_label') }}</dt><dd>{{ $case->priority }}</dd></div>
                <div><dt>{{ trans('support.category_label') }}</dt><dd>{{ $case->category }}</dd></div>
            </dl>
        </section>

        <section class="next-panel" aria-labelledby="support-messages-title">
            <h2 id="support-messages-title">{{ trans('support.messages_title') }}</h2>
            @forelse ($case->messages as $message)
                <article class="support-message">
                    <p>{{ $message->body }}</p>
                    <time datetime="{{ optional($message->created_at)->toIso8601String() }}">{{ optional($message->created_at)->toDateTimeString() }}</time>
                </article>
            @empty
                <p>{{ trans('support.no_messages') }}</p>
            @endforelse
        </section>

        @if (! in_array($case->status, ['closed', 'resolved'], true))
            <section class="next-panel" aria-labelledby="support-reply-title">
                <h2 id="support-reply-title">{{ trans('support.reply_title') }}</h2>
                <form method="post" action="{{ route('support.reply', ['reference' => $case->public_reference]) }}">
                    @csrf
                    <label for="reply-body">{{ trans('support.reply_label') }}</label>
                    <textarea id="reply-body" name="body" rows="6" maxlength="10000" required>{{ old('body') }}</textarea>
                    <button class="next-button next-button--gold" type="submit">{{ trans('support.reply_submit') }}</button>
                </form>
            </section>
        @endif
    @else
        <section class="next-panel" aria-labelledby="support-cases-title">
            <p class="next-eyebrow">{{ trans('support.state_label') }}</p>
            <h2 id="support-cases-title">{{ trans('support.cases_title') }}</h2>
            @forelse ($records as $record)
                <article class="support-case-summary">
                    <h3><a href="{{ route('support.show', ['reference' => $record->public_reference]) }}">{{ $record->subject }}</a></h3>
                    <p>{{ trans('support.reference') }}: <span class="font-mono">{{ $record->public_reference }}</span></p>
                    <p>{{ $record->status }} · {{ $record->priority }} · {{ $record->category }}</p>
                </article>
            @empty
                <p>{{ trans('support.no_cases') }}</p>
            @endforelse
        </section>

        <section class="next-panel" aria-labelledby="support-create-title">
            <h2 id="support-create-title">{{ trans('support.create_title') }}</h2>
            <form method="post" action="{{ route('support.store') }}">
                @csrf
                <label for="support-category">{{ trans('support.category_label') }}</label>
                <select id="support-category" name="category" required>
                    @foreach (['account', 'payment', 'withdrawal', 'bet', 'lottery', 'security', 'other'] as $category)
                        <option value="{{ $category }}" @selected(old('category') === $category)>{{ trans('support.category_'.$category) }}</option>
                    @endforeach
                </select>
                <label for="support-priority">{{ trans('support.priority_label') }}</label>
                <select id="support-priority" name="priority">
                    @foreach (['low', 'normal', 'high'] as $priority)
                        <option value="{{ $priority }}" @selected(old('priority', 'normal') === $priority)>{{ trans('support.priority_'.$priority) }}</option>
                    @endforeach
                </select>
                <label for="support-subject">{{ trans('support.subject_label') }}</label>
                <input id="support-subject" name="subject" type="text" maxlength="180" value="{{ old('subject') }}" required>
                <label for="support-body">{{ trans('support.body_label') }}</label>
                <textarea id="support-body" name="body" rows="8" maxlength="10000" required>{{ old('body') }}</textarea>
                <button class="next-button next-button--gold" type="submit">{{ trans('support.create_submit') }}</button>
            </form>
        </section>
    @endif
</main>
@endsection
