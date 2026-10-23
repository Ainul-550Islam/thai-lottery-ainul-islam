/**
 * Lottery Bet Slip & Same-Origin Session JSON Transport
 *
 * Enforces CSPRNG 192-bit client idempotency keys, fail-closed sessionStorage
 * pending-slip journaling for safe same-tab retry without duplicate deduction,
 * exact BigInt satang & 10^8 scaled payout arithmetic, leading-zero preservation,
 * itemized partial/refused outcome reporting, and sticky betting-closed state.
 */
(function () {
    'use strict';

    var MAX_STAKE_SATANG = 99999999999999n;
    var MULTIPLIER_SCALE = 100000000n;
    var CLIENT_KEY_PATTERN = /^slip-[a-f0-9]{48}$/;

    function parseSatang(raw) {
        var trimmed = String(raw || '').trim();
        var match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(trimmed);
        if (!match) {
            return null;
        }
        var whole = BigInt(match[1]);
        var frac = BigInt((match[2] || '').padEnd(2, '0'));
        var total = whole * 100n + frac;
        if (total <= 0n || total > MAX_STAKE_SATANG) {
            return null;
        }
        return total;
    }

    function formatSatangPlain(satang) {
        var safe = satang < 0n ? 0n : satang;
        var whole = (safe / 100n).toString();
        var frac = (safe % 100n).toString().padStart(2, '0');
        return whole + '.' + frac;
    }

    function formatSatangDisplay(satang) {
        var safe = satang < 0n ? 0n : satang;
        var whole = (safe / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        var frac = (safe % 100n).toString().padStart(2, '0');
        return whole + '.' + frac;
    }

    function parseMultiplierScaled(raw) {
        var trimmed = String(raw === undefined || raw === null ? '0' : raw).trim();
        var match = /^(\d+)(?:\.(\d{1,8}))?$/.exec(trimmed);
        if (!match) {
            return 0n;
        }
        var whole = BigInt(match[1]);
        var frac = BigInt((match[2] || '').padEnd(8, '0'));
        return whole * MULTIPLIER_SCALE + frac;
    }

    function computePayoutSatang(stakeSatang, multiplierScaled) {
        if (stakeSatang <= 0n || multiplierScaled <= 0n) {
            return 0n;
        }
        return (stakeSatang * multiplierScaled) / MULTIPLIER_SCALE;
    }

    function generateCsprngClientKey() {
        if (!window.crypto || typeof window.crypto.getRandomValues !== 'function') {
            return null;
        }
        var bytes = new Uint8Array(24);
        window.crypto.getRandomValues(bytes);
        var hex = '';
        for (var i = 0; i < bytes.length; i++) {
            hex += bytes[i].toString(16).padStart(2, '0');
        }
        return 'slip-' + hex;
    }

    function clearElement(node) {
        while (node && node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    function resolveCsrfToken(form) {
        if (form) {
            var tokenInput = form.querySelector('input[name="_token"]');
            if (tokenInput && tokenInput.value) {
                return String(tokenInput.value).trim();
            }
        }
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.getAttribute('content')) {
            return String(meta.getAttribute('content')).trim();
        }
        return '';
    }

    function validateRecoveredJournal(parsed, expectedDrawId) {
        if (!parsed || typeof parsed !== 'object') {
            return false;
        }
        if (typeof parsed.body !== 'string' || parsed.body.length < 10) {
            return false;
        }
        var payload;
        try {
            payload = JSON.parse(parsed.body);
        } catch (err) {
            return false;
        }
        if (!payload || typeof payload !== 'object') {
            return false;
        }
        if (!CLIENT_KEY_PATTERN.test(String(payload.client_key || ''))) {
            return false;
        }
        if (expectedDrawId !== null && payload.draw_id !== expectedDrawId) {
            return false;
        }
        if (!Array.isArray(payload.items) || payload.items.length === 0) {
            return false;
        }
        for (var i = 0; i < payload.items.length; i++) {
            var it = payload.items[i];
            if (
                !it ||
                typeof it.market !== 'string' ||
                it.market === '' ||
                typeof it.number !== 'string' ||
                !/^\d+$/.test(it.number) ||
                parseSatang(it.stake) === null
            ) {
                return false;
            }
        }
        return true;
    }

    function isValidReportEnvelope(body, expectedCount) {
        if (!body || typeof body !== 'object' || body.success !== true) {
            return false;
        }
        if (!body.data || typeof body.data !== 'object' || !body.data.report || typeof body.data.report !== 'object') {
            return false;
        }
        var report = body.data.report;
        if (
            typeof report.purchased !== 'number' ||
            typeof report.replayed !== 'number' ||
            typeof report.refused !== 'number'
        ) {
            return false;
        }
        var charged = report.total_charged !== undefined ? report.total_charged : report.newly_charged_stake;
        if (typeof charged !== 'string') {
            return false;
        }
        if (!Array.isArray(report.items) || report.items.length !== expectedCount) {
            return false;
        }
        return true;
    }

    function formatReportSummary(report) {
        var currency = typeof report.currency === 'string' && report.currency !== '' ? report.currency : 'THB';
        var charged = report.total_charged !== undefined ? report.total_charged : report.newly_charged_stake;
        var summary =
            'Purchased: ' + report.purchased +
            ', replayed: ' + report.replayed +
            ', refused: ' + report.refused +
            '. Newly charged: ' + charged + ' ' + currency + '.';

        var details = [];
        for (var i = 0; i < report.items.length; i++) {
            var entry = report.items[i];
            if (entry && entry.outcome === 'refused') {
                var num = entry.selection && typeof entry.selection.number === 'string'
                    ? entry.selection.number
                    : ('item #' + (i + 1));
                var reason = typeof entry.reason === 'string' && entry.reason !== ''
                    ? ' (' + entry.reason + ')'
                    : '';
                details.push(num + ': refused' + reason);
            }
        }
        if (details.length > 0) {
            summary += ' ' + details.join('; ') + '.';
        }
        return summary;
    }

    function initBetSlip() {
        var container = document.getElementById('bet-slip-container');
        if (!container || container.getAttribute('data-bet-slip-ready') === 'true') {
            return;
        }

        var form = container.closest('form[data-bet-form]') || document.querySelector('form[data-bet-form]');
        var listEl = container.querySelector('#bet-items-list');
        var countEl = container.querySelector('[data-slip-count]');
        var stakeEl = container.querySelector('#total-stake-amount');
        var payoutEl = container.querySelector('#total-payout-amount');
        var statusEl = container.querySelector('[data-slip-status]');
        var submitBtn = container.querySelector('#btn-place-bet');

        if (!listEl || !stakeEl || !payoutEl || !submitBtn) {
            return;
        }

        var maxItemsParsed = parseInt(container.getAttribute('data-max-items') || '50', 10);
        var maxItems = Number.isFinite(maxItemsParsed) && maxItemsParsed > 0 ? maxItemsParsed : 50;

        var drawIdAttr = (container.getAttribute('data-draw-id') || '').trim();
        var drawId = /^\d+$/.test(drawIdAttr) ? Number(drawIdAttr) : null;
        var drawReference = (container.getAttribute('data-draw-reference') || '').trim();
        var actionUrl = form ? String(form.getAttribute('action') || '').trim() : '';

        var scope = (
            container.getAttribute('data-slip-scope') ||
            (form ? form.getAttribute('data-slip-scope') : '') ||
            ''
        ).trim();
        var journalKey = scope !== '' ? 'lottery-pending-slip:' + scope : '';

        var items = [];
        var pendingJournal = null;
        var slipState = 'idle';
        var isSubmitting = false;
        var bettingClosed = false;
        var storageBroken = false;
        var cryptoAvailable = Boolean(window.crypto && typeof window.crypto.getRandomValues === 'function');

        function setSlipState(nextState) {
            slipState = nextState;
            container.setAttribute('data-slip-state', nextState);
        }

        function setStatus(message, tone) {
            if (!statusEl) {
                return;
            }
            statusEl.textContent = message || '';
            statusEl.setAttribute('data-tone', tone || 'info');
            statusEl.setAttribute('role', tone === 'error' || tone === 'warning' ? 'alert' : 'status');
            statusEl.setAttribute('aria-live', 'polite');
            if (message) {
                statusEl.classList.remove('hidden');
            }
        }

        function isAddLocked() {
            return isSubmitting || bettingClosed || storageBroken || slipState === 'unknown' || pendingJournal !== null;
        }

        function isRemoveLocked() {
            return isSubmitting || storageBroken || slipState === 'unknown' || pendingJournal !== null;
        }

        function canSubmit() {
            if (isSubmitting || bettingClosed || storageBroken || !cryptoAvailable) {
                return false;
            }
            if (!actionUrl || (drawId === null && drawReference === '')) {
                return false;
            }
            return items.length > 0 || pendingJournal !== null;
        }

        function renderSlip() {
            clearElement(listEl);

            if (countEl) {
                countEl.textContent = String(items.length);
            }

            if (items.length === 0) {
                var emptyBox = document.createElement('div');
                emptyBox.className = 'py-8 text-center text-xs text-slate-400';
                emptyBox.textContent = 'No numbers selected yet. Choose a market and add numbers to build your slip.';
                listEl.appendChild(emptyBox);
            }

            var totalStakeSatang = 0n;
            var totalPayoutSatang = 0n;
            var lockRemovals = isRemoveLocked();

            for (var i = 0; i < items.length; i++) {
                var item = items[i];
                totalStakeSatang += item.stakeSatang;
                var itemPayoutSatang = computePayoutSatang(item.stakeSatang, item.multiplierScaled);
                totalPayoutSatang += itemPayoutSatang;

                var row = document.createElement('div');
                row.setAttribute('data-slip-item', String(i));
                row.className = 'flex items-center justify-between gap-3 rounded-xl border border-slate-800 bg-slate-950/80 px-3 py-2.5';

                var left = document.createElement('div');
                left.className = 'min-w-0 flex items-center gap-2.5';

                var badge = document.createElement('span');
                badge.className = 'inline-flex min-w-[2.5rem] items-center justify-center rounded-lg border border-amber-400/35 bg-amber-500/10 px-2 py-1 font-mono text-xs font-extrabold text-amber-200';
                badge.textContent = item.number;

                var meta = document.createElement('div');
                meta.className = 'min-w-0';

                var marketTitle = document.createElement('div');
                marketTitle.className = 'truncate text-xs font-semibold text-slate-100';
                marketTitle.textContent = item.label || item.market;

                var stakeMeta = document.createElement('div');
                stakeMeta.className = 'font-mono text-[11px] text-slate-400';
                stakeMeta.textContent = formatSatangDisplay(item.stakeSatang) + ' THB';

                meta.appendChild(marketTitle);
                meta.appendChild(stakeMeta);
                left.appendChild(badge);
                left.appendChild(meta);

                var right = document.createElement('div');
                right.className = 'flex items-center gap-2 shrink-0';

                var payoutBadge = document.createElement('span');
                payoutBadge.className = 'font-mono text-xs font-bold text-emerald-400';
                payoutBadge.textContent = formatSatangDisplay(itemPayoutSatang);

                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.setAttribute('data-remove-index', String(i));
                removeBtn.setAttribute('aria-label', 'Remove number ' + item.number);
                removeBtn.disabled = lockRemovals;
                removeBtn.className = 'inline-flex min-h-[32px] min-w-[32px] items-center justify-center rounded-lg border border-slate-700 bg-slate-900 px-2 text-xs font-bold text-slate-300 transition hover:border-rose-500/50 hover:text-rose-300 disabled:cursor-not-allowed disabled:opacity-40';
                removeBtn.textContent = '×';

                right.appendChild(payoutBadge);
                right.appendChild(removeBtn);

                row.appendChild(left);
                row.appendChild(right);
                listEl.appendChild(row);
            }

            stakeEl.textContent = formatSatangDisplay(totalStakeSatang);
            payoutEl.textContent = formatSatangDisplay(totalPayoutSatang);
            submitBtn.disabled = !canSubmit();
            submitBtn.setAttribute('aria-busy', isSubmitting ? 'true' : 'false');
        }

        function probeAndRecoverStorage() {
            if (!journalKey) {
                storageBroken = true;
                setSlipState('corrupt');
                setStatus('Pending slip storage is unavailable or invalid: missing user/draw scope.', 'error');
                return;
            }

            try {
                var probeKey = journalKey + ':probe';
                window.sessionStorage.setItem(probeKey, '1');
                window.sessionStorage.removeItem(probeKey);

                var existingRaw = window.sessionStorage.getItem(journalKey);
                if (existingRaw !== null) {
                    var parsed = JSON.parse(existingRaw);
                    if (!validateRecoveredJournal(parsed, drawId)) {
                        storageBroken = true;
                        setSlipState('corrupt');
                        setStatus(
                            'Pending slip storage is unavailable or invalid. Verify My Bets before retrying.',
                            'error'
                        );
                        return;
                    }

                    var payload = JSON.parse(parsed.body);
                    pendingJournal = {
                        body: parsed.body,
                        count: payload.items.length,
                    };
                    items = payload.items.map(function (entry, idx) {
                        var metaItem = Array.isArray(parsed.meta) && parsed.meta[idx] ? parsed.meta[idx] : {};
                        return {
                            market: entry.market,
                            label: typeof metaItem.label === 'string' && metaItem.label !== '' ? metaItem.label : entry.market,
                            number: entry.number,
                            stakeSatang: parseSatang(entry.stake) || 0n,
                            multiplierScaled: parseMultiplierScaled(metaItem.multiplier || '0'),
                        };
                    });
                    setSlipState('unknown');
                    setStatus(
                        'A pending slip was recovered for this draw. Submit again to reconcile safely with the same idempotency key.',
                        'warning'
                    );
                }
            } catch (err) {
                storageBroken = true;
                setSlipState('corrupt');
                setStatus(
                    'Pending slip storage is unavailable or invalid in this browser session. Wager submission is disabled.',
                    'error'
                );
            }
        }

        function buildAndPersistJournal() {
            if (pendingJournal) {
                return pendingJournal;
            }

            var clientKey = generateCsprngClientKey();
            if (!clientKey) {
                cryptoAvailable = false;
                setSlipState('blocked');
                setStatus('Secure random generator unavailable; wager submission is disabled.', 'error');
                renderSlip();
                return null;
            }

            var payload = {};
            if (drawId !== null) {
                payload.draw_id = drawId;
            }
            if (drawReference !== '') {
                payload.draw_reference = drawReference;
            }
            payload.client_key = clientKey;
            payload.items = items.map(function (it) {
                return {
                    market: it.market,
                    number: it.number,
                    stake: formatSatangPlain(it.stakeSatang),
                };
            });

            var bodyJson = JSON.stringify(payload);
            var journalRecord = {
                body: bodyJson,
                meta: items.map(function (it) {
                    return {
                        label: it.label,
                        multiplier: it.multiplierRaw || '0',
                    };
                }),
            };

            try {
                window.sessionStorage.setItem(journalKey, JSON.stringify(journalRecord));
            } catch (err) {
                storageBroken = true;
                setSlipState('corrupt');
                setStatus(
                    'Pending slip storage is unavailable or invalid; wager was not transmitted.',
                    'error'
                );
                renderSlip();
                return null;
            }

            pendingJournal = {
                body: bodyJson,
                count: payload.items.length,
            };
            return pendingJournal;
        }

        function clearJournal() {
            pendingJournal = null;
            if (!journalKey) {
                return;
            }
            try {
                window.sessionStorage.removeItem(journalKey);
            } catch (err) {
                storageBroken = true;
            }
        }

        function submitSlip() {
            if (!canSubmit()) {
                return;
            }

            var csrfToken = resolveCsrfToken(form);
            if (!csrfToken) {
                setSlipState('blocked');
                setStatus('Security CSRF token is missing. Refresh the page before placing a wager.', 'error');
                renderSlip();
                return;
            }

            var activeJournal = buildAndPersistJournal();
            if (!activeJournal) {
                return;
            }

            isSubmitting = true;
            setSlipState('submitting');
            setStatus('Submitting wager slip… Do not close this tab.', 'info');
            renderSlip();

            window.fetch(actionUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: activeJournal.body,
            })
                .then(function (response) {
                    return response.text().then(function (rawText) {
                        var parsed = null;
                        if (rawText) {
                            try {
                                parsed = JSON.parse(rawText);
                            } catch (err) {
                                parsed = null;
                            }
                        }
                        return {
                            ok: response.ok,
                            status: response.status,
                            body: parsed,
                        };
                    });
                })
                .then(function (result) {
                    isSubmitting = false;

                    if (result.ok) {
                        if (!isValidReportEnvelope(result.body, activeJournal.count)) {
                            setSlipState('unknown');
                            setStatus(
                                'Outcome unknown: server returned an unverified response. Check My Bets or retry safely.',
                                'warning'
                            );
                            renderSlip();
                            return;
                        }

                        var report = result.body.data.report;
                        clearJournal();
                        items = [];
                        var summaryTone = report.refused > 0 ? 'warning' : 'success';
                        setSlipState('resolved');
                        setStatus(formatReportSummary(report), summaryTone);
                        renderSlip();
                        document.dispatchEvent(new CustomEvent('wallet:balance-stale'));
                        return;
                    }

                    if (result.status === 422) {
                        clearJournal();
                        setSlipState('rejected');
                        var validationMsg =
                            result.body && typeof result.body.message === 'string' && result.body.message !== ''
                                ? result.body.message
                                : 'Validation failed. Review your selections and stake limits.';
                        setStatus(validationMsg, 'error');
                        renderSlip();
                        return;
                    }

                    if (result.status === 401 || result.status === 419) {
                        setSlipState('unknown');
                        setStatus(
                            'Outcome unknown: session or CSRF token expired (HTTP ' + result.status + '). Sign in again and check My Bets before retrying.',
                            'warning'
                        );
                        renderSlip();
                        return;
                    }

                    if (result.status === 403 || result.status === 429) {
                        setSlipState('unknown');
                        var policyMsg =
                            result.body && typeof result.body.message === 'string' && result.body.message !== ''
                                ? result.body.message
                                : 'Request restricted or rate-limited (HTTP ' + result.status + ').';
                        setStatus('Outcome unknown: ' + policyMsg, 'warning');
                        renderSlip();
                        return;
                    }

                    setSlipState('unknown');
                    setStatus(
                        'Outcome unknown due to server error (HTTP ' + result.status + '). Pending slip is locked so retrying uses the identical idempotency key.',
                        'warning'
                    );
                    renderSlip();
                })
                .catch(function () {
                    isSubmitting = false;
                    setSlipState('unknown');
                    setStatus(
                        'Outcome unknown due to lost network connection. Pending slip is locked so retrying uses the identical idempotency key.',
                        'warning'
                    );
                    renderSlip();
                });
        }

        listEl.addEventListener('click', function (e) {
            var target = e.target && e.target.closest ? e.target.closest('[data-remove-index]') : null;
            if (!target || isRemoveLocked()) {
                return;
            }
            var idx = parseInt(target.getAttribute('data-remove-index') || '-1', 10);
            if (Number.isFinite(idx) && idx >= 0 && idx < items.length) {
                items.splice(idx, 1);
                if (!bettingClosed) {
                    setSlipState(items.length > 0 ? 'editing' : 'idle');
                }
                renderSlip();
            }
        });

        document.addEventListener('lottery:selection-added', function (e) {
            var detail = e.detail;
            if (!detail || typeof detail !== 'object') {
                return;
            }

            if (isAddLocked()) {
                detail.accepted = false;
                detail.rejectionReason = bettingClosed
                    ? 'Betting is closed for this draw.'
                    : 'Pending slip is locked while awaiting submission outcome.';
                return;
            }

            var rawNumber = String(detail.number || '').trim();
            var stakeSatang = parseSatang(detail.stake);
            var market = String(detail.market || '').trim();
            if (!market || !/^\d+$/.test(rawNumber) || stakeSatang === null) {
                detail.accepted = false;
                detail.rejectionReason = 'Invalid market, number, or stake format.';
                return;
            }

            var multiplierRaw = String(detail.multiplier === undefined ? '0' : detail.multiplier).trim();
            var multiplierScaled = parseMultiplierScaled(multiplierRaw);

            for (var i = 0; i < items.length; i++) {
                if (items[i].market === market && items[i].number === rawNumber) {
                    var mergedStake = items[i].stakeSatang + stakeSatang;
                    if (mergedStake > MAX_STAKE_SATANG) {
                        detail.accepted = false;
                        detail.rejectionReason = 'Selection refused: merged stake exceeds maximum 999,999,999,999.99 THB.';
                        return;
                    }
                    items[i].stakeSatang = mergedStake;
                    items[i].multiplierRaw = multiplierRaw;
                    items[i].multiplierScaled = multiplierScaled;
                    detail.accepted = true;
                    setSlipState('editing');
                    renderSlip();
                    return;
                }
            }

            if (items.length >= maxItems) {
                detail.accepted = false;
                detail.rejectionReason = 'Maximum of ' + maxItems + ' selections reached for this bet slip.';
                return;
            }

            items.push({
                market: market,
                label: String(detail.label || market).trim(),
                number: rawNumber,
                stakeSatang: stakeSatang,
                multiplierRaw: multiplierRaw,
                multiplierScaled: multiplierScaled,
            });

            detail.accepted = true;
            setSlipState('editing');
            renderSlip();
        });

        document.addEventListener('lottery:betting-closed', function () {
            bettingClosed = true;
            setSlipState('closed');
            setStatus('Betting is closed for this draw.', 'error');
            renderSlip();
        });

        submitBtn.addEventListener('click', function (e) {
            e.preventDefault();
            submitSlip();
        });

        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (!isSubmitting) {
                    submitSlip();
                }
            });
        }

        if (!cryptoAvailable) {
            setSlipState('blocked');
            setStatus('Secure random generator unavailable; wager submission is disabled.', 'error');
        } else {
            probeAndRecoverStorage();
        }

        renderSlip();
        container.setAttribute('data-bet-slip-ready', 'true');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBetSlip);
    } else {
        initBetSlip();
    }
})();
