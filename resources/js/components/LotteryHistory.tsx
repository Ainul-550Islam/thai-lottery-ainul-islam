import React from 'react';

export interface LotteryHistoryProps {
    title?: string;
    statusMessage?: string;
}

/**
 * Canonical accessible status surface (LotteryHistory).
 * Ticket and bet history records are rendered server-side from authoritative player ledger records.
 */
export default function LotteryHistory({
    title = 'LotteryHistory',
    statusMessage = 'Ticket and bet history records are rendered server-side from authoritative player ledger records.',
}: LotteryHistoryProps): React.ReactElement {
    return (
        <section
            className="tl-v2-card"
            aria-label={title}
            data-component="LotteryHistory"
        >
            <h2 className="text-lg font-bold text-[#FFF6D6]">{title}</h2>
            <p className="mt-2 text-sm text-[#D4CAB3]" role="status">
                {statusMessage}
            </p>
        </section>
    );
}
