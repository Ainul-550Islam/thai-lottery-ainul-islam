import React from 'react';

export interface ThaiLotteryBettingProps {
    title?: string;
    statusMessage?: string;
}

/**
 * Canonical accessible status surface (ThaiLotteryBetting).
 * Bet slip selection and purchase run through the canonical server-authoritative betting flow.
 */
export default function ThaiLotteryBetting({
    title = 'ThaiLotteryBetting',
    statusMessage = 'Bet slip selection and purchase run through the canonical server-authoritative betting flow.',
}: ThaiLotteryBettingProps): React.ReactElement {
    return (
        <section
            className="tl-v2-card"
            aria-label={title}
            data-component="ThaiLotteryBetting"
        >
            <h2 className="text-lg font-bold text-[#FFF6D6]">{title}</h2>
            <p className="mt-2 text-sm text-[#D4CAB3]" role="status">
                {statusMessage}
            </p>
        </section>
    );
}
