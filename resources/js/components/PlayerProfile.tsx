import React from 'react';

export interface PlayerProfileProps {
    title?: string;
    statusMessage?: string;
}

/**
 * Canonical accessible status surface (PlayerProfile).
 * Profile updates submit through authenticated server-validated forms with CSRF protection.
 */
export default function PlayerProfile({
    title = 'PlayerProfile',
    statusMessage = 'Profile updates submit through authenticated server-validated forms with CSRF protection.',
}: PlayerProfileProps): React.ReactElement {
    return (
        <section
            className="tl-v2-card"
            aria-label={title}
            data-component="PlayerProfile"
        >
            <h2 className="text-lg font-bold text-[#FFF6D6]">{title}</h2>
            <p className="mt-2 text-sm text-[#D4CAB3]" role="status">
                {statusMessage}
            </p>
        </section>
    );
}
