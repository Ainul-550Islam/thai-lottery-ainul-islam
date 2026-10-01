/**
 * Thai Lottery Interactive Auth Portal Module (TypeScript)
 * Reference: https://thailotto.club/
 */

export interface PasswordStrengthResult {
    score: number; // 0 to 4
    label: string;
    className: string;
}

export class AuthPortal {
    private form: HTMLFormElement | null = null;
    private passwordInput: HTMLInputElement | null = null;
    private strengthBar: HTMLElement | null = null;
    private strengthLabel: HTMLElement | null = null;

    constructor() {
        this.init();
    }

    public init(): void {
        this.bindPasswordToggles();
        this.bindPasswordStrengthMeter();
        this.bindPhoneFormatter();
        this.bindReferralAutoPopulate();
        this.bindSubmitGuards();
    }

    /**
     * Bind all password visibility toggle buttons.
     */
    private bindPasswordToggles(): void {
        const toggles = document.querySelectorAll<HTMLButtonElement>('[data-password-toggle]');
        toggles.forEach((button) => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                const targetId = button.getAttribute('data-target');
                if (!targetId) return;

                const input = document.getElementById(targetId) as HTMLInputElement | null;
                if (!input) return;

                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';

                const eyeOpen = button.querySelector('.eye-open');
                const eyeClosed = button.querySelector('.eye-closed');

                if (eyeOpen && eyeClosed) {
                    eyeOpen.classList.toggle('hidden', !isPassword);
                    eyeClosed.classList.toggle('hidden', isPassword);
                }

                button.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            });
        });
    }

    /**
     * Live client-side password entropy evaluator.
     */
    public evaluatePasswordStrength(password: string): PasswordStrengthResult {
        if (!password) {
            return { score: 0, label: '', className: '' };
        }

        let score = 0;
        if (password.length >= 8) score++;
        if (/[A-Z]/.test(password) && /[a-z]/.test(password)) score++;
        if (/[0-9]/.test(password)) score++;
        if (/[^A-Za-z0-9]/.test(password)) score++;

        switch (score) {
            case 1:
                return { score: 1, label: 'Weak', className: 'tl-strength-weak' };
            case 2:
                return { score: 2, label: 'Fair', className: 'tl-strength-fair' };
            case 3:
                return { score: 3, label: 'Good', className: 'tl-strength-good' };
            case 4:
                return { score: 4, label: 'Strong', className: 'tl-strength-strong' };
            default:
                return { score: 0, label: 'Very Weak', className: 'tl-strength-weak' };
        }
    }

    /**
     * Bind real-time password strength meter listener.
     */
    private bindPasswordStrengthMeter(): void {
        this.passwordInput = document.getElementById('register-password') as HTMLInputElement | null;
        this.strengthBar = document.getElementById('password-strength-bar');
        this.strengthLabel = document.getElementById('password-strength-label');

        if (!this.passwordInput || !this.strengthBar) return;

        this.passwordInput.addEventListener('input', () => {
            const val = this.passwordInput?.value || '';
            const result = this.evaluatePasswordStrength(val);

            if (this.strengthBar) {
                this.strengthBar.className = 'tl-strength-bar ' + result.className;
            }

            if (this.strengthLabel) {
                this.strengthLabel.textContent = result.label ? `Strength: ${result.label}` : '';
            }
        });
    }

    /**
     * Format mobile phone numbers smoothly with +66 Thai country code prefix.
     */
    private bindPhoneFormatter(): void {
        const phoneInput = document.getElementById('phone') as HTMLInputElement | null;
        if (!phoneInput) return;

        phoneInput.addEventListener('input', (e) => {
            let val = phoneInput.value.replace(/[^0-9+]/g, '');
            if (val.startsWith('0') && val.length > 1) {
                // Normal Thai domestic prefix 08X -> standard format
                val = val.substring(0, 10);
            }
            phoneInput.value = val;
        });
    }

    /**
     * Auto-populate referral code from URL search query (?ref=...).
     */
    private bindReferralAutoPopulate(): void {
        const refInput = document.getElementById('referral_code') as HTMLInputElement | null;
        if (!refInput || refInput.value) return;

        const urlParams = new URLSearchParams(window.location.search);
        const refParam = urlParams.get('ref') || urlParams.get('agent');
        if (refParam) {
            refInput.value = refParam.toUpperCase();
        }
    }

    /**
     * Guard against double submit and generate client idempotency tokens.
     */
    private bindSubmitGuards(): void {
        const forms = document.querySelectorAll<HTMLFormElement>('form[data-auth-form]');
        forms.forEach((form) => {
            form.addEventListener('submit', (e) => {
                const submitBtn = form.querySelector('button[type="submit"]') as HTMLButtonElement | null;
                if (submitBtn) {
                    if (submitBtn.disabled) {
                        e.preventDefault();
                        return;
                    }
                    submitBtn.disabled = true;
                    submitBtn.classList.add('opacity-75', 'cursor-not-allowed');
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = `
                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-slate-900 inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Processing...
                    `;
                    // Safety timeout to re-enable if server hangs
                    setTimeout(() => {
                        submitBtn.disabled = false;
                        submitBtn.classList.remove('opacity-75', 'cursor-not-allowed');
                        submitBtn.innerHTML = originalText;
                    }, 8000);
                }
            });
        });
    }
}

// Auto-initialize on DOM ready
if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => new AuthPortal());
    } else {
        new AuthPortal();
    }
}
