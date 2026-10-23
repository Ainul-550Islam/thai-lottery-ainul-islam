import React from 'react';

export interface GloResultsCheckerProps {
    title?: string;
    statusMessage?: string;
}

/**
 * Canonical accessible status surface (GloResultsChecker).
 * Published 6-digit lottery checks run through the canonical server-verified checker.
 */
export default function GloResultsChecker({
    title = 'GloResultsChecker',
    statusMessage = 'Published 6-digit lottery checks run through the canonical server-verified checker.',
}: GloResultsCheckerProps): React.ReactElement {
    return (
        <section
            className="tl-v2-card"
            aria-label={title}
            data-component="GloResultsChecker"
        >
            <h2 className="text-lg font-bold text-[#FFF6D6]">{title}</h2>
            <p className="mt-2 text-sm text-[#D4CAB3]" role="status">
                {statusMessage}
            </p>
        </section>
    );
}
