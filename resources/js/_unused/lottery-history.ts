/**
 * Lottery Platform Member Lottery Bet History & Slip Verification Controller
 */

export interface SlipBetItem {
    id: string;
    play_type: string;
    play_label: string;
    number: string;
    stake: number;
    rate: number;
    potential_payout: number;
    payout?: number;
    status: 'pending' | 'won' | 'lost' | 'cancelled';
    winning_number?: string;
}

export interface WagerSlip {
    slip_id: string;
    market_id: string;
    market_name: string;
    draw_date: string;
    draw_time: string;
    created_at: string;
    status: 'pending' | 'won' | 'lost' | 'cancelled';
    status_label: string;
    total_stake: number;
    total_payout: number;
    potential_win: number;
    can_cancel: boolean;
    items: SlipBetItem[];
}

export class LotteryHistoryManager {
    private currentStatusFilter: string = 'all';
    private currentMarketFilter: string = 'all';
    private currentDrawDateFilter: string = '';
    private searchQuery: string = '';
    private csrfToken: string = '';

    constructor() {
        const tokenMeta = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null;
        this.csrfToken = tokenMeta?.content || '';
        this.initEventListeners();
    }

    private initEventListeners(): void {
        // Status filter pills
        const pills = document.querySelectorAll<HTMLButtonElement>('[data-lh-status]');
        pills.forEach((pill) => {
            pill.addEventListener('click', () => {
                pills.forEach((p) => p.classList.remove('active'));
                pill.classList.add('active');
                this.currentStatusFilter = pill.getAttribute('data-lh-status') || 'all';
                this.fetchAndRenderSlips();
            });
        });

        // Market filter dropdown
        const marketSelect = document.getElementById('marketSelectFilter') as HTMLSelectElement | null;
        marketSelect?.addEventListener('change', () => {
            this.currentMarketFilter = marketSelect.value;
            this.fetchAndRenderSlips();
        });

        // Draw date dropdown
        const dateSelect = document.getElementById('drawDateSelectFilter') as HTMLSelectElement | null;
        dateSelect?.addEventListener('change', () => {
            this.currentDrawDateFilter = dateSelect.value;
            this.fetchAndRenderSlips();
        });

        // Search input
        const searchInput = document.getElementById('historySearchInput') as HTMLInputElement | null;
        let searchDebounce: any = null;
        searchInput?.addEventListener('input', () => {
            clearTimeout(searchDebounce);
            searchDebounce = setTimeout(() => {
                this.searchQuery = searchInput.value.trim();
                this.fetchAndRenderSlips();
            }, 300);
        });

        // Refresh button
        const refreshBtn = document.getElementById('historyRefreshBtn');
        refreshBtn?.addEventListener('click', () => {
            this.fetchAndRenderSlips();
        });
    }

    public async fetchAndRenderSlips(): Promise<void> {
        try {
            const params = new URLSearchParams();
            if (this.currentStatusFilter !== 'all') params.append('status', this.currentStatusFilter);
            if (this.currentMarketFilter !== 'all') params.append('market', this.currentMarketFilter);
            if (this.currentDrawDateFilter) params.append('draw_date', this.currentDrawDateFilter);
            if (this.searchQuery) params.append('q', this.searchQuery);

            const res = await fetch(`/api/v1/player/history?${params.toString()}`, {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
            });

            if (!res.ok) throw new Error('Failed to load history');
            const data = await res.json();
            this.renderSlipsList(data.data || []);
        } catch (err) {
            console.error('Error fetching slips:', err);
        }
    }

