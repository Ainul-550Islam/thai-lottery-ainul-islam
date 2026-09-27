<?php

declare(strict_types=1);

namespace Tests\Feature\PublicPages;

use App\Models\User;
use App\Services\Account\PublicAccountInfoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Signed-out explainers for the account programme.
 *
 * A page-by-page comparison against the reference surface found that its
 * account-grade and account-verify pages are PUBLIC information, while ours
 * were only reachable behind a login. Someone deciding whether to register
 * could not see what the grades were.
 *
 * The fix is two new public paths, not relaxed auth on the existing ones:
 * one URL answering differently depending on who asks is how a personal
 * figure eventually renders for a guest. These tests hold that line.
 */
final class PublicAccountInfoTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_grade_ladder_is_public(): void
    {
        $response = $this->get(route('account-grades'));

        $response->assertOk();
        $response->assertSee(trans('account_info.grades_title'), false);
        $response->assertSee('<table', false);
    }

    public function test_the_verification_guide_is_public(): void
    {
        $response = $this->get(route('account-verification-guide'));

        $response->assertOk();
        $response->assertSee(trans('account_info.verification_title'), false);
        $response->assertSee(trans('account_info.step_review_title'), false);
    }

    public function test_the_ladder_shows_every_enabled_tier_with_its_configured_numbers(): void
    {
        $response = $this->get(route('account-grades'));

        $response->assertOk();

        foreach ((array) config('account_grades.tiers') as $tier) {
            if (($tier['enabled'] ?? true) !== true) {
                continue;
            }

            // The advertised figure IS the configured figure; the page and
            // the live calculation read the same file.
            $response->assertSee((string) $tier['name'], false);
            $response->assertSee((string) $tier['min_spend'], false);
        }
    }

    public function test_a_rate_is_formatted_without_float_arithmetic(): void
    {
        $ladder = app(PublicAccountInfoService::class)->gradeLadder();

        $rates = array_column($ladder['tiers'], 'discount_display');

        // 0.0150 -> '1.50%'. Multiplying by 100 as a float yields values like
        // 1.4999999999999999, which is what this formatting exists to avoid.
        $this->assertContains('1.50%', $rates);
        $this->assertContains('0.00%', $rates);

        foreach ($rates as $rate) {
            $this->assertMatchesRegularExpression('/^-?[0-9]+\.[0-9]{2}%$/', $rate);
        }
    }

    public function test_the_page_states_that_glo_prices_are_never_discounted(): void
    {
        // The discount is advertised here, so the limit belongs here too: a
        // reader must not infer a reduction on a government-priced ticket.
        $this->get(route('account-grades'))
            ->assertOk()
            ->assertSee(trans('account_info.discount_scope_note'), false);
    }

    public function test_the_public_pages_expose_no_personal_data(): void
    {
        $user = User::factory()->create(['email' => 'private-person@example.test']);

        foreach ([route('account-grades'), route('account-verification-guide')] as $url) {
            $response = $this->get($url);

            $response->assertOk();
            $response->assertDontSee('private-person@example.test', false);
            $response->assertDontSee('qualifying_spend', false);
            $response->assertDontSee('current_grade', false);
        }

        // Structural, not textual: a bare id like "1" occurs innocently all
        // over a rendered page, so asserting its absence proves nothing. What
        // does prove something is that the service CANNOT be handed a user -
        // no method takes one, so no request can return one person's figures.
        $reflection = new \ReflectionClass(PublicAccountInfoService::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                $this->assertNotSame(
                    User::class,
                    $type instanceof \ReflectionNamedType ? $type->getName() : null,
                    $method->getName().'() accepts a User, so it could return personal data.',
                );
            }
        }

        unset($user);
    }

    public function test_the_authenticated_account_pages_are_still_behind_a_login(): void
    {
        // The whole point of separate paths: adding public explainers must not
        // have opened the pages that show a person their own figures.
        $this->get('/account/grade')->assertRedirect();
        $this->get('/account/verification')->assertRedirect();
    }

    public function test_english_and_thai_keys_are_identical(): void
    {
        $en = array_keys(require lang_path('en/account_info.php'));
        $th = array_keys(require lang_path('th/account_info.php'));

        sort($en);
        sort($th);

        $this->assertSame($en, $th);
    }

    public function test_the_shared_footer_links_to_both_explainers(): void
    {
        // Reachable from every public informational page, not only by typing
        // the URL.
        $response = $this->get(route('about'));

        $response->assertOk();
        $response->assertSee(route('account-grades'), false);
        $response->assertSee(route('account-verification-guide'), false);
    }
}
