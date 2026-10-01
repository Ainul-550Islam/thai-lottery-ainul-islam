/**
 * SVGBetSlip — Premium 3D Glass Interactive Bet Slip.
 *
 * Implements client-side bet basket management, satang-precision subtotal previews,
 * CSRF headers, idempotency token persistence across retries, and double-submit prevention.
 */
(function () {
    'use strict';

    var MAX_ITEMS = 50;

    function formatBaht(amount) {
        var num = Number(amount);
        if (!Number.isFinite(num)) return '0.00';
        return num.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    function toSatang(decimalStr) {
        var parts = String(decimalStr).split('.');
        var major = parts[0] === '' ? '0' : parts[0];
        var minor = (parts[1] || '').padEnd(2, '0').slice(0, 2);
        return (Number(major) * 100) + Number(minor);
    }

    function fromSatang(satang) {
        return (satang / 100).toFixed(2);
    }

    function generateClientKey() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return 'slip-' + window.crypto.randomUUID();
        }
        return 'slip-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
    }

    function initBetSlip() {
        var container = document.getElementById('bet-slip-container');
        if (!container) return;

        var list = container.querySelector('#bet-items-list');
        var stakeDisplay = container.querySelector('#total-stake-amount');
        var payoutDisplay = container.querySelector('#total-payout-amount');
        var placeBtn = container.querySelector('#btn-place-bet');
        var statusAlert = container.querySelector('[data-slip-status]');

        if (!list || !stakeDisplay || !payoutDisplay || !placeBtn) return;

        var endpoint = (container.getAttribute('data-purchase-endpoint') || '').trim();
        var token = (container.getAttribute('data-api-token') || '').trim();
        var drawId = Number(container.getAttribute('data-draw-id'));
        var hasValidDraw = Number.isFinite(drawId) && drawId > 0;

        var items = [];
        var clientKey = generateClientKey();
        var isSubmitting = false;

        function setFeedback(message, type) {
            if (!statusAlert) return;
            statusAlert.textContent = message || '';
            statusAlert.setAttribute('data-tone', type || 'neutral');
            if (message) {
                statusAlert.classList.remove('hidden');
            } else {
                statusAlert.classList.add('hidden');
            }
        }

        function renderSlip() {
            list.innerHTML = '';

            if (items.length === 0) {
                var emptyNotice = document.createElement('div');
                emptyNotice.className = 'text-center py-12 text-slate-500 text-xs';
                emptyNotice.innerHTML = '<p>Your bet slip is empty.</p><p class="mt-1 text-[11px] text-slate-600">Select lottery numbers from the market selector.</p>';
                list.appendChild(emptyNotice);
            }

            var totalStakeSatang = 0;
            var totalPayoutSatang = 0;

            items.forEach(function (item, index) {
                totalStakeSatang += item.stakeSatang;
                totalPayoutSatang += Math.round(item.stakeSatang * item.multiplier);

                var row = document.createElement('div');
                row.className = 'flex items-center justify-between p-3 rounded-xl bg-slate-950/80 border border-slate-800/80 transition hover:border-slate-700';

                row.innerHTML =
                    '<div class="flex items-center space-x-3">' +
                        '<span class="tl-lottery-ball-3d w-8 h-8 text-xs text-slate-950">' + item.number + '</span>' +
                        '<div>' +
                            '<span class="text-xs font-bold text-white block">' + item.label + '</span>' +
                            '<span class="text-[10px] text-slate-400 font-mono">฿' + formatBaht(fromSatang(item.stakeSatang)) + '</span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="flex items-center space-x-3">' +
                        '<span class="text-xs font-mono font-bold text-emerald-400">max ฿' + formatBaht(fromSatang(Math.round(item.stakeSatang * item.multiplier))) + '</span>' +
                        '<button type="button" data-remove-index="' + index + '" class="w-6 h-6 rounded-lg bg-slate-900 hover:bg-rose-950/60 text-slate-400 hover:text-rose-400 text-xs font-bold transition flex items-center justify-center">&times;</button>' +
                    '</div>';

                list.appendChild(row);
            });

            stakeDisplay.textContent = '฿' + formatBaht(fromSatang(totalStakeSatang));
            payoutDisplay.textContent = '฿' + formatBaht(fromSatang(totalPayoutSatang));

            var canSubmit = items.length > 0 && !isSubmitting && hasValidDraw && endpoint !== '';
            placeBtn.disabled = !canSubmit;
        }

        list.addEventListener('click', function (e) {
            var removeBtn = e.target.closest('[data-remove-index]');
            if (!removeBtn) return;
            var idx = Number(removeBtn.getAttribute('data-remove-index'));
            if (Number.isFinite(idx) && idx >= 0 && idx < items.length) {
                items.splice(idx, 1);
                setFeedback('', 'neutral');
                renderSlip();
            }
        });

        document.addEventListener('lottery:selection-added', function (e) {
            var detail = e.detail;
            if (!detail || typeof detail !== 'object') return;

            var stakeSatang = toSatang(detail.stake);
            if (!Number.isFinite(stakeSatang) || stakeSatang <= 0) return;

            // Merge if exact market and number exists
            for (var i = 0; i < items.length; i++) {
                if (items[i].market === detail.market && items[i].number === detail.number) {
                    items[i].stakeSatang += stakeSatang;
                    detail.accepted = true;
                    renderSlip();
                    return;
                }
            }

            if (items.length >= MAX_ITEMS) {
                detail.accepted = false;
                setFeedback('Maximum ' + MAX_ITEMS + ' selections allowed per slip.', 'warning');
                return;
            }

            items.push({
                market: detail.market,
                label: detail.label || detail.market,
                number: detail.number,
                stakeSatang: stakeSatang,
                multiplier: Number(detail.multiplier || 1),
            });

            detail.accepted = true;
            renderSlip();
        });

        placeBtn.addEventListener('click', function () {
            if (isSubmitting || items.length === 0 || !hasValidDraw || endpoint === '') return;

            isSubmitting = true;
            placeBtn.disabled = true;
            setFeedback('Submitting bet slip…', 'neutral');

            var payload = {
                draw_id: drawId,
                client_key: clientKey,
                items: items.map(function (it) {
                    return {
                        market: it.market,
                        number: it.number,
                        stake: fromSatang(it.stakeSatang),
                    };
                }),
            };

            var csrf = document.querySelector('meta[name="csrf-token"]');
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
            .then(function (res) {
                return res.json().catch(function () { return null; }).then(function (body) {
                    return { ok: res.ok, status: res.status, body: body };
                });
            })
            .then(function (result) {
                isSubmitting = false;
                if (result.ok) {
                    items = [];
                    clientKey = generateClientKey();
                    renderSlip();
                    setFeedback('Bet slip successfully placed! View under My Bets.', 'success');
                    document.dispatchEvent(new CustomEvent('wallet:balance-stale'));
                } else {
                    renderSlip();
                    var msg = result.body && result.body.message ? result.body.message : 'Wager placement refused.';
                    setFeedback(msg, 'error');
                }
            })
            .catch(function () {
                isSubmitting = false;
                renderSlip();
                setFeedback('Connection failure. Check My Bets before retrying.', 'error');
            });
        });

        renderSlip();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBetSlip);
    } else {
        initBetSlip();
    }
})();
