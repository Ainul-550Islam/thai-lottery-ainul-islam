/**
 * Lottery Platform Member Lottery Bet History & Slip Verification Runtime (Vanilla JS)
 */

(function () {
    'use strict';

    function initHistory() {
        var tokenMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = tokenMeta ? tokenMeta.getAttribute('content') : '';

        var currentStatus = 'all';
        var currentMarket = 'all';
        var currentDate = '';
        var searchQ = '';

        function fetchSlips() {
            var params = new URLSearchParams();
            if (currentStatus !== 'all') params.append('status', currentStatus);
            if (currentMarket !== 'all') params.append('market', currentMarket);
            if (currentDate) params.append('draw_date', currentDate);
            if (searchQ) params.append('q', searchQ);

            fetch('/api/v1/player/history?' + params.toString(), {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                }
            })
            .then(function (res) { return res.json(); })
            .then(function (res) {
                if (res.success && res.data) {
                    renderSlips(res.data);
                }
            })
            .catch(function (err) {
                console.error(err);
            });
        }

        function renderSlips(slips) {
            var container = document.getElementById('slipsContainer');
            if (!container) return;

            if (slips.length === 0) {
                container.innerHTML = '<div class="lh-slip-card text-center py-12">' +
                    '<div class="text-4xl mb-2">📜</div>' +
                    '<div class="text-white font-bold text-base">No Lottery Wager Records Found</div>' +
                    '<div class="text-slate-400 text-xs mt-1">Try adjusting your filters or date selection.</div>' +
                    '</div>';
                return;
            }

            var html = '';
            slips.forEach(function (slip) {
                var badgeClass = slip.status === 'won' ? 'won' : slip.status === 'lost' ? 'lost' : 'pending';
                var statusClass = 'status-' + slip.status;

                html += '<div class="lh-slip-card ' + statusClass + '" id="slip-' + slip.slip_id + '">';
                html += '  <div class="lh-slip-head">';
                html += '    <div class="lh-slip-id-wrap">';
                html += '      <span class="lh-slip-ref">' + slip.slip_id + '</span>';
                html += '      <span class="lh-status-badge ' + badgeClass + '">' + slip.status_label + '</span>';
                html += '    </div>';
                html += '    <div class="text-xs text-slate-400 font-mono">';
                html += '      Draw Date: <span class="font-bold text-white">' + slip.draw_date + ' (' + slip.draw_time + ')</span>';
                html += '    </div>';
                html += '  </div>';

                html += '  <div class="text-xs font-bold text-amber-400">' + slip.market_name + '</div>';

                html += '  <table class="lh-items-table">';
                html += '    <thead>';
                html += '      <tr>';
                html += '        <th>Play Type (ประเภท)</th>';
                html += '        <th>Number (เลข)</th>';
                html += '        <th>Stake (บาท)</th>';
                html += '        <th>Odds (จ่าย)</th>';
                html += '        <th>Result / Payout</th>';
                html += '      </tr>';
                html += '    </thead>';
                html += '    <tbody>';

                slip.items.forEach(function (item) {
                    html += '<tr>';
                    html += '  <td><span class="font-bold text-white">' + item.play_label + '</span></td>';
                    html += '  <td><span class="lh-num-badge">' + item.number + '</span></td>';
                    html += '  <td class="font-mono">' + Number(item.stake).toFixed(2) + ' ฿</td>';
                    html += '  <td class="font-mono text-amber-400">x' + item.rate + '</td>';
                    html += '  <td>';
                    if (item.status === 'won') {
                        html += '<span class="font-mono font-bold text-emerald-400">+' + Number(item.payout || 0).toLocaleString() + ' ฿</span>';
                    } else if (item.status === 'lost') {
                        html += '<span class="text-slate-500 font-bold">0.00 ฿</span>';
                    } else {
                        html += '<span class="text-amber-400 text-xs font-bold">Pending</span>';
                    }
                    html += '  </td>';
                    html += '</tr>';
                });

                html += '    </tbody>';
                html += '  </table>';

                html += '  <div class="lh-slip-foot">';
                html += '    <div class="flex items-center gap-4 text-xs font-mono">';
                html += '      <div>Total Stake: <span class="font-bold text-white">' + Number(slip.total_stake).toFixed(2) + ' THB</span></div>';
                html += '      <div>Total Payout: <span class="font-bold ' + (slip.total_payout > 0 ? 'text-emerald-400' : 'text-slate-400') + '">' + Number(slip.total_payout).toFixed(2) + ' THB</span></div>';
                html += '    </div>';

                html += '    <div class="lh-slip-actions">';
                if (slip.can_cancel) {
                    html += '  <button type="button" class="lh-action-btn danger" onclick="window.lotteryHistory.cancelSlip(\'' + slip.slip_id + '\')">Cancel Slip</button>';
                }
                html += '      <button type="button" class="lh-action-btn" onclick="window.lotteryHistory.rebetSlip(\'' + slip.slip_id + '\')">🔁 Re-Bet (แทงซ้ำ)</button>';
                html += '      <button type="button" class="lh-action-btn primary" onclick="window.lotteryHistory.openSlipDetailModal(\'' + slip.slip_id + '\')">View Official Slip</button>';
                html += '    </div>';
                html += '  </div>';

                html += '</div>';
            });

            container.innerHTML = html;
        }

        // Setup filter pills
        document.querySelectorAll('[data-lh-status]').forEach(function (pill) {
            pill.addEventListener('click', function () {
                document.querySelectorAll('[data-lh-status]').forEach(function (p) { p.classList.remove('active'); });
                pill.classList.add('active');
                currentStatus = pill.getAttribute('data-lh-status') || 'all';
                fetchSlips();
            });
        });

        // Market select
        var marketSelect = document.getElementById('marketSelectFilter');
        if (marketSelect) {
            marketSelect.addEventListener('change', function () {
                currentMarket = marketSelect.value;
                fetchSlips();
            });
        }

        // Draw date select
        var dateSelect = document.getElementById('drawDateSelectFilter');
        if (dateSelect) {
            dateSelect.addEventListener('change', function () {
                currentDate = dateSelect.value;
                fetchSlips();
            });
        }

        // Search input
        var searchInput = document.getElementById('historySearchInput');
        var debounce;
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                clearTimeout(debounce);
                debounce = setTimeout(function () {
                    searchQ = searchInput.value.trim();
                    fetchSlips();
                }, 300);
            });
        }

        // Refresh btn
        var refreshBtn = document.getElementById('historyRefreshBtn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () {
                fetchSlips();
            });
        }

        window.lotteryHistory = {
            cancelSlip: function (slipId) {
                if (!confirm('Are you sure you want to cancel and refund slip ' + slipId + '?')) return;
                fetch('/api/v1/player/history/cancel/' + slipId, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
                })
                .then(function (res) { return res.json(); })
                .then(function (res) {
                    alert(res.message || 'Slip cancelled.');
                    fetchSlips();
                });
            },
            rebetSlip: function (slipId) {
                fetch('/api/v1/player/history/rebet/' + slipId, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
                })
                .then(function (res) { return res.json(); })
                .then(function (res) {
                    if (res.redirect) window.location.href = res.redirect;
                });
            },
            openSlipDetailModal: function (slipId) {
                var modal = document.getElementById('slipDetailModal');
                if (modal) modal.style.display = 'flex';
            },
            closeSlipDetailModal: function () {
                var modal = document.getElementById('slipDetailModal');
                if (modal) modal.style.display = 'none';
            }
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initHistory);
    } else {
        initHistory();
    }
})();
