/**
 * Live Chat visitor widget.
 *
 * ============================================================================
 * DESIGN: ONE MOUNT POINT, EVERYTHING ELSE RENDERED HERE
 * ============================================================================
 * The blade partial emits a single <div id="tlc-mount"> carrying the
 * server-resolved copy as a data-tlc-lang attribute, plus the CSRF meta the
 * platform already carries. All markup is built here, which keeps the widget a
 * single self-contained asset: no half of it lives in a template where it could
 * be styled or scripted against by accident. Nothing in the template is
 * executable, so the page needs no `unsafe-inline` in its script policy.
 *
 * ============================================================================
 * THE CLIENT NEVER SETS ITS OWN POLLING RATE
 * ============================================================================
 * Every poll response carries next_poll_seconds, decided by the server from
 * config. The widget obeys it. During an incident one config change slows
 * every client down; a buggy or hostile client that ignores the hint trips
 * the poll rate limiter instead of silently doubling load.
 *
 * ============================================================================
 * RETRIES ARE IDEMPOTENT
 * ============================================================================
 * Every send carries a client_token (a random id generated per message). If
 * the network dies after the send but before the response, the retry uses
 * the SAME token and the server returns the original message instead of
 * posting a twin. This is the single most common cause of "my message
 * appeared twice" in chat products, and it is designed out rather than
 * documented away.
 */
