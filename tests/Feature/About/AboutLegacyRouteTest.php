<?php

declare(strict_types=1);

namespace Tests\Feature\About;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AboutLegacyRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_about_url_redirects_to_the_canonical_about_route(): void
    {
        $this->get('/about.php')
            ->assertStatus(301)
            ->assertRedirect(route('about'));
    }

    public function test_unknown_about_php_suffix_does_not_capture_a_real_route(): void
    {
        $this->get('/about.php/extra/deeper.php')->assertNotFound();
        $this->get(route('about'))->assertOk();
    }
}
