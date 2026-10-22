{{--
    Live Chat widget mount point.

    WHY THIS PARTIAL IS ALMOST NOTHING
    The widget renders itself from JavaScript (resources/js/chat/widget.js);
    this partial provides only the mount div and the translation strings the
    widget shows before its first fetch returns. Keeping the copy here means
    the first paint speaks the visitor's language instead of English
    fallbacks, while the runtime copy still comes from the resolved
    availability state.

    NOTHING HERE IS EXECUTABLE
    The copy is handed to the script as a data attribute on the mount div, not
    as an inline <script>. This partial is included from layouts/app.blade.php,
    so any inline code it emitted would ship on every page and would force
    `script-src 'unsafe-inline'` into a Content-Security-Policy sitewide.

    SHOW/HIDE RULE
    config('chat.widget.show_on') decides per page class; the master switch
    config('chat.enabled') can remove the whole feature. The check lives in
    the template (not the middleware) because the widget is chrome on pages
    that many controllers render — one include, one rule, no controller
    changes.
--}}
@php
    $chatShowOn = config('chat.widget.show_on', []);
    $chatOnAuthPage = request()->routeIs('login*', 'register*', 'password.*', 'auth.*');
    $chatSection = $chatOnAuthPage ? 'auth' : (request()->routeIs('player.*', 'wallet.*', 'dashboard*') ? 'player' : 'public');
    $chatVisible = (bool) config('chat.enabled', true) && (bool) ($chatShowOn[$chatSection] ?? false);

    // Availability copy, resolved server-side so the widget's first paint
    // never shows raw keys or English over a Thai locale. Built in @php
    // (rather than inline in @json) so the directive receives a plain
    // variable — multi-line expressions inside a directive are a known
    // Blade parser hazard.
    $chatLang = [
        'chat.widget_title' => __('chat.widget_title'),
        'chat.widget_greeting' => __('chat.widget_greeting'),
        'chat.widget_offline' => __('chat.widget_offline'),
        'chat.composer_placeholder' => __('chat.composer_placeholder'),
        'chat.availability_available' => __('chat.availability_available'),
        'chat.availability_busy' => __('chat.availability_busy'),
        'chat.availability_outside_hours' => __('chat.availability_outside_hours'),
        'chat.availability_outside_hours_notice' => __('chat.availability_outside_hours_notice'),
        'chat.availability_offline' => __('chat.availability_offline'),
        'chat.availability_disabled' => __('chat.availability_disabled'),
        'chat.closed_message' => __('chat.closed_message'),
    ];
@endphp

@if($chatVisible)
    {{-- The widget's CSS and JS ship from the layout's @vite array (they
         must be in <head>/before the stacks flush, and this partial is
         included after them). The JS no-ops when #tlc-mount is absent, so
         pages where the widget is hidden pay nothing beyond the fetch. --}}
    {{-- WHY THE COPY TRAVELS AS AN ATTRIBUTE AND NOT AS AN INLINE SCRIPT
         This partial used to emit `<script>window.tlcLang = @json(...)</script>`.
         Because layouts/app.blade.php includes it, that put executable inline
         code on every page in the application, and an inline script forces any
         Content-Security-Policy to carry `script-src 'unsafe-inline'` - the one
         directive that defeats most of what a CSP is for (see the same reasoning
         recorded in resources/js/layout/mobile-menu.js, which was moved out of
         the layout for exactly this cause). A data attribute is inert: the
         browser never executes it, so the policy can stay `script-src 'self'`.
         widget.js parses it once at mount. --}}
    <div id="tlc-mount" aria-live="polite"
         data-tlc-lang="{{ json_encode($chatLang, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}"></div>
@endif
