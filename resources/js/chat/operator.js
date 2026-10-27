/**
 * Operator chat console.
 *
 * ============================================================================
 * SHELL IN BLADE, BEHAVIOUR HERE
 * ============================================================================
 * The blade template renders the static shell (tables, composer, buttons)
 * with server-translated labels; this script fills the dynamic parts and
 * owns every mutation. Keeping the shell server-rendered means an operator
 * with JavaScript blocked still sees the page's structure and its labels in
 * their locale — only the live numbers wait for the fetches.
 *
 * ============================================================================
 * EVERY WRITE GOES THROUGH ONE REQUEST HELPER
 * ============================================================================
 * All mutations share postJSON(): it attaches the CSRF token, decodes the
 * chat refusal envelope, and surfaces the machine code. When the server
 * says chat_already_claimed, the console refreshes the queue instead of
 * showing a stack trace — the race already resolved itself, and the honest
 * UI response is "someone got there first".
 */
(function () {
    'use strict';

    var mount = document.getElementById('tlco-mount');
    if (!mount) { return; }

    var csrf = mount.getAttribute('data-csrf') || '';
    var urls = {
        dashboard: mount.getAttribute('data-dashboard-url'),
        take: mount.getAttribute('data-take-url'),
        canned: mount.getAttribute('data-canned-url'),
        pause: mount.getAttribute('data-pause-url'),
        resume: mount.getAttribute('data-resume-url'),
    };

    // Conversation-scoped URLs are derived from the operator route pattern.
    // /operator/chat/{id}/message etc. — one source of truth for the shape.
    var base = urls.dashboard.replace(/\/dashboard$/, '');

    var state = {
        conversationId: null,
        cursor: 0,
        messages: [],
        pollTimer: null,
        dashboardTimer: null,
        notice: null,
    };

    // ------------------------------------------------------------------ http

    function request(method, url, data) {
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
        };

        if (data !== undefined) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(data);
        }

        return fetch(url, options).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (body) {
                if (!response.ok) {
                    var err = new Error((body && body.message) || ('HTTP ' + response.status));
                    err.code = (body && body.code) || null;
                    err.status = response.status;
                    throw err;
                }
                return body;
            });
        });
    }

    function getJSON(url) { return request('GET', url); }
    function postJSON(url, data) { return request('POST', url, data || {}); }

    function convUrl(id, action) {
        return base + '/' + id + '/' + action;
    }

    // ---------------------------------------------------------------- render

    function setText(id, text) {
        var node = document.getElementById(id);
        if (node) { node.textContent = text; }
    }

    function renderSummary(presence) {
        var host = document.getElementById('tlco-summary');
        if (!host || !presence) { return; }

        host.innerHTML = '';

        var items = [
            ['Online agents', presence.online_agents],
            ['Accepting', presence.agents_accepting],
            ['Waiting', presence.waiting_conversations],
            ['Active', presence.active_conversations],
            ['Away', presence.away_agents],
        ];

        items.forEach(function (item) {
            var card = document.createElement('div');
            card.className = 'tlco__card';

            var number = document.createElement('div');
            number.className = 'text-2xl font-bold text-emerald-200';
            number.textContent = String(item[1]);

            var label = document.createElement('div');
            label.className = 'text-xs text-slate-400';
            label.textContent = item[0];

            card.appendChild(number);
            card.appendChild(label);
            host.appendChild(card);
        });
    }

    function renderWarnings(warnings) {
        var host = document.getElementById('tlco-warnings');
        if (!host) { return; }

        host.innerHTML = '';

        (warnings || []).forEach(function (warning) {
            var li = document.createElement('li');
            li.textContent = warning.detail;
            host.appendChild(li);
        });
    }

    function renderQueue(queue) {
        var host = document.getElementById('tlco-queue');
        if (!host) { return; }

        host.innerHTML = '';

        (queue || []).forEach(function (conversation) {
            var tr = document.createElement('tr');

            var ref = document.createElement('td');
            ref.textContent = conversation.public_reference;

            var visitor = document.createElement('td');
            visitor.textContent = conversation.guest_name || ('#' + conversation.id);

            var priority = document.createElement('td');
            var badge = document.createElement('span');
            badge.className = 'tlco-badge tlco-badge--' + conversation.priority;
            badge.textContent = conversation.priority;
            priority.appendChild(badge);

            var waited = document.createElement('td');
            waited.textContent = waitedLabel(conversation.queued_at);

            var action = document.createElement('td');
            var take = document.createElement('button');
            take.className = 'tlc-btn tlc-btn--ghost';
            take.style.padding = '0.2rem 0.6rem';
            take.textContent = 'Open';
            take.addEventListener('click', function () {
                claimAndOpen(conversation.id);
            });
            action.appendChild(take);

            tr.appendChild(ref);
            tr.appendChild(visitor);
            tr.appendChild(priority);
            tr.appendChild(waited);
            tr.appendChild(action);
            host.appendChild(tr);
        });

        if ((queue || []).length === 0) {
            var empty = document.createElement('tr');
            var td = document.createElement('td');
            td.colSpan = 5;
            td.className = 'text-slate-500 text-sm';
            td.textContent = 'Queue is empty.';
            empty.appendChild(td);
            host.appendChild(empty);
        }
    }

    function renderWorkload(workload) {
        var host = document.getElementById('tlco-workload');
        if (!host) { return; }

        host.innerHTML = '';

        (workload || []).forEach(function (conversation) {
            var tr = document.createElement('tr');

            var ref = document.createElement('td');
            ref.textContent = conversation.public_reference;

            var visitor = document.createElement('td');
            visitor.textContent = conversation.guest_name || ('#' + conversation.id);

            var unread = document.createElement('td');
            unread.textContent = conversation.agent_unread_count > 0
                ? conversation.agent_unread_count + ' unread'
                : '';

            var open = document.createElement('td');
            var button = document.createElement('button');
            button.className = 'tlc-btn';
            button.style.padding = '0.2rem 0.6rem';
            button.textContent = 'View';
            button.addEventListener('click', function () {
                openConversation(conversation.id);
            });
            open.appendChild(button);

            tr.appendChild(ref);
            tr.appendChild(visitor);
            tr.appendChild(unread);
            tr.appendChild(open);
            host.appendChild(tr);
        });

        if ((workload || []).length === 0) {
            var empty = document.createElement('tr');
            var td = document.createElement('td');
            td.colSpan = 4;
            td.className = 'text-slate-500 text-sm';
            td.textContent = 'No active conversations.';
            empty.appendChild(td);
            host.appendChild(empty);
        }
    }

    function waitedLabel(queuedAt) {
        if (!queuedAt) { return '—'; }

        var seconds = Math.max(0, Math.floor((Date.now() - new Date(queuedAt).getTime()) / 1000));

        if (seconds < 60) { return seconds + 's'; }
        if (seconds < 3600) { return Math.floor(seconds / 60) + 'm'; }

        return Math.floor(seconds / 3600) + 'h ' + Math.floor((seconds % 3600) / 60) + 'm';
    }

    function renderThread() {
        var host = document.getElementById('tlco-thread');
        if (!host) { return; }

        host.innerHTML = '';

        state.messages.forEach(function (message) {
            var className = 'tlc-msg ';

            if (message.sender_type === 'visitor') { className += 'tlc-msg--visitor'; }
            else if (message.kind === 'note') { className += 'tlc-msg--agent tlco-note'; }
            else if (message.sender_type === 'system') { className += 'tlc-msg--system'; }
            else { className += 'tlc-msg--agent'; }

            var bubble = document.createElement('div');
            bubble.className = className;

            var who = document.createElement('span');
            who.className = 'tlc-msg__meta';
            who.textContent = (message.sender_display_name || message.sender_type)
                + (message.kind === 'note' ? ' · internal note' : '');

            bubble.appendChild(document.createTextNode(message.body));
            bubble.appendChild(who);
            host.appendChild(bubble);
        });

        requestAnimationFrame(function () { host.scrollTop = host.scrollHeight; });
    }

    function renderCanned(groups) {
        var host = document.getElementById('tlco-canned');
        if (!host) { return; }

        host.innerHTML = '';

        var heading = document.createElement('div');
        heading.className = 'tlco__heading';
        heading.textContent = 'Saved replies';
        host.appendChild(heading);

        Object.keys(groups || {}).forEach(function (category) {
            (groups[category] || []).forEach(function (canned) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'tlc-btn tlc-btn--ghost';
                button.style.display = 'block';
                button.style.marginBottom = '0.3rem';
                button.textContent = canned.title;
                button.addEventListener('click', function () {
                    var textarea = document.getElementById('tlco-reply');
                    if (textarea) {
                        textarea.value = canned.body;
                        textarea.focus();
                    }
                });
                host.appendChild(button);
            });
        });
    }

    // ------------------------------------------------------------------ flow

    function refreshDashboard() {
        getJSON(urls.dashboard).then(function (body) {
            renderSummary(body.presence);
            renderWarnings(body.health_warnings);
            renderQueue(body.queue);
            renderWorkload(body.workload);

            var seconds = body.next_poll_seconds || 5;
            state.dashboardTimer = setTimeout(refreshDashboard, seconds * 1000);
        }).catch(function () {
            // A failed dashboard poll is retried on the normal cadence; the
            // console must never stop watching because one request hiccuped.
            state.dashboardTimer = setTimeout(refreshDashboard, 5000);
        });
    }

    function claimAndOpen(conversationId) {
        postJSON(convUrl(conversationId, 'take')).then(function (body) {
            openConversation(body.conversation.id);
        }).catch(function (err) {
            if (err.code === 'chat_already_claimed') {
                state.notice = 'Another agent took that conversation first.';
            } else {
                state.notice = err.message;
            }
            refreshNow();
        });
    }

    function openConversation(conversationId) {
        state.conversationId = conversationId;
        state.cursor = 0;
        state.messages = [];

        setText('tlco-conv-title', 'Conversation #' + conversationId);
        renderThread();
        pollConversation();
    }

    function pollConversation() {
        if (!state.conversationId) { return; }

        postJSON(convUrl(state.conversationId, 'poll') + '?after=' + state.cursor).then(function (body) {
            var result = body.poll || {};

            (result.messages || []).forEach(function (message) {
                state.messages.push(message);
                state.cursor = Math.max(state.cursor, message.id);
            });

            if (result.next_cursor) { state.cursor = Math.max(state.cursor, result.next_cursor); }

            if (result.conversation && result.conversation.reference) {
                setText('tlco-conv-title',
                    result.conversation.reference + ' — ' + result.conversation.visitor_display_name);
            }

            state.notice = null;
            renderThread();

            if ((result.messages || []).length > 0) {
                postJSON(convUrl(state.conversationId, 'read')).catch(function () { /* receipt is best-effort */ });
            }

            var seconds = result.next_poll_seconds || 5;
            state.pollTimer = setTimeout(pollConversation, seconds * 1000);
        }).catch(function (err) {
            state.notice = err.message;
            state.pollTimer = setTimeout(pollConversation, 5000);
        });
    }

    function sendMessage(kind) {
        if (!state.conversationId) { return; }

        var textarea = document.getElementById('tlco-reply');
        var body = textarea ? textarea.value.trim() : '';

        if (body === '') { return; }

        postJSON(convUrl(state.conversationId, 'message'), { body: body, kind: kind })
            .then(function (response) {
                state.messages.push(response.message);
                state.cursor = Math.max(state.cursor, response.message.id);

                if (textarea) { textarea.value = ''; }

                state.notice = null;
                renderThread();
            })
            .catch(function (err) {
                state.notice = err.code === 'chat_rate_limited'
                    ? 'Slow down — too many actions in a minute.'
                    : err.message;
            });
    }

    function refreshNow() {
        if (state.dashboardTimer) { clearTimeout(state.dashboardTimer); }
        refreshDashboard();
    }

    // ---------------------------------------------------------------- wiring

    var takeButton = document.getElementById('tlco-take');
    if (takeButton) {
        takeButton.addEventListener('click', function () {
            postJSON(urls.take).then(function (body) {
                if (body.status === 'EMPTY_QUEUE') {
                    state.notice = 'The queue is empty — nothing to take.';
                    return;
                }

                openConversation(body.conversation.id);
                refreshNow();
            }).catch(function (err) {
                state.notice = err.message;
            });
        });
    }

    var pauseButton = document.getElementById('tlco-pause');
    if (pauseButton) {
        pauseButton.addEventListener('click', function () {
            postJSON(urls.pause).then(refreshNow).catch(function (err) { state.notice = err.message; });
        });
    }

    var resumeButton = document.getElementById('tlco-resume');
    if (resumeButton) {
        resumeButton.addEventListener('click', function () {
            postJSON(urls.resume).then(refreshNow).catch(function (err) { state.notice = err.message; });
        });
    }

    var sendButton = document.getElementById('tlco-send');
    if (sendButton) {
        sendButton.addEventListener('click', function () { sendMessage('text'); });
    }

    var noteButton = document.getElementById('tlco-note');
    if (noteButton) {
        noteButton.addEventListener('click', function () { sendMessage('note'); });
    }

    var closeButton = document.getElementById('tlco-close');
    if (closeButton) {
        closeButton.addEventListener('click', function () {
            if (!state.conversationId) { return; }

            postJSON(convUrl(state.conversationId, 'close')).then(function () {
                state.notice = 'Conversation closed.';
                state.conversationId = null;
                state.messages = [];
                renderThread();
                refreshNow();
            }).catch(function (err) { state.notice = err.message; });
        });
    }

    var releaseButton = document.getElementById('tlco-release');
    if (releaseButton) {
        releaseButton.addEventListener('click', function () {
            if (!state.conversationId) { return; }

            postJSON(convUrl(state.conversationId, 'release')).then(function () {
                state.notice = 'Released back to the queue.';
                state.conversationId = null;
                state.messages = [];
                renderThread();
                refreshNow();
            }).catch(function (err) { state.notice = err.message; });
        });
    }

    // ------------------------------------------------------------------ boot

    getJSON(urls.canned).then(function (body) {
        renderCanned(body.groups);
    }).catch(function () { /* canned replies are a convenience; degrade quietly */ });

    refreshDashboard();
})();
