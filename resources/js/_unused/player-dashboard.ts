/**
 * Retired player-dashboard compatibility adapter.
 *
 * The authenticated /dashboard, /deposit, and /bets surfaces own all account,
 * money, ticket-cancellation, refund, and draw-time state. This adapter only
 * routes obsolete controls and cannot fabricate an outcome.
 */
export class PlayerDashboard {
    public init(): void {
        document.getElementById('walletDepositBtn')?.addEventListener('click', () => {
            window.location.assign('/deposit');
        });

        document.querySelectorAll<HTMLElement>('[data-cancel-ticket]').forEach((control) => {
            control.addEventListener('click', (event) => {
                event.preventDefault();
                window.location.assign('/bets');
            });
        });
    }
}

if (typeof document !== 'undefined') {
    const start = (): void => new PlayerDashboard().init();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}
