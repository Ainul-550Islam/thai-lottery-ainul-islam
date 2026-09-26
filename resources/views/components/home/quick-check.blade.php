{{-- Result quick-check UI — dedicated form page is the primary UX (not raw API links). --}}
<section class="home-card home-card--check" aria-labelledby="ticket-check-title" id="check">
    <div class="home-card__head">
        <h2 id="ticket-check-title">{{ $text['check_title'] ?? 'Check Your Ticket' }}</h2>
    </div>

    <p class="home-muted">{{ $text['check_help'] ?? 'Enter exactly six digits. Leading zeros are preserved.' }}</p>

    <form class="home-check-form"
          method="POST"
          action="{{ route('ticket-check.submit') }}"
          accept-charset="UTF-8"
          novalidate>
        @csrf
        <div class="home-field">
            <label for="home-check-number">{{ $text['check_label'] ?? '6-digit ticket number' }}</label>
            <input
                id="home-check-number"
                name="number"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                pattern="\d{6}"
                maxlength="6"
                minlength="6"
                required
                value="{{ old('number', $number ?? '') }}"
                placeholder="{{ $text['check_placeholder'] ?? 'e.g. 012345' }}"
                aria-describedby="home-check-help"
                class="home-input home-input--digits"
            >
            <p id="home-check-help" class="home-help">{{ $text['check_help'] ?? 'Enter exactly six digits. Leading zeros are preserved.' }}</p>
            @error('number')
                <p class="home-error" role="alert">{{ $message }}</p>
            @enderror
        </div>
        <button type="submit" class="home-btn home-btn--primary">{{ $text['check_button'] ?? 'Check Result' }}</button>
    </form>

    <p class="home-muted">
        Prefer the full history?
        <a href="{{ route('results.index') }}">{{ $text['cta_results'] ?? 'Results' }}</a>
    </p>
</section>
