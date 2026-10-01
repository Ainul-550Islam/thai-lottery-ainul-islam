<?php

declare(strict_types=1);

namespace Tests\Feature\UI;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Design System Regression & Component Integrity Test Suite.
 */
class DesignSystemRegressionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. UI Button Component Variants.
     */
    public function test_ui_button_component_renders_variants(): void
    {
        // Primary button
        $primary = Blade::render('<x-ui.button variant="primary">Submit</x-ui.button>');
        $this->assertStringContainsString('from-emerald-600', $primary);
        $this->assertStringContainsString('Submit', $primary);

        // Secondary button
        $secondary = Blade::render('<x-ui.button variant="secondary">Cancel</x-ui.button>');
        $this->assertStringContainsString('bg-slate-800', $secondary);
        $this->assertStringContainsString('Cancel', $secondary);

        // Link button with href
        $link = Blade::render('<x-ui.button href="/player/wallet" variant="accent">Wallet</x-ui.button>');
        $this->assertStringContainsString('<a href="/player/wallet"', $link);
        $this->assertStringContainsString('bg-amber-500', $link);

        // Disabled button
        $disabled = Blade::render('<x-ui.button :disabled="true">Disabled</x-ui.button>');
        $this->assertStringContainsString('disabled', $disabled);
    }

    /**
     * 2. Result Card Component Rendering.
     */
    public function test_result_card_component_renders(): void
    {
        $rendered = Blade::render('<x-results.result-card drawNumber="101" drawDate="2026-10-01" firstPrize="123456" :secondPrize="[\'111111\', \'222222\']" sourceState="OFFICIAL_SOURCE_VERIFIED" />');

        $this->assertStringContainsString('Draw #101', $rendered);
        $this->assertStringContainsString('2026-10-01', $rendered);
        $this->assertStringContainsString('123456', $rendered);
        $this->assertStringContainsString('OFFICIAL SOURCE VERIFIED', $rendered);
    }

    /**
     * 3. Main Navigation & Mobile Navigation Component Rendering.
     */
    public function test_navigation_components_render_for_guest_and_user(): void
    {
        // Guest Nav
        $guestNav = Blade::render('<x-navigation.main-nav />');
        $this->assertStringContainsString('Results', $guestNav);
        $this->assertStringContainsString('Check Ticket', $guestNav);

        // Authenticated Nav
        $user = User::factory()->create();
        $this->actingAs($user);
        $authNav = Blade::render('<x-navigation.main-nav />');
        $this->assertStringContainsString('Dashboard', $authNav);
        $this->assertStringContainsString('Place Bet', $authNav);
        $this->assertStringContainsString('My Bets', $authNav);
    }

    /**
     * 4. Design System CSS Files Existence and Motion Token Verification.
     */
    public function test_design_system_css_files_exist_and_include_reduced_motion(): void
    {
        $cssFiles = [
            resource_path('css/app.css'),
            resource_path('css/theme.css'),
            resource_path('css/components/glass.css'),
            resource_path('css/components/metrics.css'),
            resource_path('css/components/lottery-3d.css'),
        ];

        foreach ($cssFiles as $path) {
            $this->assertFileExists($path);
            $content = (string) file_get_contents($path);
            $this->assertNotEmpty($content);
        }

        // Verify reduced motion in theme or app CSS
        $themeContent = (string) file_get_contents(resource_path('css/theme.css'));
        $this->assertStringContainsString('prefers-reduced-motion', $themeContent);
    }
}