    private renderSlipsList(slips: WagerSlip[]): void {
        const container = document.getElementById('slipsContainer');
        if (!container) return;

        if (slips.length === 0) {
            container.innerHTML = `
                <div class="lh-slip-card text-center py-12">
                    <div class="text-4xl mb-2">📜</div>
                    <div class="text-white font-bold text-base">No Lottery Wager Records Found</div>
                    <div class="text-slate-400 text-xs mt-1">Try adjusting your filters or date selection.</div>
                </div>
            `;
            return;
        }

        container.innerHTML = slips.map((slip) => {
            const badgeClass = slip.status === 'won' ? 'won' : slip.status === 'lost' ? 'lost' : 'pending';
            const cardStatusClass = `status-${slip.status}`;

            return `
                <div class="lh-slip-card ${cardStatusClass}" id="slip-${slip.slip_id}">
                    <div class="lh-slip-head">
                        <div class="lh-slip-id-wrap">
                            <span class="lh-slip-ref">${slip.slip_id}</span>
                            <span class="lh-status-badge ${badgeClass}">${slip.status_label}</span>
                        </div>
                        <div class="text-xs text-slate-400 font-mono">
                            Draw Date: <span class="font-bold text-white">${slip.draw_date} (${slip.draw_time})</span>
                        </div>
                    </div>

                    <div class="text-xs font-bold text-amber-400">
                        ${slip.market_name}
                    </div>

                    <table class="lh-items-table">
                        <thead>
                            <tr>
                                <th>Play Type (ประเภท)</th>
                                <th>Number (เลข)</th>
                                <th>Stake (บาท)</th>
                                <th>Odds (จ่าย)</th>
                                <th>Result / Payout</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${slip.items.map((item) => `
                                <tr>
                                    <td><span class="font-bold text-white">${item.play_label}</span></td>
                                    <td><span class="lh-num-badge">${item.number}</span></td>
                                    <td class="font-mono">${item.stake.toFixed(2)} ฿</td>
                                    <td class="font-mono text-amber-400">x${item.rate}</td>
                                    <td>
                                        ${item.status === 'won'
                                            ? `<span class="font-mono font-bold text-emerald-400">+${(item.payout || 0).toLocaleString()} ฿</span>`
                                            : item.status === 'lost'
                                            ? `<span class="text-slate-500 font-bold">0.00 ฿</span>`
                                            : `<span class="text-amber-400 text-xs font-bold">Pending</span>`
                                        }
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>

                    <div class="lh-slip-foot">
                        <div class="flex items-center gap-4 text-xs font-mono">
                            <div>Total Stake: <span class="font-bold text-white">${slip.total_stake.toFixed(2)} THB</span></div>
                            <div>Total Payout: <span class="font-bold ${slip.total_payout > 0 ? 'text-emerald-400' : 'text-slate-400'}">${slip.total_payout.toFixed(2)} THB</span></div>
                        </div>

                        <div class="lh-slip-actions">
                            ${slip.can_cancel ? `
                                <button type="button" class="lh-action-btn danger" onclick="window.lotteryHistory.cancelSlip('${slip.slip_id}')">
                                    Cancel Slip
                                </button>
                            ` : ''}
                            <button type="button" class="lh-action-btn" onclick="window.lotteryHistory.rebetSlip('${slip.slip_id}')">
                                🔁 Re-Bet (แทงซ้ำ)
                            </button>
                            <button type="button" class="lh-action-btn primary" onclick="window.lotteryHistory.openSlipDetailModal('${slip.slip_id}')">
                                View Official Slip
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    public async cancelSlip(slipId: string): Promise<void> {
        if (!confirm(`Are you sure you want to cancel and refund slip ${slipId}?`)) return;

        try {
            const res = await fetch(`/api/v1/player/history/cancel/${slipId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
            });
            const data = await res.json();
            if (data.success) {
                alert(data.message);
                this.fetchAndRenderSlips();
            } else {
                alert(data.message || 'Could not cancel slip');
            }
        } catch (e) {
            alert('Failed to cancel slip.');
        }
    }

    public async rebetSlip(slipId: string): Promise<void> {
        try {
            const res = await fetch(`/api/v1/player/history/rebet/${slipId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
            });
            const data = await res.json();
            if (data.success && data.redirect) {
                window.location.href = data.redirect;
            }
        } catch (e) {
            alert('Could not re-bet slip.');
        }
    }

    public openSlipDetailModal(slipId: string): void {
        const modal = document.getElementById('slipDetailModal');
        if (modal) modal.style.display = 'flex';
    }

    public closeSlipDetailModal(): void {
        const modal = document.getElementById('slipDetailModal');
        if (modal) modal.style.display = 'none';
    }
}

declare global {
    interface Window {
        lotteryHistory: LotteryHistoryManager;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    window.lotteryHistory = new LotteryHistoryManager();
});
