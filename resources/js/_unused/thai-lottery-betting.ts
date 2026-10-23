/**
 * Retired generic betting-terminal compatibility adapter.
 *
 * The authenticated /bet surface owns exact-decimal stakes, draw checks,
 * limits, wallet locking, idempotency, ticket persistence, and ledger posting.
 * This adapter cannot manufacture a wager, balance, reference, or success.
 */
export class ThaiLotteryBettingApp {
    public init(): void {
        document.querySelectorAll<HTMLElement>('[data-place-wagers], [data-confirm-bet], #placeWagersBtn, #confirmBetBtn')
            .forEach((control) => {
                control.addEventListener('click', () => window.location.assign('/bet'));
            });
    }
}

if (typeof document !== 'undefined') {
    const start = (): void => new ThaiLotteryBettingApp().init();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}
