import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css', 'resources/css/gold-dashboard.css',
                'resources/css/thailotto-theme.css',
                'resources/css/lottery.css',
                'resources/css/home.css',
        'resources/css/public-pages.css',
        'resources/css/pages/about.css',
        'resources/css/pages/vision.css',
        'resources/css/pages/terms.css',
        'resources/css/account-services.css',
                // PROMPT 5: National Lottery result surface.
                'resources/css/national-lottery.css',
                // PROMPT 6: Weekly Lottery result surface.
                'resources/css/weekly-lottery.css',
                'resources/css/bingo-lottery.css',
                'resources/css/pcso-lottery.css',
                'resources/css/pages/public-next-pages.css',
                'resources/css/pages/privacy.css',
                'resources/css/pages/fees.css',
                'resources/css/pages/account-verification.css',
                'resources/css/pages/account-grades.css',
                'resources/css/pages/prize-verification.css',
                'resources/css/pages/discounts.css',
                'resources/css/pages/how-to-play.css',
                'resources/css/pages/faq.css',
                'resources/css/pages/contact.css',
                'resources/css/pages/download.css',
                'resources/css/auth-portal.css',
                'resources/css/admin-lottofin.css',
                'resources/css/glo-l6.css',
                // These four CSS entries were referenced by Blade (@vite) but never
                // registered, so `@vite` failed to find them in the manifest and the
                // page 500'd in production / 404'd in dev.
                'resources/css/tailwind-utility.css',
                'resources/css/wallet-conversion.css',
                'resources/css/pages/blog.css',
                'resources/css/pages/rewards.css',
        'resources/js/app.js',
        'resources/js/gold-dashboard.js',
        // Page modules the app entry reaches (statically, or as a code-split
        // chunk). They are inputs as well, so `npm run build` always produces a
        // file for them even if a future refactor drops the import.
        'resources/js/echo.js',
        'resources/js/accessibility.js',
        'resources/js/notifications.js',
        'resources/js/svgbet-slip.js',
        'resources/js/svgdeposit.js',
        'resources/js/svgwithdraw.js',
        'resources/js/truemoney-redeem.js',
        'resources/js/slip-upload.js',
        'resources/js/legal-downloads.js',
        'resources/js/public-pages.js',
        'resources/js/pages/about.js',
        'resources/js/pages/vision.js',
        'resources/js/pages/terms.js',
        'resources/js/auth-portal.js',
        'resources/js/admin-lottofin.js',
        'resources/js/deposit-portal.js',
        'resources/js/player-settings.js',
        // Same class of defect on the script side: each of these was already
        // referenced by a Blade view but missing from the build manifest.
        'resources/js/contact-portal.js',
        'resources/js/lotto-discount-portal.js',
        'resources/js/wallet-conversion.js',
        'resources/js/pages/blog.js',
        'resources/js/pages/rewards.js',
        'resources/js/chat/operator.js',
        'resources/js/how-to-play-portal.js',
        // Public prize lane and the shared toast used by every portal that used
        // to call alert(). Both are @vite'd by results/index.blade.php and by
        // the layouts respectively.
        'resources/js/prize-checker.js',
        'resources/js/ui/toast.js',
        'resources/js/account-verification.js',
        'resources/js/account-grade.js',
                'resources/js/national-lottery.js',
                'resources/js/weekly-lottery.js',
                'resources/js/bingo-lottery.js',
                'resources/js/pcso-lottery.js',
                'resources/js/pages/public-next-pages.js',
                'resources/js/pages/privacy.js',
                'resources/js/pages/fees.js',
                'resources/js/pages/account-verification.js',
                'resources/js/pages/account-grades.js',
                'resources/js/pages/prize-verification.js',
                'resources/js/pages/discounts.js',
                'resources/js/pages/how-to-play.js',
                'resources/js/pages/faq.js',
                'resources/js/pages/contact.js',
                'resources/js/pages/download.js',
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
