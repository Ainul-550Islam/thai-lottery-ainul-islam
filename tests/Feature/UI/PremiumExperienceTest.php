<?php

declare(strict_types=1);

namespace Tests\Feature\UI;

use App\Enums\Currency;
use App\Models\Draw;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Premium 3D Glass UI Experience & Contract Integration Test Suite.
 */
class PremiumExperienceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. Public Pages Multi-Locale & Canonical Metadata Rendering.
     */
    public function test_public_pages_render_without_raw_keys_in_en_and_th(): void
    {
        $routes = [
            'home',
            'results.index',
            'ticket-check',
            'about',
            'vision',
            'terms',
            'privacy',
            'fees',
            'contact',
        ];

        foreach ($routes as $route) {
            // EN
            $resEn = $this->get(route($route, ['locale' => 'en']));
            $this->assertContains($resEn->status(), [200, 301, 302]);
            $contentEn = $resEn->getContent();
            $this->assertStringNotContainsString('trans(', $contentEn);
            $this->assertStringNotContainsString('__.`', $contentEn);

            // TH
            $resTh = $this->get(route($route, ['locale' => 'th']));
            $this->assertContains($resTh->status(), [200, 301, 302]);
            $contentTh = $resTh->getContent();
            $this->assertStringNotContainsString('trans(', $contentTh);
        }
    }

    /**
     * 2. Protected Member Pages Authentication and Redirection.
     */
    public function test_player_portal_requires_authenticated_session(): void
    {
        $memberPaths = [
            '/player/dashboard',
            '/player/bet',
            '/player/draws',
            '/player/bets',
            '/player/wallet',
            '/player/deposit',
            '/player/withdraw',
            '/player/profile',
        ];

        foreach ($memberPaths as $path) {
            $response = $this->get($path);
            $response->assertRedirect(route('login'));
        }
    }

    /**
     * 3. Authenticated Member Dashboard & Wallet Rendering.
     */
    public function test_authenticated_dashboard_and_wallet_render_exact_balances(): void
    {
        $user = User::factory()->create(['name' => 'Somchai Jaidee']);
        $walletService = app(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($user->id, Currency::THB->value);
        $walletService->credit($wallet->id, '1500.50', 'Test balance');

        $response = $this->actingAs($user)->get(route('player.dashboard'));
        $response->assertOk();
        $response->assertSee('Somchai Jaidee');
        $response->assertSee('1,500.50');

        $walletRes = $this->actingAs($user)->get(route('player.wallet'));
        $walletRes->assertOk();
        $walletRes->assertSee('1,500.50');
    }

    /**
     * 4. Results Search Page Escaping and Form Rendering.
     */
    public function test_results_search_escapes_xss_and_renders_cleanly(): void
    {
        $xssQuery = '<script>alert(1)</script>';
        $response = $this->get(route('results.search', ['q' => $xssQuery]));

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee(e($xssQuery), false);
    }
}
