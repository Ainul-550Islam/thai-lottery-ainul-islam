<?php

declare(strict_types=1);

namespace Tests\Feature\Player;

use App\Enums\BetMarket;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the player-facing JavaScript modules and the markup they bind to.
 *
 * WHY THIS TEST EXISTS
 * The five modules listed in vite.config.js under resources/js/lottery and
 * resources/js/wallet were registered as build entries while their files were
 * empty placeholders, and three of them were not referenced by any template at
 * all. An empty entry still builds, still produces a manifest record and still
 * ships a <script> tag, so nothing failed - the bet page simply had a keypad
 * that did nothing. This test makes that specific failure mode loud:
 *
 *  1. each module must exist and contain real code (not a stub);
 *  2. each must be a build entry AND present in the built manifest;
 *  3. each must be referenced by the template that needs it;
 *  4. the bet page's market vocabulary must be the vocabulary the API accepts.
 *
 * Point 4 is the one that was silently wrong: the select offered
 * three_digits_top / three_digits_tod / two_digits_top / two_digits_bottom,
 * none of which App\Enums\BetMarket declares, so a slip built from the page
 * could never have been accepted by BulkBetRequest.
 */
final class PlayerFrontendModulesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private const MODULES = [
        'resources/js/lottery/ticket-selector.js',
        'resources/js/lottery/bet-slip.js',
        'resources/js/lottery/countdown.js',
        'resources/js/lottery/live-results.js',
        'resources/js/wallet/wallet-balance.js',
    ];

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        $this->player = User::factory()->create(['status' => UserStatus::Active]);
    }

    public function test_every_registered_module_contains_real_code(): void
    {
        foreach (self::MODULES as $module) {
            $path = base_path($module);

            $this->assertFileExists($path, $module.' is registered as a Vite entry but does not exist.');

            $body = (string) file_get_contents($path);

            $this->assertGreaterThan(
                500,
                strlen(trim($body)),
                $module.' is registered as a Vite entry but is effectively empty.',
            );

            $this->assertStringContainsString(
                "'use strict'",
                $body,
                $module.' must run in strict mode like every other module in this project.',
            );
        }
    }

    public function test_no_module_is_left_as_a_placeholder(): void
    {
        foreach (self::MODULES as $module) {
            $body = (string) file_get_contents(base_path($module));

            foreach (['TODO', 'FIXME', 'coming soon', 'not implemented', 'placeholder implementation'] as $marker) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $marker,
                    $body,
                    $module.' still carries the "'.$marker.'" marker.',
                );
            }
        }
    }

    public function test_modules_avoid_unsafe_dom_and_evaluation_apis(): void
    {
        // Same rule the account-services modules are held to: text goes in as
        // text, never as markup, so a server-supplied value can never become
        // executable content in the browser.
        foreach (self::MODULES as $module) {
            $body = (string) file_get_contents(base_path($module));

            foreach (['innerHTML', 'outerHTML', 'document.write', 'eval(', 'new Function('] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $body,
                    $module.' must not use '.$forbidden.'.',
                );
            }
        }
    }

    public function test_every_module_is_a_build_entry_and_is_present_in_the_manifest(): void
    {
        $viteConfig = (string) file_get_contents(base_path('vite.config.js'));
        $manifestPath = public_path('build/manifest.json');

        $this->assertFileExists($manifestPath, 'The built Vite manifest is missing; run "npm run build".');

        /** @var array<string, array{file?: string}> $manifest */
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        foreach (self::MODULES as $module) {
            $this->assertStringContainsString(
                $module,
                $viteConfig,
                $module.' is not declared as a Vite input.',
            );

            $this->assertArrayHasKey(
                $module,
                $manifest,
                $module.' has no built asset in the manifest.',
            );

            $this->assertFileExists(
                public_path('build/'.$manifest[$module]['file']),
                'The built asset for '.$module.' is listed in the manifest but absent from public/build.',
            );
        }
    }

    public function test_each_module_is_referenced_by_the_template_that_needs_it(): void
    {
        $references = [
            'resources/views/layouts/app.blade.php' => ['resources/js/wallet/wallet-balance.js'],
            'resources/views/player/bet.blade.php' => [
                'resources/js/lottery/ticket-selector.js',
                'resources/js/lottery/bet-slip.js',
            ],
            'resources/views/player/dashboard.blade.php' => ['resources/js/lottery/countdown.js'],
            'resources/views/player/draw-detail.blade.php' => ['resources/js/lottery/live-results.js'],
        ];

        foreach ($references as $view => $modules) {
            $body = (string) file_get_contents(base_path($view));

            foreach ($modules as $module) {
                $this->assertStringContainsString(
                    $module,
                    $body,
                    $view.' never loads '.$module.', so the module can never run.',
                );
            }
        }
    }

    public function test_bet_page_offers_only_market_keys_the_api_accepts(): void
    {
        $content = (string) $this->actingAs($this->player)
            ->get(route('player.bet'))
            ->assertOk()
            ->getContent();

        preg_match_all('/<option value="([^"]+)"/', $content, $matches);

        $this->assertNotEmpty($matches[1], 'The bet page rendered no market options.');

        $declared = BetMarket::allMarketKeys();

        foreach ($matches[1] as $value) {
            $this->assertContains(
                $value,
                $declared,
                'The bet page offers market "'.$value.'", which BetMarket does not declare, '
                    .'so BulkBetRequest would refuse it.',
            );
        }
    }

    public function test_bet_page_publishes_the_configured_digits_and_multipliers(): void
    {
        $content = (string) $this->actingAs($this->player)
            ->get(route('player.bet'))
            ->assertOk()
            ->getContent();

        /** @var array<string, array{digits?: int, payout_multiplier?: int|float, enabled?: bool}> $markets */
        $markets = config('lottery.markets', []);

        foreach ($markets as $key => $market) {
            if (($market['enabled'] ?? false) !== true) {
                continue;
            }

            $this->assertStringContainsString('value="'.$key.'"', $content, 'Market '.$key.' is not offered.');
            $this->assertStringContainsString(
                'data-digits="'.(int) $market['digits'].'"',
                $content,
                'The digit count for '.$key.' is not published to the browser.',
            );
            $this->assertStringContainsString(
                'data-multiplier="'.(float) $market['payout_multiplier'].'"',
                $content,
                'The payout multiplier for '.$key.' is not published to the browser, '
                    .'so the slip preview would use a hardcoded rate.',
            );
        }
    }

    public function test_bet_slip_container_mirrors_the_server_side_item_ceiling(): void
    {
        $content = (string) $this->actingAs($this->player)
            ->get(route('player.bet'))
            ->assertOk()
            ->getContent();

        $request = (string) file_get_contents(base_path('app/Http/Requests/Api/V1/BulkBetRequest.php'));

        $this->assertStringContainsString("'max:50'", $request, 'The bulk request no longer caps a slip at 50 items.');
        $this->assertStringContainsString(
            'data-max-items="50"',
            $content,
            'The slip no longer mirrors the 50-item ceiling enforced by BulkBetRequest.',
        );
    }

    public function test_the_slip_is_not_given_a_purchase_credential_it_cannot_have(): void
    {
        // POST /api/v1/bets/purchase-bulk is behind auth:sanctum and this
        // application does not call statefulApi(), so a session cookie cannot
        // authenticate. The page must therefore NOT advertise a purchase
        // endpoint or embed a token: bet-slip.js stays inert and explains why,
        // instead of firing a request that can only 401.
        $content = (string) $this->actingAs($this->player)
            ->get(route('player.bet'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-purchase-endpoint', $content);
        $this->assertStringNotContainsString('data-api-token', $content);

        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringNotContainsString(
            'statefulApi',
            $bootstrap,
            'Sanctum stateful frontend requests are now enabled; revisit the bet slip, '
                .'which is deliberately inert because they were not.',
        );
    }

    public function test_dashboard_countdown_target_is_a_server_rendered_instant(): void
    {
        $content = (string) $this->actingAs($this->player)
            ->get(route('player.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="draw-countdown"', $content);
        $this->assertMatchesRegularExpression(
            '/data-closes-at="\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}"/',
            $content,
            'The countdown target must be a full ISO 8601 instant with an offset, '
                .'so the browser never has to infer a timezone.',
        );
    }

    public function test_the_header_wallet_pill_exposes_no_client_side_balance_source(): void
    {
        $content = (string) $this->actingAs($this->player)
            ->get(route('player.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="live-wallet-pill"', $content);
        $this->assertStringContainsString('id="player-balance-display"', $content);

        // No endpoint, no token: wallet-balance.js formats and announces the
        // server-rendered figure and never polls.
        $this->assertStringNotContainsString('data-balance-endpoint', $content);
    }
}
