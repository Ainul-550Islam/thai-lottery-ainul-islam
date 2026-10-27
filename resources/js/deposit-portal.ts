/**
 * Retired deposit-portal compatibility adapter.
 *
 * The authenticated canonical /deposit surface owns exact-decimal validation,
 * provider initiation, and all user-visible outcomes. This adapter only routes
 * obsolete controls to that surface and cannot manufacture financial state.
 */
export class DepositPortal {
    public init(): void {
        const button = document.getElementById('initiateDepositBtn');
        button?.addEventListener('click', () => {
            window.location.assign('/deposit');
        });
    }
}

if (typeof document !== 'undefined') {
    const start = (): void => new DepositPortal().init();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}
