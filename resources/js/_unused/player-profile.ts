/**
 * Player-profile compatibility adapter.
 *
 * Profile and financial mutations are performed only by the authenticated,
 * server-rendered profile and wallet surfaces. This file intentionally carries
 * no balance, transfer, PIN, bank-binding, session, or success simulation.
 */
export class PlayerProfileManager {
    public init(): void {
        document.querySelectorAll<HTMLElement>('[data-open-canonical-profile]')
            .forEach((control) => {
                control.addEventListener('click', () => window.location.assign('/profile'));
            });

        document.querySelectorAll<HTMLElement>('[data-open-canonical-wallet]')
            .forEach((control) => {
                control.addEventListener('click', () => window.location.assign('/wallet'));
            });
    }
}

if (typeof document !== 'undefined') {
    const start = (): void => new PlayerProfileManager().init();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}
