/**
 * Player Dashboard Browser Runtime (JavaScript)
 * Reference: player_dashboard.png (THAI LOTTERY)
 */

(function (window, document) {
    'use strict';

    function initPlayerDashboard() {
        var remainingSeconds = 14 * 3600 + 48 * 60 + 2; // 14:48:02
        var timerEl = document.getElementById('countdownTimerDisplay');

        if (timerEl) {
            setInterval(function () {
                if (remainingSeconds <= 0) {
                    timerEl.textContent = '00:00:00';
                    return;
                }
                remainingSeconds--;
                var hours = Math.floor(remainingSeconds / 3600);
                var minutes = Math.floor((remainingSeconds % 3600) / 60);
                var seconds = remainingSeconds % 60;

                var hStr = hours < 10 ? '0' + hours : '' + hours;
                var mStr = minutes < 10 ? '0' + minutes : '' + minutes;
                var sStr = seconds < 10 ? '0' + seconds : '' + seconds;

                timerEl.textContent = hStr + ':' + mStr + ':' + sStr;
            }, 1000);
        }

        var depositBtn = document.getElementById('walletDepositBtn');
        if (depositBtn) {
            depositBtn.addEventListener('click', function () {
                var amount = prompt('Enter deposit amount in THB:', '1000');
                if (amount) {
                    var parsed = parseFloat(amount);
                    if (!isNaN(parsed) && parsed > 0) {
                        alert('Deposit request for ' + parsed.toLocaleString() + ' THB initiated.');
                    }
                }
            });
        }

        var cancelBtns = document.querySelectorAll('[data-cancel-ticket]');
        cancelBtns.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var ticketId = btn.getAttribute('data-cancel-ticket');
                if (confirm('Are you sure you want to cancel ticket ' + ticketId + '?')) {
                    alert('Ticket ' + ticketId + ' cancelled. Refund credited.');
                    var row = btn.closest('tr');
                    if (row) row.remove();
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPlayerDashboard);
    } else {
        initPlayerDashboard();
    }
})(window, document);
