/**
 * Player bet page — the bet slip basket.
 *
 * WHAT THIS OWNS
 * The right-hand card of resources/views/player/bet.blade.php: the item list,
 * the stake and maximum-payout totals, and the "Confirm & Place Bets" button.
 * Selections arrive as `lottery:selection-added` events from
 * resources/js/lottery/ticket-selector.js; this module decides whether to
 * accept them and reports that decision back on the event object.
 *
 * MONEY IS DISPLAYED, NEVER DECIDED
 * The maximum payout shown here is stake x the market multiplier the server
 * rendered onto the <option> (from config('lottery.markets')). It is a preview
 * of the same arithmetic the settlement engine performs — it is not an offer,
 * not a guarantee, and nothing in this file is trusted by the backend. The
 * authoritative figures come back from the purchase response.
 *
 * SUBMISSION
 * Operates over session-authenticated endpoints (via CSRF token) or bearer
 * tokens when configured.
 *
 * REQUEST SHAPE
 * When it is configured, the body is exactly what BulkBetRequest validates:
 *   { draw_id: int, client_key: string, items: [{market, number, stake}] }
 * with number and stake as STRINGS so leading zeros and minor units survive.
 * The client_key is generated once per slip and reused across retries, which
 * is what makes a retry idempotent instead of a second purchase.
 */
