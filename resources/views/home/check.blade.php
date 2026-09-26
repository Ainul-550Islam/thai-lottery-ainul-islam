@extends('layouts.app')

@section('title', $home['text']['check_title'] ?? 'Check Your Ticket')

@section('content')
    <div class="home-page home-page--narrow">
        <h1 class="home-page__title">{{ $home['text']['check_title'] ?? 'Check Your Ticket' }}</h1>
        <p class="home-muted">{{ $home['text']['check_help'] ?? 'Enter exactly six digits. Leading zeros are preserved.' }}</p>

        @if (!empty($error))
            <p class="home-error" role="alert">{{ $error }}</p>
        @endif

        <form class="home-check-form home-check-form--page"
              method="POST"
              action="{{ route('ticket-check.submit') }}"
              accept-charset="UTF-8">
            @csrf
            <div class="home-field">
                <label for="check-number">{{ $home['text']['check_label'] ?? '6-digit ticket number' }}</label>
                <input
                    id="check-number"
                    name="number"
                    type="text"
                    inputmode="numeric"
                    autocomplete="off"
                    pattern="\d{6}"
                    maxlength="6"
                    minlength="6"
                    required
                    value="{{ old('number', $number) }}"
                    placeholder="{{ $home['text']['check_placeholder'] ?? 'e.g. 012345' }}"
                    class="home-input home-input--digits"
                    aria-describedby="check-help"
                >
                <p id="check-help" class="home-help">{{ $home['text']['check_help'] ?? '' }}</p>
                @error('number')
                    <p class="home-error" role="alert">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="home-btn home-btn--primary">{{ $home['text']['check_button'] ?? 'Check Result' }}</button>
        </form>

        @if (is_array($result))
            <section class="home-card home-check-result" aria-live="polite" aria-labelledby="check-result-title">
                <div class="home-card__head">
                    <h2 id="check-result-title">Check result</h2>
                    @if (!empty($result['source_state']))
                        <span class="home-badge home-badge--{{ \Illuminate\Support\Str::slug($result['source_state'], '-') }}">
                            {{ $result['source_state'] }}
                        </span>
                    @endif
                </div>

                <p>
                    Ticket
                    <strong class="home-digits" data-ticket-digit>{{ $result['ticket_number'] ?? $number }}</strong>
                    @if (!empty($result['draw_number']))
                        · Draw {{ $result['draw_number'] }}
                        @if (!empty($result['draw_date'])) · {{ $result['draw_date'] }} @endif
                    @endif
                </p>

                @if (!empty($result['won']))
                    <p class="home-check-result__won" role="status">
                        Matched — total prize
                        <strong><span data-money-thb>{{ $result['total_prize'] ?? '0.00' }}</span> THB</strong>
                    </p>
                    @if (!empty($result['matches']) && is_array($result['matches']))
                        <ul class="home-check-result__matches" role="list">
                            @foreach ($result['matches'] as $match)
                                <li>
                                    @if (is_array($match))
                                        {{ json_encode($match, JSON_UNESCAPED_UNICODE) }}
                                    @else
                                        {{ $match }}
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @else
                    <p class="home-muted" role="status">
                        @if (!empty($result['message']))
                            {{ $result['message'] }}
                        @else
                            {{ $result['claim_hint'] ?? 'No matching prize category.' }}
                        @endif
                    </p>
                @endif

                @if (!empty($result['claim_hint']) && !empty($result['won']))
                    <p class="home-help">{{ $result['claim_hint'] }}</p>
                @endif
            </section>
        @endif

        <p class="home-muted">
            <a href="{{ route('home') }}">{{ $home['text']['meta_title'] ?? 'Home' }}</a>
            ·
            <a href="{{ route('results.index') }}">{{ $home['text']['footer_results'] ?? 'Results' }}</a>
        </p>

        <x-home.footer
            :text="$home['text']"
            :appName="$home['hero']['app_name'] ?? config('app.name')"
        />
    </div>
@endsection
