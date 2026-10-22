<?php

declare(strict_types=1);

namespace Tests\Feature\Parity;

use App\Http\Requests\Web\RegisterRequest;
use App\Support\SupportedLocale;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Machine-checked version of the competitor parity table
 * (GAP-REPORT-thailotto-vs-ainul-repo.md). Each row asserts the repo still
 * exposes the route or view that the table claims, so the report cannot drift
 * away from the code silently.
 */
final class CompetitorParityMatrixTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function publicRouteProvider(): array
    {
        return [
            'C1 home' => ['/'],
            'C2 national results' => ['/national-lottery'],
            'C3 weekly (6/3/2 ball) results' => ['/weekly-lottery'],
            'C6 bonus / campaigns (home section)' => ['/'],
            'C10 support' => ['/support'],
            'C11 about' => ['/about'],
            'C11 how to play' => ['/how-to-play'],
            'C11 vision' => ['/vision'],
            'C12 contact' => ['/contact'],
            'C12 contact-us alias' => ['/contact-us'],
            'C13 terms' => ['/terms'],
            'C13 fees' => ['/fees'],
            'C13 prize verification' => ['/prize-verification'],
            'C13 discounts' => ['/discounts'],
            'C14 login' => ['/login'],
            'C14 forgot password' => ['/forgot-password'],
            'C16 register' => ['/register'],
            'C18 download app' => ['/download-app'],
            'C18 download' => ['/download'],
            'C20 account verification' => ['/account/verification'],
            'C21 deposit' => ['/deposit'],
            'C21 withdraw' => ['/withdraw'],
            'C21 bet' => ['/bet'],
            'C21 wallet' => ['/wallet'],
            'C21 bets' => ['/bets'],
            'C21 dashboard' => ['/dashboard'],
            'C22 results hub' => ['/results'],
            'C19 locale switch' => ['/locale/{locale}'],
        ];
    }

    #[DataProvider('publicRouteProvider')]
    public function test_competitor_page_has_a_matching_get_route(string $uri): void
    {
        $uris = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true)) {
                $uris[] = '/'.ltrim($route->uri(), '/');
            }
        }

        $this->assertContains($uri, $uris, "No GET route for {$uri}");
    }

    /** @return array<string, array{string}> */
    public static function componentViewProvider(): array
    {
        return [
            'C1 hero' => ['components/home/hero'],
            'C1 next draw countdown' => ['components/home/next-draw'],
            'C4 live draw' => ['components/home/live-draw'],
            'C5 platform stats' => ['components/home/stats'],
            'C6 bonus section' => ['components/home/bonus-section'],
        ];
    }

    #[DataProvider('componentViewProvider')]
    public function test_competitor_home_component_exists_as_a_view(string $view): void
    {
        $this->assertFileExists(resource_path('views/'.$view.'.blade.php'));
    }

    public function test_bangla_is_part_of_the_locale_allow_list_for_c19(): void
    {
        $this->assertContains(SupportedLocale::BN, SupportedLocale::ALL);
    }

    public function test_the_register_form_collects_the_referral_and_contact_fields_c16(): void
    {
        $rules = (new RegisterRequest)->rules();

        foreach (['referral_id', 'mobile', 'date_of_birth', 'nationality', 'gender', 'city', 'country', 'terms'] as $field) {
            $this->assertArrayHasKey($field, $rules, "register form lost the {$field} field");
        }
    }

    public function test_c15_social_login_is_still_absent_by_design(): void
    {
        // Social login needs operator-issued OAuth credentials. Until those exist
        // this must stay absent rather than being faked with dead buttons.
        $this->assertFalse(class_exists('Laravel\\Socialite\\Facades\\Socialite'));
    }
}
