@props(['captcha'])
@php($driver = $captcha['driver'] ?? 'local')
<div>
    @if (in_array($driver, ['turnstile', 'hcaptcha'], true))
        <p class="text-sm font-medium text-slate-300 mb-1">{{ __('public_pages.captcha_label') }}</p>
        <input type="hidden" name="captcha_token" value="{{ $captcha['token'] }}">
        @if ($driver === 'turnstile')
            <div class="cf-turnstile" data-sitekey="{{ $captcha['site_key'] }}" data-action="{{ $captcha['action'] }}"></div>
            @once
                <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
            @endonce
        @else
            <div class="h-captcha" data-sitekey="{{ $captcha['site_key'] }}"></div>
            @once
                <script src="https://js.hcaptcha.com/1/api.js" async defer></script>
            @endonce
        @endif
        <p class="mt-1 text-xs text-slate-500">{{ __('Reload this page for a new security check after an error or expiry.') }}</p>
        <noscript><p role="alert">{{ __('public_pages.captcha_hint') }}</p></noscript>
    @elseif ($driver === 'unavailable')
        <p role="alert" class="text-sm text-rose-300">{{ config('auth_security.messages.captcha_failed') }}</p>
    @else
        <label for="captcha_answer" class="block text-sm font-medium text-slate-300 mb-1">{{ __('public_pages.captcha_label') }}</label>
        <div class="flex items-center gap-3">
            <span class="select-none inline-flex items-center justify-center px-4 py-3 rounded-xl border border-slate-700 bg-slate-950 font-mono text-lg tracking-widest text-emerald-300">{{ $captcha['question'] }}</span>
            <input id="captcha_answer" name="captcha_answer" type="text" inputmode="numeric" autocomplete="off" required
                   aria-describedby="captcha-hint" @if ($errors->has('captcha')) aria-invalid="true" @endif
                   class="block flex-1 px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('captcha') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}"
                   placeholder="{{ __('public_pages.captcha_placeholder') }}">
        </div>
        <input type="hidden" name="captcha_token" value="{{ $captcha['token'] }}">
        <p id="captcha-hint" class="mt-1 text-xs text-slate-500">{{ __('public_pages.captcha_hint') }}</p>
    @endif
    @foreach (['captcha', 'captcha_token', 'captcha_answer', 'cf-turnstile-response', 'h-captcha-response'] as $field)
        @error($field)
            <p role="alert" class="mt-1 text-xs text-rose-300">{{ $message }}</p>
        @enderror
    @endforeach
</div>
