@php
    /** @var \App\Services\Support\LiveChatService $chatService */
    $chatService = app(\App\Services\Support\LiveChatService::class);
    $chat = $chatService->viewConfig(auth()->user(), request()->path());
@endphp

@if ($chat['enabled'])
    <div id="tl-live-chat-root"
         data-live-chat
         data-provider="{{ $chat['provider'] }}"
         data-widget-id="{{ $chat['widget_id'] }}"
         data-require-consent="{{ $chat['require_consent'] ? '1' : '0' }}"
         data-suppress-proactive="{{ $chat['suppress_proactive'] ? '1' : '0' }}"
         @if ($chat['identity'] !== null)
             data-user-id="{{ $chat['identity']['user_id'] }}"
             data-user-email="{{ $chat['identity']['email'] }}"
             data-user-hmac="{{ $chat['identity']['hmac'] }}"
         @endif
         class="fixed bottom-5 right-5 z-40 print:hidden">
        <button type="button"
                data-live-chat-trigger
                aria-label="{{ __('conversion.chat_launcher_aria') }}"
                class="inline-flex items-center gap-2 rounded-full border border-emerald-500/40 bg-surface-1 px-4 py-2.5 text-sm font-semibold text-content-strong shadow-lg transition hover:border-emerald-400">
            <span aria-hidden="true" class="inline-block h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
            <span>{{ __('conversion.chat_launcher_label') }}</span>
        </button>
    </div>

    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        (function () {
            const root = document.getElementById('tl-live-chat-root');
            if (!root) return;

            let booted = false;
            const provider = root.dataset.provider || '';
            const widgetId = root.dataset.widgetId || '';
            const requireConsent = root.dataset.requireConsent === '1';
            const userId = root.dataset.userId || '';
            const userEmail = root.dataset.userEmail || '';
            const userHmac = root.dataset.userHmac || '';
            const trigger = root.querySelector('[data-live-chat-trigger]');

            function bootProvider(openImmediately) {
                if (booted || !widgetId) return;
                booted = true;

                if (provider === 'crisp') {
                    window.$crisp = window.$crisp || [];
                    window.CRISP_WEBSITE_ID = widgetId;
                    if (userEmail && userHmac) {
                        window.$crisp.push(['set', 'user:email', [userEmail, userHmac]]);
                    }
                    if (openImmediately) {
                        window.$crisp.push(['do', 'chat:open']);
                    }
                    const s = document.createElement('script');
                    s.src = 'https://client.crisp.chat/l.js';
                    s.async = true;
                    document.head.appendChild(s);
                } else if (provider === 'intercom') {
                    window.intercomSettings = Object.assign({
                        app_id: widgetId,
                        hide_default_launcher: false,
                    }, userId && userHmac ? { user_id: userId, user_hash: userHmac, email: userEmail || undefined } : {});
                    const s = document.createElement('script');
                    s.src = 'https://widget.intercom.io/widget/' + encodeURIComponent(widgetId);
                    s.async = true;
                    s.onload = function () {
                        if (openImmediately && typeof window.Intercom === 'function') {
                            window.Intercom('show');
                        }
                    };
                    document.head.appendChild(s);
                } else if (provider === 'tawk') {
                    window.Tawk_API = window.Tawk_API || {};
                    window.Tawk_LoadStart = new Date();
                    if (userEmail && userHmac) {
                        window.Tawk_API.onLoad = function () {
                            window.Tawk_API.setAttributes({ email: userEmail, hash: userHmac }, function () {});
                            if (openImmediately) window.Tawk_API.maximize();
                        };
                    } else if (openImmediately) {
                        window.Tawk_API.onLoad = function () { window.Tawk_API.maximize(); };
                    }
                    const s = document.createElement('script');
                    s.src = 'https://embed.tawk.to/' + widgetId;
                    s.async = true;
                    s.crossOrigin = 'anonymous';
                    document.head.appendChild(s);
                }

                if (trigger) {
                    trigger.style.display = 'none';
                }
            }

            if (trigger) {
                trigger.addEventListener('click', function () {
                    bootProvider(true);
                });
            }

            if (!requireConsent) {
                if ('requestIdleCallback' in window) {
                    window.requestIdleCallback(function () { bootProvider(false); });
                } else {
                    window.addEventListener('load', function () { bootProvider(false); }, { once: true });
                }
            }
        })();
    </script>
@endif
