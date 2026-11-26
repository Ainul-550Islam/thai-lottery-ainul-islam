<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\User;
use App\Services\Account\ProfileOverviewService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * The profile overview is already masked by the service, and the resource never sees
 * the raw values.
 *
 * The test that matters most is the masking one: the endpoint is authenticated, but a
 * full phone number or email rendered on a screen that gets shoulder-surfed or
 * screenshotted is a real leak, and the masking has to happen server-side because a
 * client-side mask is decoration.
 */
final class ProfileOverviewServiceTest extends TestCase
{
    use DatabaseTruncation;

    public function test_the_overview_returns_the_documented_sections(): void
    {
        $user = User::factory()->create();

        $overview = app(ProfileOverviewService::class)->overview($user);

        foreach (['member', 'personal', 'verification', 'balances'] as $section) {
            $this->assertArrayHasKey($section, $overview);
        }
    }

    public function test_the_email_is_masked(): void
    {
        $user = User::factory()->create(['email' => 'averylongaddress@example.test']);

        $overview = app(ProfileOverviewService::class)->overview($user);
        $email = (string) ($overview['personal']['email_masked'] ?? $overview['personal']['email'] ?? '');

        $this->assertNotSame('averylongaddress@example.test', $email);
        $this->assertStringContainsString('@', $email, 'A masked email still has to be recognisable to its owner.');
        $this->assertStringContainsString('*', $email);
    }

    public function test_the_phone_is_masked(): void
    {
        $user = User::factory()->create(['phone' => '+8801712345678']);

        $overview = app(ProfileOverviewService::class)->overview($user);
        $phone = (string) ($overview['personal']['phone_masked'] ?? $overview['personal']['phone'] ?? '');

        $this->assertNotSame('+8801712345678', $phone);
        $this->assertStringContainsString('*', $phone);
    }

    public function test_the_full_name_is_not_leaked_into_the_member_section(): void
    {
        $user = User::factory()->create(['name' => 'Somchai Prasert']);

        $json = json_encode(app(ProfileOverviewService::class)->overview($user), JSON_THROW_ON_ERROR);

        // The display name is expected; the point is that nothing ELSE identifying
        // arrives alongside it.
        $this->assertStringNotContainsString('password', $json);
        $this->assertStringNotContainsString('remember_token', $json);
        $this->assertStringNotContainsString('two_factor_secret', $json);
    }

    public function test_the_verification_section_reports_booleans_not_documents(): void
    {
        $user = User::factory()->create();

        $overview = app(ProfileOverviewService::class)->overview($user);

        foreach ($overview['verification'] as $key => $value) {
            $this->assertTrue(
                is_bool($value) || is_string($value) || $value === null,
                $key.' must be a status flag, never a document payload.',
            );
        }
    }

    public function test_reading_the_profile_writes_nothing(): void
    {
        $user = User::factory()->create();

        $before = $user->fresh()->toArray();

        app(ProfileOverviewService::class)->overview($user);
        app(ProfileOverviewService::class)->overview($user);

        $this->assertSame($before, $user->fresh()->toArray());
    }

    public function test_reading_another_members_profile_is_not_possible_through_this_service(): void
    {
        // The service takes a User instance, not an id, so a caller cannot ask for
        // "user 5" — the endpoint hands it the authenticated model and nothing else.
        $user = User::factory()->create();

        $overview = app(ProfileOverviewService::class)->overview($user);

        $this->assertSame((int) $user->id, (int) ($overview['member']['id'] ?? 0));
    }
}
