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

        // The assets the page actually loads, resolved through the build.
        //
        // This previously asserted pages/home.css and pages/home.js, which
        // belonged to the static mockup this page replaced - the one that
        // carried a fabricated government licence badge. Those two files were
        // orphans: never loaded, duplicating components/glass.css, and in the
        // script's case binding the same countdown element that
        // home/countdown.js owns. They have been deleted.
        //
        // Asserting a source filename cannot work against a production build,
        // because Vite emits content-hashed names: resources/css/home.css
        // ships as assets/home-Caj98s6M.css. So each source entry is resolved
        // through public/build/manifest.json and the emitted filename is what
        // the response must contain. That is a stronger check than the old
        // one - it proves the page ships the compiled artefact, not merely
        // that a string appears in the markup.
        $manifestPath = public_path('build/manifest.json');

        $this->assertFileExists(
            $manifestPath,
            'The front-end build is missing: run npm run build before the suite.',
        );

        /** @var array<string, array{file?: string}> $manifest */
        $manifest = (array) json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        foreach ([
            'resources/css/home.css',
            'resources/js/home/countdown.js',
            'resources/js/home/live-draw.js',
        ] as $source) {
            $this->assertArrayHasKey(
                $source,
                $manifest,
                $source.' is not a build entry, so the home page cannot load it.',
            );

            $emitted = (string) ($manifest[$source]['file'] ?? '');

            $this->assertNotSame('', $emitted, $source.' resolved to no built file.');

            $this->assertStringContainsString(
                $emitted,
                $content,
                'The home page does not load '.$source.' (built as '.$emitted.').',
            );
        }
    }
}
