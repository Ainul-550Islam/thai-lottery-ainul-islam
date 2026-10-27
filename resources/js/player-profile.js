/**
 * Lottery Platform Club Member Profile Browser Runtime (JavaScript)
 * Reference: https://lottery-platform.club/
 */

(function (window, document) {
    'use strict';

    function initProfile() {
        var transferDirection = 'cash_to_win';

        // Tab switcher
        document.querySelectorAll('[data-profile-tab]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tab = btn.getAttribute('data-profile-tab');
                document.querySelectorAll('[data-profile-tab]').forEach(function (b) {
                    b.classList.remove('active');
                });
                btn.classList.add('active');

                var map = {
                    'personal': 'sectionPersonal',
                    'security': 'sectionSecurity',
                    'bank': 'sectionBank',
                    'transfer': 'sectionTransfer',
                    'notifications': 'sectionNotifications',
                    'sessions': 'sectionSessions'
                };

                ['sectionPersonal', 'sectionSecurity', 'sectionBank', 'sectionTransfer', 'sectionNotifications', 'sectionSessions'].forEach(function (id) {
                    var el = document.getElementById(id);
                    if (el) el.style.display = 'none';
                });

                if (map[tab]) {
                    var target = document.getElementById(map[tab]);
                    if (target) target.style.display = 'block';
                }
            });
        });

        // Swap button
        var swapBtn = document.getElementById('swapTransferDirectionBtn');
        if (swapBtn) {
            swapBtn.addEventListener('click', function () {
                var dirLabel = document.getElementById('transferDirectionLabel');
                var fromWalletName = document.getElementById('transferFromWalletName');
                var toWalletName = document.getElementById('transferToWalletName');

                if (transferDirection === 'cash_to_win') {
                    transferDirection = 'win_to_cash';
                    if (dirLabel) dirLabel.textContent = 'Win to Cash Transfer (ถอนเงินรางวัลเข้ากระเป๋าหลัก)';
                    if (fromWalletName) fromWalletName.textContent = 'Winning Wallet (เงินรางวัล)';
                    if (toWalletName) toWalletName.textContent = 'Main Cash Wallet (กระเป๋าหลัก)';
                } else {
                    transferDirection = 'cash_to_win';
                    if (dirLabel) dirLabel.textContent = 'Cash to Win Transfer (โอนเงินสดเข้ากระเป๋าเดิมพัน)';
                    if (fromWalletName) fromWalletName.textContent = 'Main Cash Wallet (กระเป๋าหลัก)';
                    if (toWalletName) toWalletName.textContent = 'Winning Wallet (เงินรางวัล)';
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initProfile);
    } else {
        initProfile();
    }
})(window, document);
