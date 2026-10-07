/**
 * Lottery Platform Member Account & System Settings Runtime (Vanilla JS)
 */

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

        // Form 4: Notifications
        var notifForm = document.getElementById('notificationSettingsForm');
        if (notifForm) {
            notifForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var pSms = document.getElementById('settingPrizeSms').checked;
                var pEmail = document.getElementById('settingPrizeEmail').checked;
                var pLine = document.getElementById('settingPrizeLine').checked;
                var broadcast = document.getElementById('settingDrawBroadcast').checked;

                fetch('/api/v1/player/settings/notifications', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ prize_alert_sms: pSms, prize_alert_email: pEmail, prize_alert_line: pLine, draw_broadcast: broadcast })
                })
                .then(function (res) { return res.json(); })
                .then(function (res) { alert(res.message || 'Notification preferences saved.'); });
            });
        }

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
