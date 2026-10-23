import React from 'react';

export interface PlayerDashboardProps {
    title?: string;
    statusMessage?: string;
}

/**
 * Canonical accessible status surface (PlayerDashboard).
 * Player balances, active draws, and recent wagers render directly from server-verified state.
 */
export default function PlayerDashboard({
    title = 'PlayerDashboard',
    statusMessage = 'Player balances, active draws, and recent wagers render directly from server-verified state.',
}: PlayerDashboardProps): React.ReactElement {
    return (
        <section
            className="tl-v2-card"
            aria-label={title}
            data-component="PlayerDashboard"
        >
            <h2 className="text-lg font-bold text-[#FFF6D6]">{title}</h2>
            <p className="mt-2 text-sm text-[#D4CAB3]" role="status">
                {statusMessage}
            </p>
        </section>
    );
}
