/**
 * LOTTOFIN ADMIN — Live Financial Reconciliation & Analytics Controller (TypeScript)
 */

export interface ReconciliationRecord {
    id: string;
    dateTime: string;
    transactionId: string;
    sourceAccount: string;
    ledgerAccount: string;
    debit: string;
    credit: string;
    systemBal: string;
    bankBal: string;
    discrepancy: string;
    status: 'Balanced' | 'Unbalanced' | 'Pending';
}

export interface ExecutiveKpiData {
    totalWagered: string;
    totalWageredTrend: string;
    houseGrossProfit: string;
    houseGrossProfitTrend: string;
    activeInPlayBets: number;
    activeInPlayBetsNew: number;
    pendingWithdrawals: number;
    pendingWithdrawalsTrend: string;
}

export class LottoFinAdminDashboard {
    private searchInput: HTMLInputElement | null = null;
    private tableBody: HTMLTableSectionElement | null = null;
    private records: ReconciliationRecord[] = [];
    private filteredRecords: ReconciliationRecord[] = [];

    constructor() {
        this.init();
    }

    public init(): void {
        this.setupSidebarToggle();
        this.setupSearch();
        this.setupLiveReconciliationStream();
        this.setupActionModals();
    }

    private setupSidebarToggle(): void {
        const toggleBtn = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('adminSidebar');
        if (toggleBtn && sidebar) {
            toggleBtn.addEventListener('click', () => {
                sidebar.classList.toggle('hidden');
            });
        }
    }

    private setupSearch(): void {
        this.searchInput = document.getElementById('reconciliationSearch') as HTMLInputElement | null;
        this.tableBody = document.getElementById('reconciliationTableBody') as HTMLTableSectionElement | null;

        if (this.searchInput) {
            this.searchInput.addEventListener('input', () => {
                const query = this.searchInput?.value.toLowerCase().trim() || '';
                this.filterRecords(query);
            });
        }
    }

    private filterRecords(query: string): void {
        if (!this.tableBody) return;
        const rows = this.tableBody.querySelectorAll<HTMLTableRowElement>('tr');
        rows.forEach((row) => {
            const text = row.textContent?.toLowerCase() || '';
            row.style.display = text.includes(query) ? '' : 'none';
        });
    }

    /**
     * Poll real-time reconciliation updates from backend API.
     */
    private setupLiveReconciliationStream(): void {
        const fetchUpdates = async () => {
            try {
                const response = await fetch('/api/v1/admin/analytics/dashboard', {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    }
                });
                if (response.ok) {
                    const data = await response.json();
                    this.updateKpiElements(data.kpis);
                }
            } catch (error) {
                // Background quiet fail on network timeout
            }
        };

        // Poll every 15 seconds
        setInterval(fetchUpdates, 15000);
    }

    private updateKpiElements(kpis?: ExecutiveKpiData): void {
        if (!kpis) return;
        const totalWageredEl = document.getElementById('kpiTotalWagered');
        const houseProfitEl = document.getElementById('kpiHouseProfit');
        const activeBetsEl = document.getElementById('kpiActiveBets');
        const pendingWdEl = document.getElementById('kpiPendingWithdrawals');

        if (totalWageredEl) totalWageredEl.textContent = kpis.totalWagered;
        if (houseProfitEl) houseProfitEl.textContent = kpis.houseGrossProfit;
        if (activeBetsEl) activeBetsEl.textContent = kpis.activeInPlayBets.toLocaleString();
        if (pendingWdEl) pendingWdEl.textContent = kpis.pendingWithdrawals.toString();
    }

    private setupActionModals(): void {
        const actionBtns = document.querySelectorAll('[data-action-trigger]');
        actionBtns.forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const action = btn.getAttribute('data-action-trigger');
                if (action === 'export-csv') {
                    this.exportTableToCSV();
                } else if (action === 'reconcile-now') {
                    this.triggerInstantReconciliation();
                }
            });
        });
    }

    public exportTableToCSV(): void {
        let csv = 'Date/Time,Transaction ID,Source Account,Ledger Account,Debit,Credit,System Bal,Bank Bal,Discrepancy,Status\n';
        const rows = document.querySelectorAll('#reconciliationTableBody tr');
        rows.forEach((row) => {
            const cols = row.querySelectorAll('td');
            const rowData: string[] = [];
            cols.forEach((col) => {
                rowData.push('"' + (col.textContent?.trim().replace(/"/g, '""') || '') + '"');
            });
            if (rowData.length > 0) {
                csv += rowData.join(',') + '\n';
            }
        });

        const blob = new Blob([csv], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.setAttribute('hidden', '');
        a.setAttribute('href', url);
        a.setAttribute('download', `reconciliation_${new Date().toISOString().slice(0, 10)}.csv`);
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    public async triggerInstantReconciliation(): Promise<void> {
        const btn = document.getElementById('reconcileNowBtn') as HTMLButtonElement | null;

        if (!btn) {
            return;
        }

        btn.disabled = true;
        alert('Reconciliation must be started from the authorized operations workflow. No reconciliation was run.');
        btn.disabled = false;
    }
}

// Auto-initialize on DOM ready
if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => new LottoFinAdminDashboard());
    } else {
        new LottoFinAdminDashboard();
    }
}
