/**
 * Thailand Government Lottery (GLO) Interactive Ticket Checker (TypeScript)
 * Reference: draw_results_checker.png
 */

export interface GloWinningMatch {
    won: boolean;
    tierName?: string;
    prizeAmount?: string;
    ticketNumber: string;
    message: string;
}

export class GloResultsChecker {
    private currentInput: string = '';
    private displayScreen: HTMLElement | null = null;
    private maxDigits: number = 6;

    // Published Reference Numbers for Draw #128
    public publishedResults = {
        drawNumber: '128',
        publishDate: 'Oct 16, 2023',
        firstPrize: '724605',
        twoDigitBottom: '14',
        threeDigitTop: '605',
    };

    constructor() {
        this.init();
    }

    public init(): void {
        this.displayScreen = document.getElementById('gloKeypadScreen');
        this.bindKeypadButtons();
        this.bindSubmitButton();
        this.renderScreen();
    }

    private bindKeypadButtons(): void {
        const keyButtons = document.querySelectorAll<HTMLButtonElement>('[data-key-val]');
        keyButtons.forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const val = btn.getAttribute('data-key-val');
                if (!val) return;

                if (val === 'clear') {
                    this.clear();
                } else if (val === 'check') {
                    this.checkTicket();
                } else {
                    this.appendDigit(val);
                }
            });
        });
    }

    private bindSubmitButton(): void {
        const actionBtn = document.getElementById('gloInstantCheckBtn');
        if (actionBtn) {
            actionBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.checkTicket();
            });
        }
    }

    public appendDigit(digit: string): void {
        if (this.currentInput.length < this.maxDigits) {
            this.currentInput += digit;
            this.renderScreen();
        }
    }

    public clear(): void {
        this.currentInput = '';
        this.renderScreen();
    }

    private renderScreen(): void {
        if (!this.displayScreen) return;

        if (this.currentInput.length === 0) {
            this.displayScreen.textContent = '- - - - - -';
            this.displayScreen.style.color = '#d4af37';
        } else {
            // Pad remaining with dashes
            const padded = this.currentInput.padEnd(6, '-').split('').join(' ');
            this.displayScreen.textContent = padded;
            this.displayScreen.style.color = '#fbbf24';
        }
    }

    public checkTicket(): void {
        const number = this.currentInput.trim();
        if (number.length < 2) {
            alert('Please enter at least 2 to 6 digits to check.');
            return;
        }

        const match = this.evaluateMatches(number);
        this.showResultModal(match);
    }

    public evaluateMatches(input: string): GloWinningMatch {
        const { firstPrize, twoDigitBottom, threeDigitTop } = this.publishedResults;

        // 1. Exact 6-Digit 1st Prize
        if (input.length === 6 && input === firstPrize) {
            return {
                won: true,
                tierName: 'First Prize (รางวัลที่ 1)',
                prizeAmount: 'THB 6,000,000',
                ticketNumber: input,
                message: '🎉 CONGRATULATIONS! You won the First Prize jackpot!',
            };
        }

        // 2. 3-Digit Top
        if (input.endsWith(threeDigitTop) || (input.length === 3 && input === threeDigitTop)) {
            return {
                won: true,
                tierName: '3-Digit Top (3 ตัวบน)',
                prizeAmount: 'THB 4,000',
                ticketNumber: input,
                message: '🎉 Congratulations! You matched the 3-Digit Top prize!',
            };
        }

        // 3. 2-Digit Bottom
        if (input.endsWith(twoDigitBottom) || (input.length === 2 && input === twoDigitBottom)) {
            return {
                won: true,
                tierName: '2-Digit Bottom (2 ตัวท้าย)',
                prizeAmount: 'THB 2,000',
                ticketNumber: input,
                message: '🎉 Congratulations! You matched the 2-Digit Bottom prize!',
            };
        }

        return {
            won: false,
            ticketNumber: input,
            message: 'No winning matches found for this draw. Better luck next time!',
        };
    }

    private showResultModal(match: GloWinningMatch): void {
        const modal = document.getElementById('gloResultModal');
        const modalTitle = document.getElementById('gloModalTitle');
        const modalBody = document.getElementById('gloModalBody');

        if (modal && modalTitle && modalBody) {
            modalTitle.textContent = match.won ? '🏆 Official Prize Winning!' : 'Ticket Verification Result';
            modalBody.innerHTML = `
                <div class="text-center space-y-3 py-2">
                    <div class="font-mono text-2xl font-black text-amber-400 tracking-widest">${match.ticketNumber}</div>
                    <p class="text-sm ${match.won ? 'text-emerald-400 font-bold' : 'text-slate-300'}">${match.message}</p>
                    ${match.tierName ? `
                        <div class="bg-slate-950/80 p-3 rounded-xl border border-amber-500/30">
                            <span class="text-xs text-slate-400 block">${match.tierName}</span>
                            <span class="text-xl font-black text-emerald-400">${match.prizeAmount}</span>
                        </div>
                    ` : ''}
                </div>
            `;
            modal.classList.remove('hidden');
        } else {
            alert(`${match.won ? 'WINNER: ' + match.tierName + ' (' + match.prizeAmount + ')' : match.message}`);
        }
    }
}

// Auto-initialize on DOM ready
if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => new GloResultsChecker());
    } else {
        new GloResultsChecker();
    }
}
