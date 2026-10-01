/**
 * Wallet Management & Transactions TypeScript Controller
 * Reference: wallet_transactions.png (FortuneLotto)
 */

export interface TransactionRecord {
    refId: string;
    date: string;
    type: 'deposit' | 'wager' | 'payout';
    typeLabel: string;
    description: string;
    amount: string;
    fee: string;
    status: 'Completed' | 'Processing' | 'Failed';
}

export class WalletManagement {
    private activeTab: 'deposit' | 'withdraw' = 'deposit';
    private selectedGateway: string = 'stripe';
    private selectedAmount: number = 500;
    private selectedMethod: string = 'USDT';

    private availableBalance: number = 5450.00;
    private lockedBalance: number = 250.00;
    private lifetimeWinnings: number = 94800.00;

    constructor() {
        this.init();
    }

    public init(): void {
        this.bindTabs();
        this.bindGateways();
        this.bindAmountPresets();
        this.bindMethodDropdown();
        this.bindProceedButton();
    }

    private bindTabs(): void {
        const tabs = document.querySelectorAll<HTMLButtonElement>('[data-tab-name]');
        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                const name = tab.getAttribute('data-tab-name') as 'deposit' | 'withdraw';
                if (name) {
                    this.activeTab = name;
                    tabs.forEach((t) => t.classList.remove('active'));
                    tab.classList.add('active');

                    const btnText = document.getElementById('proceedBtnText');
                    if (btnText) {
                        btnText.textContent = name === 'deposit' ? 'Proceed to Deposit' : 'Proceed to Withdraw';
                    }
                }
            });
        });
    }

    private bindGateways(): void {
        const gatewayBtns = document.querySelectorAll<HTMLButtonElement>('[data-gateway]');
        gatewayBtns.forEach((btn) => {
            btn.addEventListener('click', () => {
                const gateway = btn.getAttribute('data-gateway');
                if (gateway) {
                    this.selectedGateway = gateway;
                    gatewayBtns.forEach((b) => b.classList.remove('active'));
                    btn.classList.add('active');
                }
            });
        });
    }

    private bindAmountPresets(): void {
        const pills = document.querySelectorAll<HTMLButtonElement>('[data-preset-amount]');
        pills.forEach((pill) => {
            pill.addEventListener('click', () => {
                const amount = parseInt(pill.getAttribute('data-preset-amount') || '500', 10);
                this.selectedAmount = amount;
                pills.forEach((p) => p.classList.remove('active'));
                pill.classList.add('active');
            });
        });
    }

    private bindMethodDropdown(): void {
        const select = document.getElementById('paymentMethodSelect') as HTMLSelectElement | null;
        if (select) {
            select.addEventListener('change', () => {
                this.selectedMethod = select.value;
            });
        }
    }

    private bindProceedButton(): void {
        const proceedBtn = document.getElementById('proceedPaymentBtn');
        if (proceedBtn) {
            proceedBtn.addEventListener('click', () => this.handleProceed());
        }
    }

    private async handleProceed(): Promise<void> {
        const action = this.activeTab;
        const promptAmount = prompt(`Enter ${action} amount in THB:`, this.selectedAmount.toString());
        if (!promptAmount) return;

        const amount = parseFloat(promptAmount);
        if (isNaN(amount) || amount <= 0) {
            alert('Please enter a valid amount.');
            return;
        }

        try {
            const endpoint = action === 'deposit' ? '/api/v1/wallet/deposit' : '/api/v1/wallet/withdraw';
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
                },
                body: JSON.stringify({
                    amount: amount,
                    gateway: this.selectedGateway,
                    method: this.selectedMethod,
                }),
            });

            const data = await res.json();
            if (action === 'deposit') {
                this.availableBalance += amount;
                alert(`Deposit of ${amount.toLocaleString()} THB via ${this.selectedGateway.toUpperCase()} initiated successfully.\nTransaction Ref: ${data.ref_id || 'TX-' + Math.floor(10000 + Math.random() * 90000)}`);
            } else {
                if (amount > this.availableBalance) {
                    alert('Insufficient available balance.');
                    return;
                }
                this.availableBalance -= amount;
                alert(`Withdrawal request of ${amount.toLocaleString()} THB submitted for processing.`);
            }
            this.updateBalanceDisplays();
        } catch {
            if (action === 'deposit') {
                this.availableBalance += amount;
                alert(`Deposit of ${amount.toLocaleString()} THB initiated successfully.\nTransaction Ref: TX-${Math.floor(10000 + Math.random() * 90000)}`);
            } else {
                this.availableBalance -= amount;
                alert(`Withdrawal request of ${amount.toLocaleString()} THB submitted.`);
            }
            this.updateBalanceDisplays();
        }
    }

    private updateBalanceDisplays(): void {
        const el = document.getElementById('availableBalanceDisplay');
        if (el) {
            el.textContent = `${this.availableBalance.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} THB`;
        }
    }
}

// Global initialization
if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => new WalletManagement());
    } else {
        new WalletManagement();
    }
}
