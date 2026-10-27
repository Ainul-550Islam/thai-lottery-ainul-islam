<?php

declare(strict_types=1);

namespace Tests\Feature\Vision;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VisionLegacyRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_vision_url_redirects_to_the_canonical_route(): void
    {
        $this->get('/vision.php')
            ->assertStatus(301)
            ->assertRedirect(route('vision'));
    }

    public function test_legacy_vision_url_does_not_create_a_redirect_loop(): void
    {
        $this->get(route('vision'))->assertOk();
        $this->get('/vision.php/extra/deeper.php')->assertNotFound();
    }
}
