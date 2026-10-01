<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Feature tests for Home Page (Prompt 01).
 * Verifies public access, anonymous state, authenticated state, responsive layout contracts, and required landmarks.
 */
final class HomePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_home_page_is_publicly_accessible(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertViewIs('home');
        $this->assertGuest();
    }

    public function test_home_page_renders_with_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee(route('player.dashboard'), false);
    }

    public function test_home_page_contains_required_landmarks(): void
    {
        $content = (string) $this->get('/')->assertOk()->getContent();

        $landmarks = [
            'id="hero"',
            'id="next-draw"',
            'id="live-draw"',
            'id="current-result"',
            'id="check"',
            'id="products"',
            'id="trust"',
            'id="app-links"',
            'id="payments"',
            'id="support"',
        ];

        foreach ($landmarks as $landmark) {
            $this->assertStringContainsString($landmark, $content, "Missing required landmark: {$landmark}");
        }
    }

    public function test_home_page_includes_3d_glass_stylesheet_and_scripts(): void
    {
        $content = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('pages/home.css', $content);
        $this->assertStringContainsString('pages/home.js', $content);
        $this->assertStringContainsString('home/countdown.js', $content);
    }
}
