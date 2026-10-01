/**
 * Player Dashboard TypeScript Controller
 * Reference: player_dashboard.png (THAI LOTTERY)
 */

export interface WagerRecord {
    ticketId: string;
    date: string;
    numbers: string;
    type: string;
    amount: string;
    status: 'WON' | 'PENDING' | 'LOST';
    isWinnerMatch?: boolean;
}

export class PlayerDashboard {
    private remainingSeconds: number = 14 * 3600 + 48 * 60 + 2; // 14:48:02
    private timerInterval: number | null = null;

    constructor() {
        this.init();
    }

    public init(): void {
        this.startCountdown();
        this.bindActions();
    }

    private startCountdown(): void {
        const timerEl = document.getElementById('countdownTimerDisplay');
        if (!timerEl) return;

        this.timerInterval = window.setInterval(() => {
            if (this.remainingSeconds <= 0) {
                if (this.timerInterval) clearInterval(this.timerInterval);
                timerEl.textContent = '00:00:00';
                return;
            }

            this.remainingSeconds--;
            const hours = Math.floor(this.remainingSeconds / 3600);
            const minutes = Math.floor((this.remainingSeconds % 3600) / 60);
            const seconds = this.remainingSeconds % 60;

            const pad = (n: number) => n.toString().padStart(2, '0');
            timerEl.textContent = `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
        }, 1000);
    }

    private bindActions(): void {
        // Deposit action
        const depositBtn = document.getElementById('walletDepositBtn');
        if (depositBtn) {
            depositBtn.addEventListener('click', () => {
                const amount = prompt('Enter deposit amount in THB:', '1000');
                if (amount) {
                    const parsed = parseFloat(amount);
                    if (!isNaN(parsed) && parsed > 0) {
                        alert(`Deposit request for ${parsed.toLocaleString()} THB initiated. Proceeding to payment gateway.`);
                    }
                }
            });
        }

        // Cancel bet action
        const cancelBtns = document.querySelectorAll('[data-cancel-ticket]');
        cancelBtns.forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const ticketId = btn.getAttribute('data-cancel-ticket');
                if (confirm(`Are you sure you want to cancel ticket ${ticketId}? Refund will be credited immediately.`)) {
                    alert(`Ticket ${ticketId} cancelled. 100.00 THB refunded to wallet.`);
                    btn.closest('tr')?.remove();
                }
            });
        });
    }
}

// Global initialization
if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => new PlayerDashboard());
    } else {
        new PlayerDashboard();
    }
}
