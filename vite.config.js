import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/lottery.css',
                'resources/css/home.css',
        'resources/css/public-pages.css',
        'resources/css/account-services.css',
                'resources/css/prize-discount.css',
                // PROMPT 5: National Lottery result surface.
                'resources/css/national-lottery.css',
                // PROMPT 6: Weekly Lottery result surface.
                'resources/css/weekly-lottery.css',
                'resources/css/bingo-lottery.css',
                'resources/css/pcso-lottery.css',
                'resources/css/contact.css',
        'resources/js/app.js',
        'resources/js/public-pages.js',
        'resources/js/account-verification.js',
        'resources/js/account-grade.js',
                'resources/js/prize-verification.js',
                'resources/js/national-lottery.js',
                'resources/js/weekly-lottery.js',
                'resources/js/bingo-lottery.js',
                'resources/js/pcso-lottery.js',
                'resources/js/contact.js',
                'resources/js/lottery/ticket-selector.js',
                'resources/js/lottery/countdown.js',
                'resources/js/lottery/bet-slip.js',
                'resources/js/lottery/live-results.js',
                'resources/js/wallet/wallet-balance.js',
                'resources/js/home/countdown.js',
                'resources/js/home/live-draw.js',
            ],
            refresh: true,
        }),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
    },
});
