import fs from 'node:fs';

const legacyPayload = JSON.parse(
    fs.readFileSync(new URL('./resources/css/tailwind-legacy-theme.json', import.meta.url), 'utf8'),
);
const legacyTheme = legacyPayload.theme ?? {};

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{js,ts,jsx,tsx}',
        './app/Filament/**/*.php',
        './app/View/**/*.php',
    ],
    theme: {
        ...legacyTheme,
        extend: {
            colors: {
                tl: {
                    obsidian: '#0B0904',
                    surface: '#141007',
                    elevated: '#1D170B',
                    gold: '#D4AF37',
                    'gold-soft': '#F5E6B8',
                    cream: '#FFF6D6',
                },
            },
            fontFamily: {
                sans: ['Inter', 'Noto Sans Thai', 'Noto Sans Bengali', 'system-ui', 'sans-serif'],
                display: ['Plus Jakarta Sans', 'Inter', 'Noto Sans Thai', 'Noto Sans Bengali', 'system-ui', 'sans-serif'],
                mono: ['JetBrains Mono', 'ui-monospace', 'SFMono-Regular', 'monospace'],
            },
        },
    },
    plugins: [],
};
