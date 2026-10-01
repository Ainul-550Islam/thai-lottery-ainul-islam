/**
 * ThaiLotto Member Account & System Settings Controller
 */

export interface GeneralSettings {
    language: string;
    theme: string;
    timezone: string;
    currency_format: string;
    odds_format: string;
}

export interface SecuritySettings {
    two_factor_enabled: boolean;
    session_timeout: number;
    login_alert_email: boolean;
    login_alert_line: boolean;
    biometric_login: boolean;
}

export interface BettingSettings {
    quick_stakes: number[];
    fast_bet_mode: boolean;
    sound_effects: boolean;
    default_permutation_mode: string;
    auto_clear_bet_slip: boolean;
}

export interface NotificationSettings {
    prize_alert_sms: boolean;
    prize_alert_email: boolean;
    prize_alert_line: boolean;
    draw_broadcast: boolean;
    promo_alerts: boolean;
    line_notify_bound: boolean;
    line_notify_masked: string;
}

export interface ResponsibleGamingSettings {
    daily_deposit_limit: number;
    single_bet_limit: number;
    daily_wager_limit: number;
    daily_loss_limit: number;
    self_exclusion_active: boolean;
}

export class PlayerSettingsManager {
    private csrfToken: string = '';
    private activeTab: string = 'general';

    constructor() {
        const tokenMeta = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null;
        this.csrfToken = tokenMeta?.content || '';
        this.initTabs();
        this.initForms();
    }

    private initTabs(): void {
        const tabBtns = document.querySelectorAll<HTMLButtonElement>('[data-st-tab]');
        tabBtns.forEach((btn) => {
            btn.addEventListener('click', () => {
                const target = btn.getAttribute('data-st-tab') || 'general';
                this.switchTab(target);
            });
        });
    }

    public switchTab(target: string): void {
        this.activeTab = target;
        document.querySelectorAll<HTMLButtonElement>('[data-st-tab]').forEach((btn) => {
            if (btn.getAttribute('data-st-tab') === target) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        const sections = ['general', 'security', 'betting', 'notifications', 'responsible'];
        sections.forEach((sec) => {
            const el = document.getElementById(`section-${sec}`);
            if (el) {
                el.style.display = sec === target ? 'flex' : 'none';
            }
        });
    }

    private initForms(): void {
        // Form 1: General Settings
        const generalForm = document.getElementById('generalSettingsForm');
        generalForm?.addEventListener('submit', async (e) => {
            e.preventDefault();
            await this.saveGeneralSettings();
        });

        // Form 2: Security Settings
        const securityForm = document.getElementById('securitySettingsForm');
        securityForm?.addEventListener('submit', async (e) => {
            e.preventDefault();
            await this.saveSecuritySettings();
        });

        // Form 3: Betting Preferences
        const bettingForm = document.getElementById('bettingSettingsForm');
        bettingForm?.addEventListener('submit', async (e) => {
            e.preventDefault();
            await this.saveBettingPreferences();
        });

        // Form 4: Notification Settings
        const notifyForm = document.getElementById('notificationSettingsForm');
        notifyForm?.addEventListener('submit', async (e) => {
            e.preventDefault();
            await this.saveNotificationPreferences();
        });

        // Form 5: Responsible Gaming Limits
        const limitsForm = document.getElementById('responsibleLimitsForm');
        limitsForm?.addEventListener('submit', async (e) => {
            e.preventDefault();
            await this.saveResponsibleGamingLimits();
        });

        // LINE Notify Connect Form
        const lineForm = document.getElementById('lineNotifyForm');
        lineForm?.addEventListener('submit', async (e) => {
            e.preventDefault();
            await this.bindLineNotify();
        });
    }

    public async saveGeneralSettings(): Promise<void> {
        const language = (document.getElementById('settingLanguage') as HTMLSelectElement)?.value || 'th';
        const theme = (document.getElementById('settingTheme') as HTMLSelectElement)?.value || 'dark_gold';
        const timezone = (document.getElementById('settingTimezone') as HTMLSelectElement)?.value || 'Asia/Bangkok';
        const oddsFormat = (document.getElementById('settingOddsFormat') as HTMLSelectElement)?.value || 'multiplier';

        try {
            const res = await fetch('/api/v1/player/settings/general', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                body: JSON.stringify({ language, theme, timezone, odds_format: oddsFormat }),
            });
            const data = await res.json();
            alert(data.message || 'General settings saved successfully.');
        } catch (e) {
            alert('Failed to save general settings.');
        }
    }

    public async saveSecuritySettings(): Promise<void> {
        const sessionTimeout = parseInt((document.getElementById('settingSessionTimeout') as HTMLSelectElement)?.value || '30', 10);
        const loginAlertEmail = (document.getElementById('settingLoginEmail') as HTMLInputElement)?.checked ?? true;
        const loginAlertLine = (document.getElementById('settingLoginLine') as HTMLInputElement)?.checked ?? true;
        const biometricLogin = (document.getElementById('settingBiometric') as HTMLInputElement)?.checked ?? true;

        try {
            const res = await fetch('/api/v1/player/settings/security', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                body: JSON.stringify({
                    session_timeout: sessionTimeout,
                    login_alert_email: loginAlertEmail,
                    login_alert_line: loginAlertLine,
                    biometric_login: biometricLogin,
                }),
            });
            const data = await res.json();
            alert(data.message || 'Security settings saved.');
        } catch (e) {
            alert('Failed to update security settings.');
        }
    }

