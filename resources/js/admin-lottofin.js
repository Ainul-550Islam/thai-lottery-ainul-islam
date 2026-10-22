/**
 * LOTTOFIN ADMIN — Live Financial Reconciliation & Analytics Controller (JavaScript)
 */

(function (window, document) {
    'use strict';

    function initLottoFinDashboard() {
        // 1. Sidebar mobile toggle
        const toggleBtn = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('adminSidebar');
        if (toggleBtn && sidebar) {
            toggleBtn.addEventListener('click', function () {
                sidebar.classList.toggle('hidden');
            });
        }

        // 2. Real-time Search filter
        const searchInput = document.getElementById('reconciliationSearch');
        const tableBody = document.getElementById('reconciliationTableBody');

        if (searchInput && tableBody) {
            searchInput.addEventListener('input', function () {
                const query = searchInput.value.toLowerCase().trim();
                const rows = tableBody.querySelectorAll('tr');
                rows.forEach(function (row) {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.indexOf(query) !== -1 ? '' : 'none';
                });
            });
        }

        // 3. Dropdown Action Hooks
        const actionDropdownBtn = document.getElementById('mainActionsBtn');
        const actionDropdownMenu = document.getElementById('mainActionsMenu');

        if (actionDropdownBtn && actionDropdownMenu) {
            actionDropdownBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                actionDropdownMenu.classList.toggle('hidden');
            });

            document.addEventListener('click', function () {
                actionDropdownMenu.classList.add('hidden');
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initLottoFinDashboard);
    } else {
        initLottoFinDashboard();
    }
})(window, document);
