/**
 * Retired withdrawal-portal compatibility adapter.
 *
 * The authenticated canonical /withdraw surface owns exact-decimal validation,
 * destination checks, wallet reservations, approval, and final status. This
 * adapter only routes obsolete controls and cannot mutate financial state.
 */
export class WithdrawalPortal {
    public init(): void {
        const button = document.getElementById('confirmWithdrawalBtn');
        button?.addEventListener('click', () => {
            window.location.assign('/withdraw');
        });
    }
}

if (typeof document !== 'undefined') {
    const start = (): void => new WithdrawalPortal().init();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}