    public async toggle2fa(enable: boolean): Promise<void> {
        try {
            const res = await fetch('/api/v1/player/settings/2fa', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                body: JSON.stringify({ enable }),
            });
            const data = await res.json();
            if (data.requires_verification) {
                const code = prompt(`${data.message}\nSecret: ${data.secret_key}\nEnter 6-digit code:`);
                if (code) {
                    const confirmRes = await fetch('/api/v1/player/settings/2fa', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken,
                        },
                        body: JSON.stringify({ enable: true, code }),
                    });
                    const confirmData = await confirmRes.json();
                    alert(confirmData.message || '2FA setup finished.');
                }
            } else {
                alert(data.message || '2FA updated.');
            }
        } catch (e) {
            alert('Could not toggle 2FA.');
        }
    }

    public async saveBettingPreferences(): Promise<void> {
        const fastBetMode = (document.getElementById('settingFastBet') as HTMLInputElement)?.checked ?? false;
        const soundEffects = (document.getElementById('settingSounds') as HTMLInputElement)?.checked ?? true;
        const defaultPermutationMode = (document.getElementById('settingPermutation') as HTMLSelectElement)?.value || 'standard';
        const autoClearBetSlip = (document.getElementById('settingAutoClear') as HTMLInputElement)?.checked ?? true;

        try {
            const res = await fetch('/api/v1/player/settings/betting', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                body: JSON.stringify({
                    fast_bet_mode: fastBetMode,
                    sound_effects: soundEffects,
                    default_permutation_mode: defaultPermutationMode,
                    auto_clear_bet_slip: autoClearBetSlip,
                }),
            });
            const data = await res.json();
            alert(data.message || 'Betting preferences saved.');
        } catch (e) {
            alert('Failed to save betting preferences.');
        }
    }

    public async saveNotificationPreferences(): Promise<void> {
        const prizeAlertSms = (document.getElementById('settingPrizeSms') as HTMLInputElement)?.checked ?? true;
        const prizeAlertEmail = (document.getElementById('settingPrizeEmail') as HTMLInputElement)?.checked ?? true;
        const prizeAlertLine = (document.getElementById('settingPrizeLine') as HTMLInputElement)?.checked ?? true;
        const drawBroadcast = (document.getElementById('settingDrawBroadcast') as HTMLInputElement)?.checked ?? true;

        try {
            const res = await fetch('/api/v1/player/settings/notifications', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                body: JSON.stringify({
                    prize_alert_sms: prizeAlertSms,
                    prize_alert_email: prizeAlertEmail,
                    prize_alert_line: prizeAlertLine,
                    draw_broadcast: drawBroadcast,
                }),
            });
            const data = await res.json();
            alert(data.message || 'Notification preferences saved.');
        } catch (e) {
            alert('Failed to save notification settings.');
        }
    }

    public async bindLineNotify(): Promise<void> {
        const tokenInput = document.getElementById('inputLineToken') as HTMLInputElement | null;
        const token = tokenInput?.value.trim() || '';

        if (!token) {
            alert('Please enter your LINE Notify access token.');
            return;
        }

        try {
            const res = await fetch('/api/v1/player/settings/line-notify', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                body: JSON.stringify({ line_token: token }),
            });
            const data = await res.json();
            alert(data.message || 'LINE Notify connected!');
            if (tokenInput) tokenInput.value = '';
        } catch (e) {
            alert('Failed to bind LINE Notify.');
        }
    }

    public async saveResponsibleGamingLimits(): Promise<void> {
        const dailyDeposit = parseFloat((document.getElementById('inputDailyDeposit') as HTMLInputElement)?.value || '5000');
        const singleBet = parseFloat((document.getElementById('inputSingleBet') as HTMLInputElement)?.value || '1000');
        const dailyWager = parseFloat((document.getElementById('inputDailyWager') as HTMLInputElement)?.value || '10000');
        const dailyLoss = parseFloat((document.getElementById('inputDailyLoss') as HTMLInputElement)?.value || '5000');

        try {
            const res = await fetch('/api/v1/player/settings/limits', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                body: JSON.stringify({
                    daily_deposit_limit: dailyDeposit,
                    single_bet_limit: singleBet,
                    daily_wager_limit: dailyWager,
                    daily_loss_limit: dailyLoss,
                }),
            });
            const data = await res.json();
            alert(data.message || 'Responsible gaming limits saved.');
        } catch (e) {
            alert('Failed to save spending limits.');
        }
    }

    public async applySelfExclusion(duration: string): Promise<void> {
        if (!confirm(`Are you sure you want to activate a ${duration} self-exclusion break? You will be logged out immediately.`)) {
            return;
        }

        try {
            const res = await fetch('/api/v1/player/settings/self-exclusion', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                body: JSON.stringify({ duration }),
            });
            const data = await res.json();
            alert(data.message || 'Self-exclusion period activated.');
            window.location.href = '/login';
        } catch (e) {
            alert('Failed to apply self-exclusion.');
        }
    }
}

declare global {
    interface Window {
        playerSettings: PlayerSettingsManager;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    window.playerSettings = new PlayerSettingsManager();
});
