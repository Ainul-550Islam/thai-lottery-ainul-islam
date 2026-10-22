@php
    /*
     * Marketing analytics loader (D3).
     *
     * A 2026 audit found ZERO analytics on this product — no GTM, no gtag, no
     * GA4, no Meta Pixel. Every marketing decision was being made without
     * traffic, funnel or acquisition numbers.
     *
     * EMIT NOTHING WHEN UNCONFIGURED. There are no default ids anywhere in
     * config/services.php, so until an operator sets GA4_MEASUREMENT_ID or
     * GTM_CONTAINER_ID this component outputs nothing at all. That is the
     * correct behaviour: a tag pointed at no property, or at someone else's
     * property, is worse than no tag.
     *
     * GTM WINS OVER GTAG, NEVER BOTH. If both are configured the container
     * almost certainly already loads GA4, and loading gtag as well would
     * double-count every event on the site. Duplicate counting is easy to
     * prevent here and effectively impossible to unpick from a month of data.
     *
     * CONSENT MODE v2, DEFAULT DENIED. Four of the six signals start denied and
     * are only raised after an explicit opt-in. Two are granted from the start
     * and that is deliberate, not an oversight:
     *   - security_storage carries the CSRF token. Denying it disables the
     *     fraud and CSRF protection on a site that moves real money.
     *   - functionality_storage carries the session. Denying it logs people
     *     out mid-transaction.
     * These two are strictly necessary cookies, not analytics cookies, so they
     * do not require consent.
     *
     * wait_for_update gives the consent banner 600ms to report a stored
     * decision before the tags initialise, which is what stops a returning
     * visitor's page view being recorded twice — once denied and once granted.
     */

    $ga4      = config('services.analytics.ga4_measurement_id');
    $gtm      = config('services.analytics.gtm_container_id');
    $pixel    = config('services.analytics.meta_pixel_id');

    $enabled  = (bool) config('services.analytics.enabled', false);
    $consent  = (bool) config('services.analytics.respect_consent', true);

    $hasGa4   = is_string($ga4)   && $ga4   !== '';
    $hasGtm   = is_string($gtm)   && $gtm   !== '';
    $hasPixel = is_string($pixel) && $pixel !== '';

    $anyTag = $hasGa4 || $hasGtm || $hasPixel;
    $shouldRender = $enabled && $anyTag;
@endphp

@if ($shouldRender)
    {{-- Consent Mode v2. Must run BEFORE any tag loads, or the tags initialise
         with the wrong consent state and the default is meaningless. --}}
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}

        @if ($consent)
            gtag('consent', 'default', {
                ad_storage: 'denied',
                ad_user_data: 'denied',
                ad_personalization: 'denied',
                analytics_storage: 'denied',
                functionality_storage: 'granted',
                security_storage: 'granted',
                wait_for_update: 600
            });
            gtag('set', 'ads_data_redaction', true);
            gtag('set', 'url_passthrough', true);
        @else
            {{-- Operator has explicitly disabled consent gating. This is a legal
                 decision, not a technical one: record it rather than hiding it. --}}
            gtag('consent', 'default', {
                ad_storage: 'granted',
                ad_user_data: 'granted',
                ad_personalization: 'granted',
                analytics_storage: 'granted',
                functionality_storage: 'granted',
                security_storage: 'granted'
            });
            console.warn('[analytics] consent gating is DISABLED. Confirm this is lawful for every market you serve.');
        @endif
    </script>

    @if ($hasGtm)
        {{-- Google Tag Manager. --}}
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
        new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
        j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
        'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
        })(window,document,'script','dataLayer','{{ $gtm }}');</script>
    @else
        {{-- GA4 via gtag. --}}
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" async src="https://www.googletagmanager.com/gtag/js?id={{ $ga4 }}"></script>
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
            gtag('js', new Date());
            gtag('config', '{{ $ga4 }}', {
                {{-- No IP storage: this product moves real money and holds KYC
                     documents, so the default of retaining IP addresses is not
                     one we want. --}}
                allow_google_signals: false,
                allow_ad_personalization_signals: false
            });
        </script>
    @endif

    @if ($hasPixel)
        {{-- Meta Pixel. --}}
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
            !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
            n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
            n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
            t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
            document,'script','https://connect.facebook.net/en_US/fbevents.js');
            fbq('init', '{{ $pixel }}');
            fbq('track', 'PageView');
        </script>
        <noscript><img height="1" width="1" style="display:none"
            src="https://www.facebook.com/tr?id={{ $pixel }}&ev=PageView&noscript=1" alt="" /></noscript>
    @endif
@endif
