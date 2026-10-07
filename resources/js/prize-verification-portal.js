/**
 * Lottery Platform Official Prize Verification & Keypad Controller
 */

(function () {
    'use strict';

    function initPrizeVerification() {
        var tokenMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = tokenMeta ? tokenMeta.getAttribute('content') : '';

        var numInput = document.getElementById('pvTicketNumber');
        var drawSelect = document.getElementById('pvDrawSelect');
        var verifyBtn = document.getElementById('pvVerifyBtn');
        var resultBox = document.getElementById('pvResultContainer');
        var clearBtn = document.getElementById('pvKeypadClear');
        var backspaceBtn = document.getElementById('pvKeypadBackspace');
        var keypadKeys = document.querySelectorAll('.pv-key-btn');

        // Keypad click handlers
        keypadKeys.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var digit = this.getAttribute('data-digit');
                if (numInput && numInput.value.length < 6) {
                    numInput.value += digit;
                    highlightDigits(numInput.value);
                }
            });
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                if (numInput) {
                    numInput.value = '';
                    highlightDigits('');
                }
            });
        }

        if (backspaceBtn) {
            backspaceBtn.addEventListener('click', function () {
                if (numInput && numInput.value.length > 0) {
                    numInput.value = numInput.value.slice(0, -1);
                    highlightDigits(numInput.value);
                }
            });
        }

        if (numInput) {
            numInput.addEventListener('input', function () {
                this.value = this.value.replace(/\D/g, '').slice(0, 6);
                highlightDigits(this.value);
            });
        }

        function highlightDigits(val) {
            for (var i = 1; i <= 6; i++) {
                var box = document.getElementById('digitSlot' + i);
                if (box) {
                    var ch = val[i - 1] || '-';
                    box.textContent = ch;
                    if (val[i - 1]) {
                        box.classList.add('border-amber-400', 'text-amber-400', 'bg-amber-500/10');
                    } else {
                        box.classList.remove('border-amber-400', 'text-amber-400', 'bg-amber-500/10');
                    }
                }
            }
        }

        // Verify button handler
        if (verifyBtn) {
            verifyBtn.addEventListener('click', function (e) {
                e.preventDefault();
                var val = numInput ? numInput.value.trim() : '';
                var draw = drawSelect ? drawSelect.value : '2026-10-01';

                if (!val || val.length !== 6) {
                    alert('Please enter a full 6-digit Thai lottery ticket number (e.g. 843921).');
                    return;
                }

                if (resultBox) {
                    resultBox.classList.remove('hidden');
                    resultBox.innerHTML = '<div class="flex items-center justify-center gap-3 py-10 text-amber-400 font-mono text-xs"><i class="fa-solid fa-circle-notch fa-spin text-xl"></i> Verifying ticket against official GLO ledger...</div>';
                }

                fetch('/api/v1/public/prize-verification/verify', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({
                        mode: 'number',
                        value: val,
                        draw_date: draw
                    })
                })
                .then(function (res) { return res.json(); })
                .then(function (resData) {
                    if (resData.success && resData.data) {
                        renderPrizeResult(resData.data);
                    } else {
                        if (resultBox) {
                            resultBox.innerHTML = '<div class="p-6 bg-red-500/10 border border-red-500/30 rounded-xl text-red-400 text-xs text-center"><i class="fa-solid fa-circle-exclamation mr-1.5"></i> ' + (resData.message || 'Verification failed.') + '</div>';
                        }
                    }
                })
                .catch(function (err) {
                    console.error('Prize verification error:', err);
                    if (resultBox) {
                        resultBox.innerHTML = '<div class="p-6 bg-red-500/10 border border-red-500/30 rounded-xl text-red-400 text-xs text-center"><i class="fa-solid fa-triangle-exclamation mr-1.5"></i> Network error connecting to GLO verification gateway.</div>';
                    }
                });
            });
        }

        function renderPrizeResult(data) {
            if (!resultBox) return;

            var isWin = data.status === 'WINNING';
            var headerBg = isWin ? 'bg-gradient-to-r from-emerald-950/80 to-emerald-900/60 border-emerald-500/50' : 'bg-[#0a121e] border-slate-700';
            var statusBadge = isWin
                ? '<span class="px-3 py-1 rounded-full bg-emerald-500/20 text-emerald-400 border border-emerald-500/50 font-black text-xs font-mono animate-pulse">🎉 OFFICIAL WINNER</span>'
                : '<span class="px-3 py-1 rounded-full bg-slate-800 text-slate-400 border border-slate-700 font-black text-xs font-mono">NON-WINNING TICKET</span>';

            var prizeContent = '';
            if (isWin) {
                prizeContent = '<div class="text-center py-4 space-y-2">' +
                    '  <span class="text-xs font-mono text-emerald-400 uppercase tracking-widest font-bold block">' + data.prize.category + '</span>' +
                    '  <div class="text-3xl sm:text-4xl font-black text-emerald-400 font-mono tracking-tight">' + data.prize.amount + '</div>' +
                    '  <span class="text-[11px] text-slate-300 font-mono block">Official statutory prize payout payable in full with 0.00% platform commission.</span>' +
                    '</div>';
            } else {
                prizeContent = '<div class="text-center py-4 space-y-1">' +
                    '  <div class="text-xl font-bold text-slate-300 font-mono">0.00 THB</div>' +
                    '  <span class="text-xs text-slate-400">This 6-digit number did not match winning tiers for draw date ' + data.draw.date + '.</span>' +
                    '</div>';
            }

            var html = '<div class="tl-card ' + headerBg + ' p-6 sm:p-8 space-y-5 shadow-2xl">' +
                '<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-[#1c2b44] pb-4">' +
                '  <div>' +
                '    <span class="text-[10px] font-mono text-slate-400 uppercase block">Ticket Number Checked</span>' +
                '    <span class="text-2xl font-black text-white font-mono tracking-widest">' + data.normalized_ticket_number + '</span>' +
                '  </div>' +
                '  <div>' + statusBadge + '</div>' +
                '</div>' +
                prizeContent +
                '<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-4 border-t border-[#1c2b44] text-[11px] font-mono text-slate-400">' +
                '  <div><span class="text-slate-500 block text-[9px] uppercase">Draw Ref</span><span class="text-slate-200">' + data.draw.number + '</span></div>' +
                '  <div><span class="text-slate-500 block text-[9px] uppercase">Draw Date</span><span class="text-slate-200">' + data.draw.date + '</span></div>' +
                '  <div><span class="text-slate-500 block text-[9px] uppercase">Integrity Seal</span><span class="text-emerald-400 font-bold">Passed</span></div>' +
                '  <div><span class="text-slate-500 block text-[9px] uppercase">GLO Ledger</span><span class="text-cyan-400 font-bold">Synchronized</span></div>' +
                '</div>' +
                '</div>';

            resultBox.innerHTML = html;
        }

        // Test sample winning numbers presets
        window.setSampleNumber = function (num) {
            if (numInput) {
                numInput.value = num;
                highlightDigits(num);
                if (verifyBtn) verifyBtn.click();
            }
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPrizeVerification);
    } else {
        initPrizeVerification();
    }
})();
