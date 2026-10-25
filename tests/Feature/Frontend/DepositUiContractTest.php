<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The Blade half of the Batch 08 frontend wiring contract.
 *
 * WHY THIS TEST EXISTS
 * The browser modules are code-split: they only run when their root element is
 * on the page, and they read every endpoint from a data-* attribute. A view
 * that forgets one root, renames one data attribute or hard-codes an API path
 * produces a page that looks correct and does nothing. This gate reads the
 * shipped views and proves that each root, each attribute and each named route
 * is present — and that no /api/v1/ URL was typed by hand instead of resolved
 * through route().
 */
final class DepositUiContractTest extends TestCase
{
    /** @return list<string> */
    private function views(): array
    {
        return [
            'resources/views/deposit/index.blade.php',
            'resources/views/player/deposit.blade.php',
        ];
    }

    /** @return array<string, string> view path => source */
    private function sources(): array
    {
        $sources = [];
        foreach ($this->views() as $view) {
            $path = base_path($view);
            $this->assertFileExists($path, $view.' must ship with the batch.');
            $sources[$view] = (string) file_get_contents($path);
        }

        return $sources;
    }

    public function test_both_deposit_views_expose_every_root_the_browser_modules_require(): void
    {
        $roots = [
            'id="deposit-lanes"',
            'id="truemoney-redeem"',
            'id="slip-upload"',
            'id="deposit-l10n"',
            'id="truemoney-l10n"',
            'id="slip-l10n"',
            'data-lanes-global-notice',
            'data-lane-tab',
            'data-lane-panel',
            'data-lane-notice',
            'data-lane-form',
            'data-promptpay-amount',
            'data-promptpay-qr',
            'data-promptpay-result',
            'data-promptpay-countdown',
            'data-promptpay-copy',
            'data-truemoney-url',
            'data-truemoney-submit',
            'data-truemoney-result',
            'data-slip-amount',
            'data-slip-image',
            'data-slip-qr-payload',
            'data-slip-bank-details',
            'data-slip-result',
            '<noscript>',
        ];

        foreach ($this->sources() as $view => $source) {
            foreach ($roots as $root) {
                $this->assertStringContainsString($root, $source, $view.' is missing '.$root);
            }
        }
    }

    public function test_every_endpoint_in_the_deposit_views_comes_from_a_named_route(): void
    {
        $namedRoutes = [
            'route(\'api.v1.deposit.methods\')',
            'route(\'api.v1.wallet.deposit\')',
            'route(\'api.v1.deposit.status\'',
            'route(\'api.v1.deposit.truemoney.redeem\')',
            'route(\'api.v1.deposit.bank-transfer.intent\')',
            'route(\'api.v1.deposit.slip.verify\')',
            'route(\'api.v1.deposit.slip.show\'',
        ];

        foreach ($this->sources() as $view => $source) {
            foreach ($namedRoutes as $call) {
                $this->assertStringContainsString($call, $source, $view.' must resolve '.$call.' through route()');
            }

            $this->assertDoesNotMatchRegularExpression(
                '/[\'"(]\/api\/v1\//',
                $source,
                $view.' contains a hard-coded /api/v1/ URL; use route() so the named route stays the one source of truth.',
            );
        }
    }

    public function test_the_named_routes_the_markup_uses_really_exist(): void
    {
        $this->assertTrue(Route::has('api.v1.deposit.methods'));
        $this->assertTrue(Route::has('api.v1.wallet.deposit'));
        $this->assertTrue(Route::has('api.v1.deposit.status'));
        $this->assertTrue(Route::has('api.v1.deposit.truemoney.redeem'));
        $this->assertTrue(Route::has('api.v1.deposit.slip.verify'));
        $this->assertTrue(Route::has('api.v1.deposit.slip.show'));
        $this->assertTrue(Route::has('api.v1.deposit.bank-transfer.intent'));

        $this->assertStringContainsString('/api/v1/deposit/methods', route('api.v1.deposit.methods'));
        $this->assertStringContainsString('/api/v1/wallet/deposit', route('api.v1.wallet.deposit'));
        $this->assertStringContainsString('__DEPOSIT_ID__', route('api.v1.deposit.status', ['depositId' => '__DEPOSIT_ID__']));
        $this->assertStringContainsString('__VERIFICATION_ID__', route('api.v1.deposit.slip.show', ['verification' => '__VERIFICATION_ID__']));
    }

    public function test_the_illustration_settings_and_pdf_views_expose_their_roots_and_routes(): void
    {
        $contracts = [
            'resources/views/how-to-play/index.blade.php' => [
                'id="payout-illustration"',
                'data-simulate-url',
                'data-illustration-bet-type',
                'data-illustration-stake',
                'data-illustration-disclaimer',
                'route(\'api.v1.public.how-to-play.simulate\')',
                'id="how-to-play-l10n"',
            ],
            'resources/views/player/security.blade.php' => [
                'id="notification-preferences"',
                'data-update-url',
                'data-read-url',
                'data-events',
                'data-channels',
                'data-mandatory',
                'route(\'api.v1.player.settings.notifications\')',
                'id="player-settings-l10n"',
            ],
            'resources/views/terms/index.blade.php' => [
                'id="legal-downloads"',
                'data-legal-download',
                'route(\'api.v1.public.terms.download\')',
                'id="legal-downloads-l10n"',
            ],
            'resources/views/privacy/index.blade.php' => [
                'id="legal-downloads"',
                'data-legal-download',
                'route(\'api.v1.public.privacy.download\')',
            ],
            'resources/views/fees/index.blade.php' => [
                'id="legal-downloads"',
                'data-legal-download',
                'route(\'api.v1.public.fees.download\')',
            ],
        ];

        foreach ($contracts as $view => $needles) {
            $path = base_path($view);
            $this->assertFileExists($path);
            $source = (string) file_get_contents($path);
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $source, $view.' is missing '.$needle);
            }
            $this->assertDoesNotMatchRegularExpression('/[\'"(]\/api\/v1\//', $source, $view.' hard-codes an API path.');
        }

        $this->assertTrue(File::exists(base_path('resources/js/frontend-api.js')));
        $this->assertTrue(File::exists(base_path('resources/js/truemoney-redeem.js')));
        $this->assertTrue(File::exists(base_path('resources/js/slip-upload.js')));
        $this->assertTrue(File::exists(base_path('resources/js/legal-downloads.js')));
    }

    public function test_the_new_language_catalogues_have_exact_key_parity(): void
    {
        $english = (array) require base_path('lang/en/deposit.php');
        $thai = (array) require base_path('lang/th/deposit.php');
        $bangla = (array) require base_path('lang/bn/deposit.php');

        $this->assertNotEmpty($english);
        $this->assertSame(array_keys($english), array_keys($thai), 'EN/TH deposit keys differ.');
        $this->assertSame(array_keys($english), array_keys($bangla), 'EN/BN deposit keys differ.');

        foreach (['lanes_title', 'not_configured', 'qr_alt', 'toast_wallet_title'] as $key) {
            $this->assertNotSame('', trim((string) $english[$key]));
            $this->assertNotSame($english[$key], $thai[$key], 'Thai must not silently inherit '.$key);
            $this->assertNotSame($english[$key], $bangla[$key], 'Bangla must not silently inherit '.$key);
        }
    }
}
