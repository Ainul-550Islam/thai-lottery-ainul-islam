/**
 * Wallet management client.
 *
 * This UI consumes the canonical authenticated wallet, deposit and withdrawal
 * APIs. It never fabricates references, applies optimistic money mutations or
 * treats a failed network request as a successful financial operation.
 */

interface ApiErrorEnvelope {
    success: false;
    error?: {
        code?: string;
        message?: string;
        details?: Record<string, unknown>;
    };
    message?: string;
}

interface WalletContract {
    available_balance: string;
    currency: string;
}

interface WalletEnvelope {
    success: true;
    data: {
        wallet: WalletContract;
    };
}

interface DepositEnvelope {
    success: true;
    data: {
        deposit: {
            reference_number: string;
            status: string;
        };
        checkout?: {
            redirect_url?: string;
        };
    };
}

interface WithdrawalEnvelope {
    success: true;
    data: {
        withdrawal: {
            reference_number: string;
            status: string;
        };
        kyc_detained: boolean;
    };
}

export class WalletManagement {
    private activeTab: 'deposit' | 'withdraw' = 'deposit';
    private selectedAmount = '500';
    private selectedMethod = 'stripe';
    private submitting = false;

    constructor() {
        this.init();
    }

    public init(): void {
        this.bindTabs();
        this.bindGateways();
        this.bindAmountPresets();
        this.bindMethodDropdown();
        this.bindProceedButton();
        void this.refreshWallet();
    }

    private bindTabs(): void {
        const tabs = document.querySelectorAll<HTMLButtonElement>('[data-tab-name]');
        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                const name = tab.getAttribute('data-tab-name');
                if (name !== 'deposit' && name !== 'withdraw') return;

                this.activeTab = name;
                tabs.forEach((item) => item.classList.remove('active'));
                tab.classList.add('active');

                const text = name === 'deposit' ? 'Proceed to Deposit' : 'Proceed to Withdraw';
                const buttonText = document.getElementById('proceedBtnText');
                const buttonLabel = document.getElementById('proceedBtnLabel');
                if (buttonText) buttonText.textContent = text;
                if (buttonLabel) buttonLabel.textContent = text;
            });
        });
    }

    private bindGateways(): void {
        const buttons = document.querySelectorAll<HTMLButtonElement>('[data-gateway]');
        buttons.forEach((button) => {
            button.addEventListener('click', () => {
                const method = button.getAttribute('data-gateway');
                if (!method) return;

                this.selectedMethod = method;
                buttons.forEach((item) => item.classList.remove('active'));
                button.classList.add('active');

                const select = document.getElementById('paymentMethodSelect') as HTMLSelectElement | null;
                if (select && Array.from(select.options).some((option) => option.value === method)) {
                    select.value = method;
                }
            });
        });
    }

    private bindAmountPresets(): void {
        const pills = document.querySelectorAll<HTMLButtonElement>('[data-preset-amount]');
        pills.forEach((pill) => {
            pill.addEventListener('click', () => {
                const amount = pill.getAttribute('data-preset-amount');
                if (!amount || !/^\d+(\.\d{1,2})?$/.test(amount)) return;

                this.selectedAmount = amount;
                pills.forEach((item) => item.classList.remove('active'));
                pill.classList.add('active');
            });
        });
    }

    private bindMethodDropdown(): void {
        const select = document.getElementById('paymentMethodSelect') as HTMLSelectElement | null;
        if (!select) return;

        this.selectedMethod = select.value;
        select.addEventListener('change', () => {
            this.selectedMethod = select.value;
        });
    }

    private bindProceedButton(): void {
        document.getElementById('proceedPaymentBtn')?.addEventListener('click', () => {
            void this.handleProceed();
        });
    }

    private async handleProceed(): Promise<void> {
        if (this.submitting) return;

        const rawAmount = prompt(`Enter ${this.activeTab} amount:`, this.selectedAmount);
        if (rawAmount === null) return;

        const amount = rawAmount.trim();
        if (!/^\d+(\.\d{1,2})?$/.test(amount) || /^0+(\.0{1,2})?$/.test(amount)) {
            alert('Enter a positive decimal amount with no more than two fractional digits.');
            return;
        }

        this.submitting = true;
        this.setProceedDisabled(true);

        try {
            if (this.activeTab === 'deposit') {
                const result = await this.post<DepositEnvelope>('/api/v1/deposits', {
                    amount,
                    method: this.selectedMethod,
                    idempotency_key: this.idempotencyKey('deposit'),
                });
                alert(`Deposit ${result.data.deposit.reference_number} is ${result.data.deposit.status}.`);
            } else {
                const result = await this.post<WithdrawalEnvelope>('/api/v1/withdrawals', {
                    amount,
                    method: this.selectedMethod,
                    idempotency_key: this.idempotencyKey('withdrawal'),
                });
                const suffix = result.data.kyc_detained ? ' Identity verification is required.' : '';
                alert(`Withdrawal ${result.data.withdrawal.reference_number} is ${result.data.withdrawal.status}.${suffix}`);
            }

            await this.refreshWallet();
        } catch (error) {
            alert(error instanceof Error ? error.message : 'The financial request could not be completed.');
        } finally {
            this.submitting = false;
            this.setProceedDisabled(false);
        }
    }

    private async refreshWallet(): Promise<void> {
        try {
            const response = await fetch('/api/v1/wallet', {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const payload = await response.json() as WalletEnvelope | ApiErrorEnvelope;
            if (!response.ok || !payload.success) return;

            const wallet = payload.data.wallet;
            const display = document.getElementById('availableBalanceDisplay');
            if (display) display.textContent = `${wallet.available_balance} ${wallet.currency}`;
        } catch {
            // The server-rendered balance remains visible when refresh is unavailable.
        }
    }

    private async post<T extends { success: true }>(url: string, body: Record<string, unknown>): Promise<T> {
        const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        });
        const payload = await response.json() as T | ApiErrorEnvelope;

        if (!response.ok || !payload.success) {
            const failure = payload as ApiErrorEnvelope;
            throw new Error(failure.error?.message ?? failure.message ?? 'The financial request was rejected.');
        }

        return payload as T;
    }

    private idempotencyKey(action: string): string {
        if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
            return `${action}:${crypto.randomUUID()}`;
        }

        throw new Error('This browser cannot create a secure request identifier.');
    }

    private setProceedDisabled(disabled: boolean): void {
        const button = document.getElementById('proceedPaymentBtn') as HTMLButtonElement | null;
        if (button) button.disabled = disabled;
    }
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => new WalletManagement());
    } else {
        new WalletManagement();
    }
}
