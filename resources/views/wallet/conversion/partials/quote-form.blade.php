{{--
    The quote form: the member's one interaction with this page.

    THREE THINGS HAPPEN HERE, IN THIS ORDER
      1. The member types an amount. A debounced request to the preview endpoint
         replaces the fresh-quote figures with figures for THAT amount. Nothing
         is committed by typing.
      2. Pressing "Get price" creates a real quote with a real expiry — a price
         the engine has promised for the configured TTL.
      3. Pressing "Convert" executes. This is the only action that moves money,
         and it carries an idempotency key generated once and reused on retries,
         so a double-tap or a flaky connection cannot move the money twice.

    WHY THE IDEMPOTENCY KEY IS GENERATED IN THE BROWSER AND NOT THE SERVER
    The server cannot tell a retry from a new intent. The browser can: the key is
    minted when the form is rendered, kept for the life of that form, and sent
    unchanged if the submit is repeated. A member who reloads the page gets a new
    key and a new conversion, which is what they asked for.

    WHY THE DERIVED FIELDS ARE PRESENT BUT NOT EDITABLE
    There are no hidden inputs for the fee, the rate, the wallets or the member.
    The server derives all of them and refuses a request that supplies any of
    them (the request class marks them `prohibited`), so this form cannot be used
    to negotiate its own price.
--}}
<section class="wc-card wc-card--form" aria-labelledby="wc-form-heading">
    <h2 class="wc-card__title" id="wc-form-heading">{{ __('wallet_conversion.form_heading') }}</h2>
    <p class="wc-card__hint">
        {{ __('wallet_conversion.form_hint', ['seconds' => $quoteTtlSeconds]) }}
    </p>

    <form
        class="wc-form"
        method="POST"
        action="{{ route('player.wallet.conversion.store') }}"
        data-wc-conversion-form
        data-wc-preview-url="{{ route('player.wallet.conversion.preview') }}"
        data-wc-quote-url="{{ route('player.wallet.conversion.quote') }}"
        data-wc-currency="{{ $currency->value }}"
        data-wc-direction="{{ $direction->value }}"
        data-wc-min="{{ $limits->minimum() }}"
        data-wc-max="{{ $limits->maximum() ?? '' }}"
        data-wc-available="{{ $sourceWallet === null ? '0.00' : bcadd((string) $sourceWallet->balance, '0', 2) }}"
        {{-- The messages the script shows when it cannot reach the server. They
             live here, translated with the rest of the page, so no member-facing
             sentence is ever assembled inside JavaScript. --}}
        data-wc-amount-hint="{{ __('wallet_conversion.script_amount_hint') }}"
        data-wc-unpriced-note="{{ __('wallet_conversion.script_unpriced_note') }}"
        data-wc-no-crypto-note="{{ __('wallet_conversion.script_no_crypto_note') }}"
    >
        @csrf

        {{-- The key is written by wallet-conversion.js on load and on every
             successful execute; this hidden field is the single source the
             controller reads. --}}
        <input type="hidden" name="idempotency_key" value="" data-wc-idempotency-key>

        <input type="hidden" name="direction" value="{{ $direction->value }}">

        @if (session('conversion_quote_reference'))
            <input type="hidden" name="quote_reference" value="{{ session('conversion_quote_reference') }}" data-wc-quote-reference>
        @else
            <input type="hidden" name="quote_reference" value="" data-wc-quote-reference>
        @endif

        <div class="wc-field">
            <label class="wc-field__label" for="wc-conversion-amount">{{ __('wallet_conversion.field_amount') }}</label>

            <div class="wc-field__control">
                <input
                    class="wc-field__input"
                    id="wc-conversion-amount"
                    name="amount"
                    type="text"
                    inputmode="decimal"
                    autocomplete="off"
                    required
                    value="{{ old('amount', $limits->minimum()) }}"
                    data-wc-amount
                    aria-describedby="wc-amount-help wc-amount-error"
                >
                <span class="wc-field__suffix">{{ $currency->value }}</span>
            </div>

            <p class="wc-field__help" id="wc-amount-help">
                {{ __('wallet_conversion.field_amount_help') }}
                {{ $limits->minimum() }} – {{ $limits->maximum() ?? '∞' }} {{ $currency->value }}.
            </p>

            @error('amount')
                <p class="wc-field__error" id="wc-amount-error" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div class="wc-preview" data-wc-preview aria-live="polite">
            <div class="wc-preview__cell">
                <span class="wc-preview__label">{{ __('wallet_conversion.field_fee') }}</span>
                <span class="wc-preview__value wc-money--fee" data-wc-preview-fee>{{ $feeBreakdown->feeAmount }}</span>
            </div>
            <div class="wc-preview__cell">
                <span class="wc-preview__label">{{ __('wallet_conversion.field_receive') }}</span>
                <span class="wc-preview__value wc-money--in" data-wc-preview-net>{{ $feeBreakdown->netAmount }}</span>
            </div>
            <div class="wc-preview__cell">
                <span class="wc-preview__label">{{ __('wallet_conversion.field_rate') }}</span>
                <span class="wc-preview__value" data-wc-preview-rate>{{ $feeBreakdown->percentage }}%</span>
            </div>
        </div>

        <p class="wc-preview__note" data-wc-preview-note hidden></p>

        @error('direction')
            <p class="wc-field__error" role="alert">{{ $message }}</p>
        @enderror

        @error('idempotency_key')
            <p class="wc-field__error" role="alert">{{ $message }}</p>
        @enderror

        @error('quote_reference')
            <p class="wc-field__error" role="alert">{{ $message }}</p>
        @enderror

        <div class="wc-form__actions">
            <button class="wc-button wc-button--primary" type="submit" data-wc-submit>
                {{ __('wallet_conversion.action_convert') }}
            </button>

            <button class="wc-button wc-button--ghost" type="button" data-wc-quote>
                {{ __('wallet_conversion.action_get_price') }}
            </button>
        </div>

        @if ($dailyRemaining !== null)
            <p class="wc-form__footnote">
                {{ __('wallet_conversion.daily_remaining', [
                    'amount' => $dailyRemaining,
                    'currency' => $currency->value,
                ]) }}
            </p>
        @endif

        <p class="wc-form__footnote wc-form__footnote--quiet" data-wc-disabled-note hidden>
            {{ __('wallet_conversion.script_unavailable') }}
        </p>
    </form>
</section>