(function () {
    'use strict';

    var MAX_ITEMS_FALLBACK = 50;

    function money(value) {
        var amount = Number(value);

        if (!Number.isFinite(amount)) {
            return '0.00';
        }

        return amount.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    /**
     * Sum decimal strings in integer minor units (satang) so repeated addition
     * of values like 0.10 cannot drift the way binary floats do.
     */
    function toMinorUnits(decimalString) {
        var parts = String(decimalString).split('.');
        var major = parts[0] === '' ? '0' : parts[0];
        var minor = (parts[1] || '').padEnd(2, '0').slice(0, 2);

        return (Number(major) * 100) + Number(minor);
    }

    function fromMinorUnits(minor) {
        return (minor / 100).toFixed(2);
    }

    /**
     * A per-slip idempotency key. Matches BulkBetRequest's CLIENT_KEY_PATTERN
     * (`[A-Za-z0-9._:-]+`) and its configured length window.
     */
    function newClientKey() {
        var random = '';

        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            var bytes = new Uint8Array(12);
            window.crypto.getRandomValues(bytes);

            for (var i = 0; i < bytes.length; i++) {
                random += bytes[i].toString(16).padStart(2, '0');
            }
        } else {
            random = String(Date.now()) + '-' + Math.random().toString(36).slice(2, 12);
            random = random.replace(/[^A-Za-z0-9._:-]/g, '');
        }

        return ('slip-' + Date.now().toString(36) + '-' + random).slice(0, 128);
    }

    function init(container) {
        var list = container.querySelector('#bet-items-list');
        var stakeOutput = container.querySelector('#total-stake-amount');
        var payoutOutput = container.querySelector('#total-payout-amount');
        var placeButton = container.querySelector('#btn-place-bet');
        var status = container.querySelector('[data-slip-status]');

        if (!list || !stakeOutput || !payoutOutput || !placeButton) {
            return;
        }

        var maxItems = Number(container.getAttribute('data-max-items'));
        maxItems = Number.isFinite(maxItems) && maxItems > 0 ? Math.floor(maxItems) : MAX_ITEMS_FALLBACK;

        var endpoint = (container.getAttribute('data-purchase-endpoint') || '').trim();
        var token = (container.getAttribute('data-api-token') || '').trim();
        var form = container.closest('form');
        var drawIdRaw = container.getAttribute('data-draw-id');
        var drawId = Number(drawIdRaw);
        var hasDraw = Number.isFinite(drawId) && drawId > 0;

        var items = [];
        var clientKey = newClientKey();
        var submitting = false;

        function say(message, tone) {
            if (!status) {
                return;
            }

            status.textContent = message || '';
            status.setAttribute('data-tone', tone || 'neutral');
        }

        function canSubmit() {
            return hasDraw && (endpoint !== '' || form !== null);
        }

        function render() {
            list.textContent = '';

            if (items.length === 0) {
                var empty = document.createElement('p');
                empty.className = 'text-xs text-slate-500 text-center py-10';
                empty.setAttribute('data-slip-empty', 'true');
                empty.textContent = 'No selections yet. Build your slip from the keypad.';
                list.appendChild(empty);
            }

            for (var i = 0; i < items.length; i++) {
                list.appendChild(renderItem(items[i], i));
            }

            var stakeMinor = 0;
            var payoutMinor = 0;

            for (var j = 0; j < items.length; j++) {
                stakeMinor += items[j].stakeMinor;
                payoutMinor += Math.round(items[j].stakeMinor * items[j].multiplier);
            }

            stakeOutput.textContent = money(fromMinorUnits(stakeMinor));
            payoutOutput.textContent = money(fromMinorUnits(payoutMinor));

            container.setAttribute('data-slip-count', String(items.length));

            var enabled = items.length > 0 && !submitting && canSubmit();
            placeButton.disabled = !enabled;

            if (items.length > 0 && !canSubmit()) {
                placeButton.disabled = true;
                say(
                    'No active draw is available for wagering at this moment. Totals above are a preview only.',
                    'warning'
                );
            }
        }

        function renderItem(item, index) {
            var row = document.createElement('div');
            row.className = 'flex items-center justify-between gap-3 bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5';
            row.setAttribute('data-slip-item', String(index));

            var left = document.createElement('div');
            left.className = 'min-w-0';

            var number = document.createElement('div');
            number.className = 'font-mono font-bold text-amber-400 text-lg leading-none tracking-widest';
            number.textContent = item.number;

            var label = document.createElement('div');
            label.className = 'text-[11px] text-slate-400 truncate mt-1';
            label.textContent = item.label;

            left.appendChild(number);
            left.appendChild(label);

            var right = document.createElement('div');
            right.className = 'flex items-center gap-3 shrink-0';

            var amounts = document.createElement('div');
            amounts.className = 'text-right';

            var stake = document.createElement('div');
            stake.className = 'font-mono text-xs font-bold text-white';
            stake.textContent = '฿' + money(fromMinorUnits(item.stakeMinor));

            var payout = document.createElement('div');
            payout.className = 'font-mono text-[11px] text-emerald-400';
            payout.textContent = 'max ฿' + money(fromMinorUnits(Math.round(item.stakeMinor * item.multiplier)));

            amounts.appendChild(stake);
            amounts.appendChild(payout);

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'w-7 h-7 rounded-lg bg-slate-900 hover:bg-rose-950/60 border border-slate-800 text-rose-400 text-xs font-bold transition';
            remove.setAttribute('data-remove-index', String(index));
            remove.setAttribute('aria-label', 'Remove ' + item.label + ' ' + item.number + ' from the slip');
            remove.textContent = '×';

            right.appendChild(amounts);
            right.appendChild(remove);

            row.appendChild(left);
            row.appendChild(right);

            return row;
        }

        list.addEventListener('click', function (event) {
            var button = event.target.closest('[data-remove-index]');

            if (!button || !list.contains(button)) {
                return;
            }

            var index = Number(button.getAttribute('data-remove-index'));

            if (Number.isFinite(index) && index >= 0 && index < items.length) {
                items.splice(index, 1);
                say('');
                render();
            }
        });

        document.addEventListener('lottery:selection-added', function (event) {
            var detail = event.detail;

            if (!detail || typeof detail !== 'object') {
                return;
            }

            var stakeMinor = toMinorUnits(detail.stake);

            if (!Number.isFinite(stakeMinor) || stakeMinor <= 0) {
                detail.accepted = false;
                detail.reason = 'That stake could not be read.';

                return;
            }

            // Same market and same number is one position with a larger stake,
            // not two rows — this is also what keeps a slip inside the
            // server's 50-item ceiling when a player taps the same bet twice.
            for (var i = 0; i < items.length; i++) {
                if (items[i].market === detail.market && items[i].number === detail.number) {
                    items[i].stakeMinor += stakeMinor;
                    detail.accepted = true;
                    render();

                    return;
                }
            }

            if (items.length >= maxItems) {
                detail.accepted = false;
                detail.reason = 'A slip may hold at most ' + maxItems + ' selections.';

                return;
            }

            items.push({
                market: detail.market,
                label: detail.label,
                number: detail.number,
                stakeMinor: stakeMinor,
                multiplier: Number(detail.multiplier),
            });

            detail.accepted = true;
            render();
        });

        document.addEventListener('lottery:betting-closed', function () {
            placeButton.disabled = true;
            say('Betting for this draw is closed. This slip can no longer be submitted.', 'error');
        });

        placeButton.addEventListener('click', function () {
            if (submitting || items.length === 0) {
                return;
            }

            if (!canSubmit()) {
                say(
                    'No active draw is available for wagering.',
                    'warning'
                );

                return;
            }

            if (endpoint === '' && form !== null) {
                var clientKeyInput = form.querySelector('[data-client-key]');
                if (clientKeyInput) {
                    clientKeyInput.value = clientKey;
                }

                items.forEach(function (item, index) {
                    ['market', 'number', 'stake'].forEach(function (field) {
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'items[' + index + '][' + field + ']';
                        input.value = field === 'stake' ? fromMinorUnits(item.stakeMinor) : item[field];
                        form.appendChild(input);
                    });
                });

                submitting = true;
                placeButton.disabled = true;
                form.submit();
                return;
            }

            var payload = {
                draw_id: drawId,
                client_key: clientKey,
                items: items.map(function (item) {
                    return {
                        market: item.market,
                        number: item.number,
                        stake: fromMinorUnits(item.stakeMinor),
                    };
                }),
            };

            var csrf = document.querySelector('meta[name="csrf-token"]');

            submitting = true;
            placeButton.disabled = true;
            container.setAttribute('data-slip-state', 'submitting');
            say('Submitting slip…', 'neutral');

            var headers = {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : '',
            };

            if (token !== '') {
                headers['Authorization'] = 'Bearer ' + token;
            }

            window.fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: headers,
                body: JSON.stringify(payload),
            })
                .then(function (response) {
                    return response.json().catch(function () {
                        return null;
                    }).then(function (body) {
                        return { ok: response.ok, status: response.status, body: body };
                    });
                })
                .then(function (result) {
                    submitting = false;
                    container.setAttribute('data-slip-state', result.ok ? 'purchased' : 'rejected');

                    if (result.ok) {
                        items = [];
                        // A new slip is a new idempotency scope.
                        clientKey = newClientKey();
                        render();
                        say('Slip accepted. Your wagers are listed under My Bets.', 'success');
                        document.dispatchEvent(new CustomEvent('wallet:balance-stale'));

                        return;
                    }

                    // Surface only the server's own message; never invent one.
                    var message = result.body && result.body.message
                        ? String(result.body.message)
                        : 'The slip was refused (HTTP ' + result.status + '). Nothing was charged.';

                    render();
                    say(message, 'error');
                })
                .catch(function () {
                    submitting = false;
                    container.setAttribute('data-slip-state', 'failed');
                    render();
                    say(
                        'The slip could not be sent and its outcome is unknown. Check My Bets before retrying — '
                            + 'a retry reuses the same client key, so it cannot purchase twice.',
                        'error'
                    );
                });
        });

        render();
        container.setAttribute('data-bet-slip-ready', 'true');
    }

    function boot() {
        var containers = document.querySelectorAll('#bet-slip-container');

        for (var i = 0; i < containers.length; i++) {
            init(containers[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
