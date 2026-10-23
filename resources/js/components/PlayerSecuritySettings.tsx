import React from 'react';

export interface PlayerSecuritySettingsProps {
    title?: string;
    statusMessage?: string;
}

/**
 * Canonical accessible status surface (PlayerSecuritySettings).
 * Password, two-factor, and session security controls use canonical server-validated routes.
 */
export default function PlayerSecuritySettings({
    title = 'PlayerSecuritySettings',
    statusMessage = 'Password, two-factor, and session security controls use canonical server-validated routes.',
}: PlayerSecuritySettingsProps): React.ReactElement {
    return (
        <section
            className="tl-v2-card"
            aria-label={title}
            data-component="PlayerSecuritySettings"
        >
            <h2 className="text-lg font-bold text-[#FFF6D6]">{title}</h2>
            <p className="mt-2 text-sm text-[#D4CAB3]" role="status">
                {statusMessage}
            </p>
        </section>
    );
}
