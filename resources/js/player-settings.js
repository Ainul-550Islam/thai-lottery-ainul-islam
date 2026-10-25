/**
 * Lottery Platform Member Account & System Settings Runtime (Vanilla JS)
 *
 * Batch 08 adds the notification-preferences matrix to this page. The general,
 * security, betting, LINE, 2FA, responsible-gaming and self-exclusion controls
 * below are deliberately unchanged: their backends are owned by other batches
 * and this file must not guess at their contracts.
 */

import {
    apiRequest,
    announce,
    isNotConfigured,
    readL10n,
    serverMessage,
    setText,
    text,
} from './frontend-api.js';

(function () {
    'use strict';

    function initSettings() {
        var tokenMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = tokenMeta ? tokenMeta.getAttribute('content') : '';

        // Tab switching
        document.querySelectorAll('[data-st-tab]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = btn.getAttribute('data-st-tab') || 'general';
                document.querySelectorAll('[data-st-tab]').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');

                ['general', 'security', 'betting', 'notifications', 'responsible'].forEach(function (sec) {
                    var el = document.getElementById('section-' + sec);
                    if (el) el.style.display = (sec === target) ? 'flex' : 'none';
                });
            });
        });

        // Form 1: General
        var generalForm = document.getElementById('generalSettingsForm');
        if (generalForm) {
            generalForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var lang = document.getElementById('settingLanguage').value;
                var theme = document.getElementById('settingTheme').value;
                var tz = document.getElementById('settingTimezone').value;
                var odds = document.getElementById('settingOddsFormat').value;

                fetch('/api/v1/player/settings/general', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ language: lang, theme: theme, timezone: tz, odds_format: odds })
                })
                .then(function (res) { return res.json(); })
                .then(function (res) { alert(res.message || 'General settings saved.'); });
            });
        }

        // Form 2: Security
        var secForm = document.getElementById('securitySettingsForm');
        if (secForm) {
            secForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var timeout = parseInt(document.getElementById('settingSessionTimeout').value, 10);
                var emailAlert = document.getElementById('settingLoginEmail').checked;
                var lineAlert = document.getElementById('settingLoginLine').checked;
                var bioLogin = document.getElementById('settingBiometric').checked;

                fetch('/api/v1/player/settings/security', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ session_timeout: timeout, login_alert_email: emailAlert, login_alert_line: lineAlert, biometric_login: bioLogin })
                })
                .then(function (res) { return res.json(); })
                .then(function (res) { alert(res.message || 'Security settings saved.'); });
            });
        }

        // Form 3: Betting
        var betForm = document.getElementById('bettingSettingsForm');
        if (betForm) {
            betForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var fastBet = document.getElementById('settingFastBet').checked;
                var sound = document.getElementById('settingSounds').checked;
                var perm = document.getElementById('settingPermutation').value;
                var autoClear = document.getElementById('settingAutoClear').checked;

                fetch('/api/v1/player/settings/betting', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ fast_bet_mode: fastBet, sound_effects: sound, default_permutation_mode: perm, auto_clear_bet_slip: autoClear })
                })
                .then(function (res) { return res.json(); })
                .then(function (res) { alert(res.message || 'Betting preferences saved.'); });
            });
        }

        // Form 4: Notifications — the closed event-type × channel matrix.
        // Legacy boolean flags were never persisted by the merged backend; the
        // canonical write is POST /api/v1/player/settings/notifications with a
        // preferences[] array drawn from the published enums.
        initNotificationMatrix();

        // LINE token form
        var lineForm = document.getElementById('lineNotifyForm');
        if (lineForm) {
            lineForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var tokenInput = document.getElementById('inputLineToken');
                var val = tokenInput ? tokenInput.value.trim() : '';
                if (!val) { alert('Please enter token.'); return; }

                fetch('/api/v1/player/settings/line-notify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ line_token: val })
                })
                .then(function (res) { return res.json(); })
                .then(function (res) {
                    alert(res.message || 'LINE Connected.');
                    if (tokenInput) tokenInput.value = '';
                });
            });
        }

        // Form 5: Responsible limits
        var respForm = document.getElementById('responsibleLimitsForm');
        if (respForm) {
            respForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var dep = document.getElementById('inputDailyDeposit').value.trim();
                var bet = document.getElementById('inputSingleBet').value.trim();
                var wag = document.getElementById('inputDailyWager').value.trim();
                var loss = document.getElementById('inputDailyLoss').value.trim();
                var decimal = /^\d+(\.\d{1,2})?$/;

                if (![dep, bet, wag, loss].every(function (value) { return decimal.test(value); })) {
                    alert('Enter each spending limit as a decimal amount with at most two decimal places.');
                    return;
                }

                fetch('/api/v1/player/settings/limits', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ daily_deposit_limit: dep, single_bet_limit: bet, daily_wager_limit: wag, daily_loss_limit: loss })
                })
                .then(function (response) {
                    return response.json().then(function (data) {
                        if (!response.ok) throw new Error(data.message || 'Limits could not be updated.');
                        return data;
                    });
                })
                .then(function (data) { alert(data.message || 'Responsible gaming limits were accepted.'); })
                .catch(function (error) { alert(error.message || 'Limits could not be updated.'); });
            });
        }

        /**
         * Notification-preferences matrix.
         *
         * The published event-type and channel vocabularies come from the
         * Blade view (PHP enums), never from a free-text input. Mandatory rows
         * are locked in the ON position and carry a visible tooltip plus an
         * aria-describedby explanation. Toggles are optimistic: the checkbox
         * moves immediately, the write is debounced, and any error rolls the
         * whole batch back to the last server-confirmed state.
         */
        function initNotificationMatrix() {
            var root = document.getElementById('notification-preferences');
            if (!root) {
                return;
            }

            var l10n = readL10n('player-settings-l10n', {
                title: 'Notification preferences',
                lead: 'Choose which channels deliver each type of account notice.',
                eventColumn: 'Event',
                on: 'On',
                off: 'Off',
                mandatoryHelp: 'Security, compliance and withdrawal-result notifications cannot be switched off.',
                mandatory: 'Always delivered',
                loading: 'Loading notification preferences…',
                saving: 'Saving notification preferences…',
                saved: 'Notification preferences saved.',
                failed: 'Notification preferences could not be saved. Your previous settings were restored.',
                rollback: 'Your previous settings were restored.',
                notConfigured: 'Notification preferences are not available yet.',
                authenticationRequired: 'Sign in to manage notification preferences.',
                networkError: 'The request could not be completed. Check your connection and try again.',
                validationError: 'That preference is not published on this platform.',
                channel_in_app: 'In-app',
                channel_sms: 'SMS',
                channel_email: 'Email',
                channel_push: 'Push',
            });

            var updateUrl = root.getAttribute('data-update-url') || '';
            var readUrl = root.getAttribute('data-read-url') || '';
            var grid = root.querySelector('[data-prefs-grid]');
            var status = root.querySelector('[data-prefs-status]');
            var help = root.querySelector('[data-prefs-mandatory-help]');

            function parseList(attribute) {
                try {
                    var parsed = JSON.parse(root.getAttribute(attribute) || '[]');
                    return Array.isArray(parsed) ? parsed.map(String) : [];
                } catch (error) {
                    return [];
                }
            }

            var events = parseList('data-events');
            var channels = parseList('data-channels');
            var mandatory = parseList('data-mandatory');
            var checkboxes = {};
            var confirmed = {};
            var pending = {};
            var timer = null;
            var busy = false;
            var loaded = false;

            function eventLabel(eventType) {
                var key = 'event_' + eventType;
                if (Object.prototype.hasOwnProperty.call(l10n, key) && l10n[key]) {
                    return l10n[key];
                }
                return eventType.replace(/_/g, ' ').replace(/^./, function (character) {
                    return character.toUpperCase();
                });
            }

            function channelLabel(channel) {
                var key = 'channel_' + channel;
                return Object.prototype.hasOwnProperty.call(l10n, key) ? l10n[key] : channel;
            }

            function isMandatory(eventType) {
                return mandatory.indexOf(eventType) !== -1;
            }

            function setAllDisabled(disabled) {
                Object.keys(checkboxes).forEach(function (key) {
                    var box = checkboxes[key];
                    box.disabled = disabled || isMandatory(box.getAttribute('data-event-type'));
                });
            }

            function buildGrid() {
                if (!grid || events.length === 0 || channels.length === 0) {
                    announce(status, text(l10n, 'notConfigured'));
                    return false;
                }

                var table = document.createElement('table');
                table.className = 'tl-prefs-table';
                var caption = document.createElement('caption');
                caption.className = 'tl-sr-only';
                caption.textContent = text(l10n, 'title');
                table.appendChild(caption);

                var thead = document.createElement('thead');
                var headRow = document.createElement('tr');
                var corner = document.createElement('th');
                corner.scope = 'col';
                corner.textContent = text(l10n, 'eventColumn');
                headRow.appendChild(corner);

                channels.forEach(function (channel) {
                    var th = document.createElement('th');
                    th.scope = 'col';
                    th.textContent = channelLabel(channel);
                    headRow.appendChild(th);
                });
                thead.appendChild(headRow);
                table.appendChild(thead);

                var tbody = document.createElement('tbody');
                events.forEach(function (eventType) {
                    var row = document.createElement('tr');
                    row.setAttribute('data-prefs-row', eventType);
                    if (isMandatory(eventType)) {
                        row.setAttribute('data-mandatory', 'true');
                    }

                    var heading = document.createElement('th');
                    heading.scope = 'row';
                    heading.textContent = eventLabel(eventType);

                    if (isMandatory(eventType)) {
                        var badge = document.createElement('span');
                        badge.className = 'tl-prefs-badge';
                        badge.textContent = text(l10n, 'mandatory');
                        badge.setAttribute('tabindex', '0');
                        badge.setAttribute('role', 'note');
                        if (help) {
                            var helpId = help.id || 'prefs-mandatory-help';
                            help.id = helpId;
                            badge.setAttribute('aria-describedby', helpId);
                        }
                        heading.appendChild(document.createTextNode(' '));
                        heading.appendChild(badge);
                    }
                    row.appendChild(heading);

                    channels.forEach(function (channel) {
                        var cell = document.createElement('td');
                        var label = document.createElement('label');
                        label.className = 'tl-prefs-toggle';
                        var box = document.createElement('input');
                        box.type = 'checkbox';
                        box.setAttribute('data-event-type', eventType);
                        box.setAttribute('data-channel', channel);
                        box.setAttribute('aria-label', eventLabel(eventType) + ' — ' + channelLabel(channel));
                        box.disabled = true;
                        box.checked = isMandatory(eventType);
                        var state = document.createElement('span');
                        state.className = 'tl-prefs-toggle-state';
                        state.setAttribute('aria-hidden', 'true');
                        state.textContent = box.checked ? text(l10n, 'on') : text(l10n, 'off');
                        label.appendChild(box);
                        label.appendChild(state);
                        cell.appendChild(label);
                        row.appendChild(cell);

                        var key = eventType + '|' + channel;
                        checkboxes[key] = box;
                        confirmed[key] = box.checked;

                        box.addEventListener('change', function () {
                            onToggle(eventType, channel, key);
                        });
                    });

                    tbody.appendChild(row);
                });
                table.appendChild(tbody);
                grid.appendChild(table);

                return true;
            }

            function applyRows(rows) {
                if (!Array.isArray(rows)) {
                    return;
                }

                rows.forEach(function (row) {
                    if (!row || typeof row !== 'object') {
                        return;
                    }
                    var key = String(row.event_type) + '|' + String(row.channel);
                    var box = checkboxes[key];
                    if (!box) {
                        return;
                    }
                    var enabled = isMandatory(String(row.event_type)) ? true : Boolean(row.enabled);
                    box.checked = enabled;
                    confirmed[key] = enabled;
                    var state = box.parentNode.querySelector('.tl-prefs-toggle-state');
                    if (state) {
                        state.textContent = enabled ? text(l10n, 'on') : text(l10n, 'off');
                    }
                });
            }

            function rollback() {
                Object.keys(pending).forEach(function (key) {
                    var box = checkboxes[key];
                    if (!box) {
                        return;
                    }
                    box.checked = Boolean(confirmed[key]);
                    var state = box.parentNode.querySelector('.tl-prefs-toggle-state');
                    if (state) {
                        state.textContent = box.checked ? text(l10n, 'on') : text(l10n, 'off');
                    }
                });
                pending = {};
            }

            function onToggle(eventType, channel, key) {
                if (!loaded || isMandatory(eventType)) {
                    return;
                }

                if (!Object.prototype.hasOwnProperty.call(pending, key)) {
                    pending[key] = Boolean(confirmed[key]);
                }

                var state = checkboxes[key].parentNode.querySelector('.tl-prefs-toggle-state');
                if (state) {
                    state.textContent = checkboxes[key].checked ? text(l10n, 'on') : text(l10n, 'off');
                }

                announce(status, text(l10n, 'saving'));
                if (timer !== null) {
                    clearTimeout(timer);
                }
                timer = setTimeout(flush, 300);
            }

            async function flush() {
                timer = null;
                if (busy || updateUrl === '' || Object.keys(pending).length === 0) {
                    return;
                }

                var changes = Object.keys(pending).map(function (key) {
                    var parts = key.split('|');
                    return {
                        event_type: parts[0],
                        channel: parts[1],
                        enabled: Boolean(checkboxes[key].checked),
                    };
                });

                if (changes.length === 0) {
                    pending = {};
                    return;
                }

                busy = true;
                setAllDisabled(true);

                var response = await apiRequest(updateUrl, {
                    method: 'POST',
                    body: { preferences: changes },
                });

                busy = false;

                if (isNotConfigured(response) || (response.data && response.data.status === 'NOT_CONFIGURED')) {
                    rollback();
                    setAllDisabled(true);
                    loaded = false;
                    announce(status, text(l10n, 'notConfigured'));
                    return;
                }

                if (response.status === 401) {
                    rollback();
                    setAllDisabled(true);
                    loaded = false;
                    announce(status, text(l10n, 'authenticationRequired'));
                    return;
                }

                if (!response.ok || !response.data || response.data.success === false) {
                    rollback();
                    var message = serverMessage(response) || text(l10n, response.status === 0 ? 'networkError' : 'failed');
                    announce(status, message + ' ' + text(l10n, 'rollback'));
                    setAllDisabled(false);
                    return;
                }

                pending = {};
                var rows = response.data.data && response.data.data.preferences
                    ? response.data.data.preferences
                    : [];
                if (rows.length > 0) {
                    applyRows(rows);
                } else {
                    changes.forEach(function (change) {
                        var key = change.event_type + '|' + change.channel;
                        confirmed[key] = change.enabled;
                    });
                }
                setAllDisabled(false);
                announce(status, text(l10n, 'saved'));
            }

            async function load() {
                announce(status, text(l10n, 'loading'));

                if (readUrl === '') {
                    loaded = true;
                    setAllDisabled(false);
                    announce(status, text(l10n, 'saved'));
                    return;
                }

                var response = await apiRequest(readUrl, { method: 'GET' });

                if (isNotConfigured(response) || (response.data && response.data.state === 'NOT_CONFIGURED')) {
                    announce(status, text(l10n, 'notConfigured'));
                    return;
                }

                if (response.status === 401) {
                    announce(status, text(l10n, 'authenticationRequired'));
                    return;
                }

                if (!response.ok || !response.data || response.data.success !== true) {
                    // The write endpoint is still authoritative: keep the
                    // published default state editable but say plainly that the
                    // saved state could not be read rather than faking a load.
                    loaded = true;
                    setAllDisabled(false);
                    announce(status, text(l10n, response.status === 0 ? 'networkError' : 'failed'));
                    return;
                }

                var data = response.data.data || {};
                var rows = Array.isArray(data.preferences) ? data.preferences : [];
                applyRows(rows);
                loaded = true;
                setAllDisabled(false);
                announce(status, text(l10n, 'saved'));
            }

            if (help) {
                setText(help, text(l10n, 'mandatoryHelp'));
            }

            if (buildGrid()) {
                load();
            }
        }

        window.playerSettings = {
            toggle2fa: function (enable) {
                fetch('/api/v1/player/settings/2fa', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ enable: enable })
                })
                .then(function (res) { return res.json(); })
                .then(function (res) {
                    if (res.requires_verification) {
                        var code = prompt(res.message + '\nSecret: ' + res.secret_key + '\nEnter 6-digit code:');
                        if (code) {
                            fetch('/api/v1/player/settings/2fa', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                                body: JSON.stringify({ enable: true, code: code })
                            })
                            .then(function (r) { return r.json(); })
                            .then(function (r) { alert(r.message || '2FA setup done.'); });
                        }
                    } else {
                        alert(res.message || '2FA status updated.');
                    }
                });
            },
            applySelfExclusion: function (duration) {
                if (!confirm('Activate ' + duration + ' exclusion break? You will be logged out.')) return;
                fetch('/api/v1/player/settings/self-exclusion', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ duration: duration })
                })
                .then(function (res) { return res.json(); })
                .then(function (res) {
                    alert(res.message || 'Exclusion activated.');
                    window.location.href = '/login';
                });
            }
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSettings);
    } else {
        initSettings();
    }
})();
