/**
 * Lottery Platform Fee Schedule & Real-Time Fee Calculator Controller
 */

(function () {
    'use strict';

    function initFees() {
        var tokenMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = tokenMeta ? tokenMeta.getAttribute('content') : '';

        // Live Fee Calculator Form
        var calcForm = document.getElementById('feeCalculatorForm');
        var calcCategory = document.getElementById('calcCategory');
        var calcAmount = document.getElementById('calcAmount');

        function formatDecimal(value) {
            var raw = typeof value === 'string' ? value.trim() : '';
            if (!/^\d+(\.\d{1,2})?$/.test(raw)) return null;
            var parts = raw.split('.');
            var integer = parts[0].replace(/^0+(?=\d)/, '');
            var fraction = (parts[1] || '').padEnd(2, '0');
            return integer.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + fraction;
        }

        function triggerCalculation() {
            var cat = calcCategory ? calcCategory.value : 'deposit_promptpay';
            var amt = calcAmount ? calcAmount.value.trim() : '';

            if (formatDecimal(amt) === null || !/[1-9]/.test(amt)) return;

            fetch('/api/v1/public/fees/calculate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ category: cat, amount: amt })
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    renderCalculationResult(data);
                }
            })
            .catch(function (err) {
                console.error('Fee calculation error:', err);
            });
        }

        function renderCalculationResult(data) {
            var baseEl = document.getElementById('calcResultBase');
            var feeEl = document.getElementById('calcResultFee');
            var netEl = document.getElementById('calcResultNet');
            var noteEl = document.getElementById('calcResultNote');

            var base = formatDecimal(data.base_amount);
            var fee = formatDecimal(data.fee_amount);
            var net = formatDecimal(data.net_amount);

            if (baseEl) baseEl.textContent = base !== null ? base + ' ' + data.currency : 'Unavailable';
            if (feeEl) feeEl.textContent = fee !== null ? fee + ' ' + data.currency + ' (' + data.fee_rate + ')' : 'Unavailable';
            if (netEl) netEl.textContent = net !== null ? net + ' ' + data.currency : 'Unavailable';
            if (noteEl) noteEl.textContent = data.note;
        }

        if (calcForm) {
            calcForm.addEventListener('submit', function (e) {
                e.preventDefault();
                triggerCalculation();
            });
        }

        if (calcCategory) calcCategory.addEventListener('change', triggerCalculation);
        if (calcAmount) {
            var debounce;
            calcAmount.addEventListener('input', function () {
                clearTimeout(debounce);
                debounce = setTimeout(triggerCalculation, 300);
            });
        }

        // Search bar
        var searchInput = document.getElementById('feeSearchInput');
        var searchDebounce;
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                clearTimeout(searchDebounce);
                searchDebounce = setTimeout(function () {
                    var q = searchInput.value.trim();
                    searchFeesApi(q);
                }, 300);
            });
        }

        function searchFeesApi(q) {
            fetch('/api/v1/public/fees/search' + (q ? '?q=' + encodeURIComponent(q) : ''), {
                headers: { 'Accept': 'application/json' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success && data.results) {
                    renderFeeGroups(data.results);
                }
            });
        }

        function renderFeeGroups(groups) {
            var container = document.getElementById('feesGroupsContainer');
            if (!container) return;

            if (groups.length === 0) {
                container.innerHTML = '<div class="tl-card text-center py-12">' +
                    '<div class="text-3xl mb-2">💰</div>' +
                    '<div class="font-bold text-white text-base">No Matching Fee Schedules Found</div>' +
                    '<div class="text-slate-400 text-xs mt-1">Try searching for keywords like "PromptPay", "Bank", "USDT", or "0%".</div>' +
                    '</div>';
                return;
            }

            var html = '';
            groups.forEach(function (group) {
                html += '<div class="tl-card mb-6" id="' + group.id + '">';
                html += '  <div class="flex items-center gap-3 border-b border-[#1c2b44] pb-3 mb-4">';
                html += '    <span class="w-8 h-8 rounded-lg bg-amber-500/20 border border-amber-500 text-amber-400 font-black text-xs flex items-center justify-center font-mono">' + group.number + '</span>';
                html += '    <div>';
                html += '      <h3 class="text-base font-black text-white">' + group.title + '</h3>';
                html += '      <span class="text-xs text-slate-400">' + group.summary + '</span>';
                html += '    </div>';
                html += '  </div>';

                html += '  <table class="w-full text-xs text-left">';
                html += '    <thead>';
                html += '      <tr class="text-slate-400 uppercase text-[10px] border-b border-[#1c2b44]">';
                html += '        <th class="py-2.5">Service / Channel</th>';
                html += '        <th class="py-2.5">Fee Rate</th>';
                html += '        <th class="py-2.5">Speed</th>';
                html += '        <th class="py-2.5">Min - Max Bounds</th>';
                html += '      </tr>';
                html += '    </thead>';
                html += '    <tbody class="divide-y divide-[#1c2b44]/60">';
                group.items.forEach(function (item) {
                    html += '<tr>';
                    html += '  <td class="py-3">';
                    html += '    <span class="font-bold text-white block">' + item.name + '</span>';
                    html += '    <span class="text-[10px] text-slate-400">' + item.description + '</span>';
                    html += '  </td>';
                    html += '  <td class="py-3 font-mono font-black ' + (item.rate === '0.00%' || item.rate === 'Free' ? 'text-emerald-400' : 'text-amber-400') + '">' + item.rate + '</td>';
                    html += '  <td class="py-3 font-mono text-slate-300">' + item.speed + '</td>';
                    html += '  <td class="py-3 font-mono text-slate-400">' + item.min_max + '</td>';
                    html += '</tr>';
                });
                html += '    </tbody>';
                html += '  </table>';
                html += '</div>';
            });

            container.innerHTML = html;
        }

        // Global methods
        window.feesPortal = {
            downloadFees: function () {
                fetch('/api/v1/public/fees/download', {
                    headers: { 'Accept': 'application/json' }
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    alert('Official Fee Schedule: ' + data.file_name + ' (' + data.file_size + ')\nSHA256: ' + data.sha256.substring(0, 24) + '...');
                });
            }
        };

        // Initial calculation trigger
        triggerCalculation();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFees);
    } else {
        initFees();
    }
})();