(function () {
    'use strict';

    var mount = document.getElementById('tlc-mount');
    if (!mount) { return; }

    // Server-resolved copy arrives as a data attribute on the mount, never as an
    // inline <script>: an inline assignment would force `script-src
    // 'unsafe-inline'` on every page rendering this widget, and that one
    // directive defeats most of what a CSP is for. A data attribute is inert, so
    // the policy can stay `script-src 'self'`. Parsed once, here, at mount.
    var lang = (function () {
        try {
            return JSON.parse(mount.getAttribute('data-tlc-lang') || '{}') || {};
        } catch (ignored) {
            // A malformed or absent bag must not break the widget: every lookup
            // below has a hardcoded fallback, and the runtime copy arrives with
            // the availability response anyway.
            return {};
        }
    })();

    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var base = '/chat';

    var state = {
        open: false,
        conversation: null,      // { reference, status, has_agent, visitor_unread }
        messages: [],
        cursor: 0,
        pollTimer: null,
        pollSeconds: 4,
        availability: null,      // availability DTO
        widget: null,            // widget config DTO
        sending: false,
        error: null,
    };

    // ------------------------------------------------------------------ http

    function api(path, options) {
        options = options || {};
        options.headers = Object.assign({
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf,
        }, options.headers || {});
        options.credentials = 'same-origin';

        return fetch(base + path, options).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (body) {
                if (!response.ok) {
                    var err = new Error((body && body.message) || ('HTTP ' + response.status));
                    err.status = response.status;
                    err.code = (body && body.code) || null;
                    throw err;
                }
                return body;
            });
        });
    }

    function postJSON(path, data) {
        return api(path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data || {}),
        });
    }

    function newClientToken() {
        if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
        return 'tok-' + Date.now() + '-' + Math.random().toString(36).slice(2);
    }

    // ------------------------------------------------------------------ dom

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = text; }
        return node;
    }

    function render() {
        mount.innerHTML = '';

        var widget = el('div', 'tlc-widget tlc-widget--' + positionClass());

        if (state.open) {
            widget.appendChild(renderPanel());
        } else {
            widget.appendChild(renderLauncher());
        }

        mount.appendChild(widget);
    }

    function positionClass() {
        var position = state.widget && state.widget.position;
        return position === 'bottom-left' ? 'bottom-left' : 'bottom-right';
    }

    function renderLauncher() {
        var launcher = el('button', 'tlc-launcher');
        launcher.type = 'button';
        launcher.setAttribute('aria-expanded', 'false');

        var dot = el('span', 'tlc-launcher__dot ' + dotClass());
        var label = el('span', null, launcherLabel());

        launcher.appendChild(dot);
        launcher.appendChild(label);

        launcher.addEventListener('click', function () {
            state.open = true;
            render();
            ensureConversationLoaded();
        });

        return launcher;
    }

    function dotClass() {
        var a = state.availability;
        if (!a) { return ''; }
        if (a.state === 'available') { return 'tlc-launcher__dot--available'; }
        if (a.state === 'busy') { return 'tlc-launcher__dot--busy'; }
        return 'tlc-launcher__dot--offline';
    }

    function launcherLabel() {
        var key = (state.widget && state.widget.title_key) || 'chat.widget_title';
        return lang[key] ? lang[key] : 'Live support';
    }

    function renderPanel() {
        var panel = el('div', 'tlc-panel');

        var header = el('div', 'tlc-panel__header');
        header.appendChild(el('span', 'tlc-panel__title', launcherLabel()));

        var close = el('button', 'tlc-panel__close', '×');
        close.type = 'button';
        close.setAttribute('aria-label', 'Close chat');
        close.addEventListener('click', function () {
            state.open = false;
            stopPolling();
            render();
        });
        header.appendChild(close);
        panel.appendChild(header);

        panel.appendChild(el('div', 'tlc-status', statusSentence()));

        if (state.error) {
            panel.appendChild(el('div', 'tlc-error', state.error));
        }

        if (state.conversation) {
            panel.appendChild(renderThread());

            if (state.conversation.status === 'closed') {
                panel.appendChild(el('div', 'tlc-status', closedSentence()));
            } else {
                panel.appendChild(renderComposer());
            }
        } else if (state.availability && state.availability.can_start_conversation) {
            panel.appendChild(renderStartForm());
        }
        // No availability yet, or cannot start: the status strip already says why.

        return panel;
    }

    function statusSentence() {
        var a = state.availability;
        if (!a) { return '…'; }

        var key = a.message_key;
        var text = lang[key] || fallbackFor(key);

        if (a.waiting_conversations > 0) {
            text += ' (' + a.waiting_conversations + ' ahead of you)';
        }

        return text;
    }

    function fallbackFor(key) {
        var fallbacks = {
            'chat.availability_available': 'An agent is online and can help you now.',
            'chat.availability_busy': 'All agents are busy right now. You can still write — you will join the queue.',
            'chat.availability_outside_hours': 'Live chat is closed at the moment. Please use the contact form.',
            'chat.availability_outside_hours_notice': 'Live chat is closed at the moment. Please use the contact form.',
            'chat.availability_offline': 'No agent is online right now. Please use the contact form.',
            'chat.availability_disabled': 'Live chat is not available on this site.',
            'chat.widget_title': 'Live support',
            'chat.widget_greeting': 'Hello! How can we help you today?',
            'chat.composer_placeholder': 'Type your message…',
            'chat.closed_message': 'This conversation has ended. Start a new one if you need more help.',
        };

        return fallbacks[key] || '';
    }

    function closedSentence() {
        return fallbackFor('chat.closed_message');
    }

    function renderThread() {
        var thread = el('div', 'tlc-thread');

        state.messages.forEach(function (message) {
            var who = message.sender_type === 'visitor'
                ? 'tlc-msg--visitor'
                : (message.sender_type === 'system' ? 'tlc-msg--system' : 'tlc-msg--agent');

            var bubble = el('div', 'tlc-msg ' + who);
            bubble.appendChild(document.createTextNode(message.body));

            if (message.sender_type !== 'system') {
                var metaText = message.sender_display_name || '';
                if (message.created_at) {
                    metaText += (metaText ? ' · ' : '') + new Date(message.created_at).toLocaleTimeString([], {
                        hour: '2-digit', minute: '2-digit',
                    });
                }
                if (metaText) {
                    bubble.appendChild(el('span', 'tlc-msg__meta', metaText));
                }
            }

            thread.appendChild(bubble);
        });

        // Keep the newest line visible without fighting an upward scroll.
        requestAnimationFrame(function () { thread.scrollTop = thread.scrollHeight; });

        return thread;
    }

    function renderStartForm() {
        var form = el('form', 'tlc-start');

        var nameLabel = el('label', null, 'Your name');
        var nameInput = el('input', 'tlc-input');
        nameInput.name = 'guest_name';
        nameInput.required = true;
        nameInput.maxLength = 60;

        var emailLabel = el('label', null, 'Email (optional — for the transcript)');
        var emailInput = el('input', 'tlc-input');
        emailInput.name = 'guest_email';
        emailInput.type = 'email';
        emailInput.maxLength = 190;

        var button = el('button', 'tlc-btn', 'Start chat');
        button.type = 'submit';

        form.appendChild(nameLabel);
        form.appendChild(nameInput);
        form.appendChild(emailLabel);
        form.appendChild(emailInput);
        form.appendChild(button);

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            postJSON('/start', {
                guest_name: nameInput.value,
                guest_email: emailInput.value || null,
                meta: { page_url: window.location.pathname },
            }).then(function (body) {
                state.conversation = body.conversation;
                state.error = null;
                render();
                startPolling();
            }).catch(function (err) {
                state.error = err.message;
                render();
            });
        });

        return form;
    }

    function renderComposer() {
        var composer = el('div', 'tlc-composer');

        var textarea = document.createElement('textarea');
        textarea.placeholder = fallbackFor('chat.composer_placeholder');
        textarea.maxLength = 2000;
        textarea.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                send();
            }
        });

        var button = el('button', 'tlc-btn', 'Send');
        button.type = 'button';
        button.disabled = state.sending;
        button.addEventListener('click', send);

        composer.appendChild(textarea);
        composer.appendChild(button);

        function send() {
            var body = textarea.value.trim();
            if (body === '' || state.sending) { return; }

            state.sending = true;
            button.disabled = true;

            postJSON('/conversation/messages', {
                body: body,
                client_token: newClientToken(),
            }).then(function (response) {
                state.messages.push(response.message);
                state.cursor = Math.max(state.cursor, response.message.id);
                textarea.value = '';
                state.error = null;
                state.sending = false;
                render();
            }).catch(function (err) {
                state.sending = false;

                if (err.code === 'chat_rate_limited') {
                    state.error = 'You are sending too quickly. Please wait a moment.';
                } else {
                    state.error = err.message;
                }

                render();
            });
        }

        return composer;
    }

    // ---------------------------------------------------------------- flow

    function ensureConversationLoaded() {
        api('/conversation').then(function (body) {
            state.conversation = body.conversation;
            render();
            startPolling();
        }).catch(function () {
            // 404 = no conversation yet. The start form (or the honest
            // unavailable strip) is already what render() shows.
            state.conversation = null;
            render();
        });
    }

    function startPolling() {
        stopPolling();
        poll();
    }

    function stopPolling() {
        if (state.pollTimer) {
            clearTimeout(state.pollTimer);
            state.pollTimer = null;
        }
    }

    function poll() {
        state.pollTimer = null;

        if (!state.open || !state.conversation) { return; }

        api('/conversation/poll?after=' + state.cursor).then(function (body) {
            var result = body.poll || {};

            (result.messages || []).forEach(function (message) {
                state.messages.push(message);
                state.cursor = Math.max(state.cursor, message.id);
            });

            if (result.next_cursor) { state.cursor = Math.max(state.cursor, result.next_cursor); }
            if (result.status) { state.conversation.status = result.status; }
            state.conversation.has_agent = !!result.has_agent;

            state.pollSeconds = result.next_poll_seconds || state.pollSeconds;
            state.error = null;

            render();

            // Reading a poll means the visitor saw it: fire the receipt.
            if ((result.messages || []).length > 0) {
                postJSON('/conversation/read', {}).catch(function () { /* receipt is best-effort */ });
            }

            scheduleNext();
        }).catch(function (err) {
            // A closed conversation refuses nothing here (poll is allowed on
            // closed); a 404 means the identity vanished — show the start
            // form again rather than spinning forever.
            if (err.status === 404) {
                state.conversation = null;
                render();
                return;
            }

            state.error = err.message;
            render();
            scheduleNext();
        });
    }

    function scheduleNext() {
        if (!state.open || !state.conversation) { return; }

        var seconds = Math.max(2, state.pollSeconds || 4);
        state.pollTimer = setTimeout(poll, seconds * 1000);
    }

    // ---------------------------------------------------------------- boot

    function boot() {
        Promise.all([
            api('/widget').catch(function () { return { enabled: false }; }),
            api('/availability').catch(function () { return null; }),
        ]).then(function (results) {
            state.widget = results[0];
            state.availability = results[1];

            if (!state.widget || !state.widget.enabled) { return; }

            render();

            // If this browser already owns an open conversation, pick it up
            // silently so a page refresh resumes the chat instead of
            // offering to start a new one.
            api('/conversation').then(function (body) {
                state.conversation = body.conversation;
                render();
                startPolling();
            }).catch(function () { /* no conversation yet — that is fine */ });
        });
    }

    // Slow down politely while the tab is hidden: keep the cadence, but do
    // not burn requests nobody is watching.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stopPolling();
        } else if (state.open && state.conversation) {
            startPolling();
        }
    });

    boot();
})();
