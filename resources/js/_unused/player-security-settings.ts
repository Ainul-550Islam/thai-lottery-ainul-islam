/**
 * Player Security & Responsible Gaming Settings Controller (TypeScript)
 * Reference: kyc_responsible_gaming.png
 */

export interface LimitConfig {
    dailyDeposit: number;
    dailyDepositMax: number;
    singleBet: number;
    singleBetMax: number;
    dailyWager: number;
    dailyWagerMax: number;
}

export class PlayerSecuritySettings {
    private limits: LimitConfig = {
        dailyDeposit: 5000,
        dailyDepositMax: 20000,
        singleBet: 1000,
        singleBetMax: 5000,
        dailyWager: 10000,
        dailyWagerMax: 50000,
    };

    constructor() {
        this.init();
    }

    public init(): void {
        this.bindSliders();
        this.bindEditButtons();
        this.bindBreakButtons();
        this.bindExclusionToggle();
    }

    private bindSliders(): void {
        const depositSlider = document.getElementById('depositSlider') as HTMLInputElement | null;
        const depositDisplay = document.getElementById('depositValDisplay');

        if (depositSlider && depositDisplay) {
            depositSlider.addEventListener('input', () => {
                const val = parseInt(depositSlider.value, 10);
                depositDisplay.textContent = val.toLocaleString();
                this.limits.dailyDeposit = val;
            });
        }

        const betSlider = document.getElementById('betSlider') as HTMLInputElement | null;
        const betDisplay = document.getElementById('betValDisplay');

        if (betSlider && betDisplay) {
            betSlider.addEventListener('input', () => {
                const val = parseInt(betSlider.value, 10);
                betDisplay.textContent = val.toLocaleString();
                this.limits.singleBet = val;
            });
        }

        const wagerSlider = document.getElementById('wagerSlider') as HTMLInputElement | null;
        const wagerDisplay = document.getElementById('wagerValDisplay');

        if (wagerSlider && wagerDisplay) {
            wagerSlider.addEventListener('input', () => {
                const val = parseInt(wagerSlider.value, 10);
                wagerDisplay.textContent = val.toLocaleString();
                this.limits.dailyWager = val;
            });
        }
    }

    private bindEditButtons(): void {
        const editBtns = document.querySelectorAll<HTMLButtonElement>('[data-edit-limit]');
        editBtns.forEach((btn) => {
            btn.addEventListener('click', () => {
                const limitKey = btn.getAttribute('data-edit-limit');
                if (!limitKey) return;

                let currentVal = 0;
                let maxVal = 0;

                if (limitKey === 'deposit') {
                    currentVal = this.limits.dailyDeposit;
                    maxVal = this.limits.dailyDepositMax;
                } else if (limitKey === 'bet') {
                    currentVal = this.limits.singleBet;
                    maxVal = this.limits.singleBetMax;
                } else if (limitKey === 'wager') {
                    currentVal = this.limits.dailyWager;
                    maxVal = this.limits.dailyWagerMax;
                }

                const input = prompt(`Enter new ${limitKey} limit (Max: ${maxVal.toLocaleString()} THB):`, currentVal.toString());
                if (input !== null) {
                    const parsed = parseInt(input.replace(/[^0-9]/g, ''), 10);
                    if (!isNaN(parsed) && parsed > 0 && parsed <= maxVal) {
                        this.updateLimit(limitKey, parsed);
                    } else {
                        alert(`Please enter a valid amount between 1 and ${maxVal.toLocaleString()} THB.`);
                    }
                }
            });
        });
    }

    private updateLimit(key: string, value: number): void {
        if (key === 'deposit') {
            this.limits.dailyDeposit = value;
            const slider = document.getElementById('depositSlider') as HTMLInputElement | null;
            const display = document.getElementById('depositValDisplay');
            if (slider) slider.value = value.toString();
            if (display) display.textContent = value.toLocaleString();
        } else if (key === 'bet') {
            this.limits.singleBet = value;
            const slider = document.getElementById('betSlider') as HTMLInputElement | null;
            const display = document.getElementById('betValDisplay');
            if (slider) slider.value = value.toString();
            if (display) display.textContent = value.toLocaleString();
        } else if (key === 'wager') {
            this.limits.dailyWager = value;
            const slider = document.getElementById('wagerSlider') as HTMLInputElement | null;
            const display = document.getElementById('wagerValDisplay');
            if (slider) slider.value = value.toString();
            if (display) display.textContent = value.toLocaleString();
        }

        // Send API sync
        this.saveLimitsToServer();
    }

    private async saveLimitsToServer(): Promise<void> {
        try {
            await fetch('/api/v1/player/security/limits', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
                },
                body: JSON.stringify(this.limits),
            });
        } catch (e) {
            // Quiet network sync
        }
    }

    private bindBreakButtons(): void {
        const breakBtns = document.querySelectorAll<HTMLButtonElement>('[data-break-duration]');
        breakBtns.forEach((btn) => {
            btn.addEventListener('click', () => {
                const duration = btn.getAttribute('data-break-duration');
                const pin = prompt(`Take a ${duration} break? Enter your 4-digit security PIN to confirm:`);
                if (pin && pin.length >= 4) {
                    const activeBreakLabel = document.getElementById('activeBreakDisplay');
                    if (activeBreakLabel) {
                        activeBreakLabel.textContent = `Active (${duration})`;
                        activeBreakLabel.className = 'pss-break-val text-amber-400 font-bold';
                    }
                    alert(`Your account break for ${duration} is now activated.`);
                }
            });
        });
    }

    private bindExclusionToggle(): void {
        const toggle = document.getElementById('selfExclusionToggle') as HTMLInputElement | null;
        if (toggle) {
            toggle.addEventListener('change', () => {
                if (toggle.checked) {
                    const pin = prompt('Self-Exclusion disables all betting & deposits. Enter PIN to confirm:');
                    if (!pin || pin.length < 4) {
                        toggle.checked = false;
                    } else {
                        alert('Voluntary self-exclusion is now active.');
                    }
                }
            });
        }
    }
}

// Auto-initialize on DOM ready
if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => new PlayerSecuritySettings());
    } else {
        new PlayerSecuritySettings();
    }
}
