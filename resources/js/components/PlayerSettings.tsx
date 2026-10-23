import React from 'react';

export interface PlayerSettingsProps {
    title?: string;
    statusMessage?: string;
}

/**
 * Canonical accessible status surface (PlayerSettings).
 * Notification and responsible-gaming preferences use canonical authenticated server endpoints.
 */
export default function PlayerSettings({
    title = 'PlayerSettings',
    statusMessage = 'Notification and responsible-gaming preferences use canonical authenticated server endpoints.',
}: PlayerSettingsProps): React.ReactElement {
    return (
        <section
            className="tl-v2-card"
            aria-label={title}
            data-component="PlayerSettings"
        >
            <h2 className="text-lg font-bold text-[#FFF6D6]">{title}</h2>
            <p className="mt-2 text-sm text-[#D4CAB3]" role="status">
                {statusMessage}
            </p>
        </section>
    );
}
