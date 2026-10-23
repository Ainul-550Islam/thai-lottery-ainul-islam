import React from 'react';

export interface DepositPortalProps {
    title?: string;
    statusMessage?: string;
}

/**
 * Canonical accessible status surface (DepositPortal).
 * Deposit requests are processed through the authenticated server-rendered payment flow.
 */
export default function DepositPortal({
    title = 'DepositPortal',
    statusMessage = 'Deposit requests are processed through the authenticated server-rendered payment flow.',
}: DepositPortalProps): React.ReactElement {
    return (
        <section
            className="tl-v2-card"
            aria-label={title}
            data-component="DepositPortal"
        >
            <h2 className="text-lg font-bold text-[#FFF6D6]">{title}</h2>
            <p className="mt-2 text-sm text-[#D4CAB3]" role="status">
                {statusMessage}
            </p>
        </section>
    );
}
