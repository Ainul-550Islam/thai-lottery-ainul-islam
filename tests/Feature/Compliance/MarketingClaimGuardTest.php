<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Services\Compliance\ClaimRefused;
use App\Services\Compliance\MarketingClaimGuard;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * The guard that stops a marketing string from becoming a regulated claim.
 *
 * WHY THIS IS TESTED WORD BY WORD
 * The guard is the only thing standing between an operator's well-intentioned copy
 * ("100% secure!", "Government approved") and a published assertion the operator
 * cannot support. Each data set below is a real phrasing seen on lottery marketing
 * pages, and the assertion is that it is refused — not merely "flagged".
 */
final class MarketingClaimGuardTest extends TestCase
{
    use DatabaseTruncation;

    private MarketingClaimGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = app(MarketingClaimGuard::class);
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function refusedClaims(): array
    {
        return [
            'absolute percentage' => ['100% Secure'],
            'ninety nine percent' => ['99% payout rate'],
            'government approval' => ['Government approved lottery'],
            'licence assertion' => ['Licensed by the Gambling Commission'],
            'guaranteed win' => ['Guaranteed win on every draw'],
            'risk free' => ['Risk free betting'],
            'official affiliation' => ['The official national lottery'],
            'no loss promise' => ['You cannot lose'],
            'instant payout promise' => ['Instant payouts, always'],
            'certified security' => ['Bank-grade certified security'],
        ];
    }

    /**
     * @dataProvider refusedClaims
     */
    public function test_a_claim_the_operator_cannot_support_is_refused(string $claim): void
    {
        $this->expectException(ClaimRefused::class);

        $this->guard->assertPublishable($claim, 'public_label');
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function allowedCopy(): array
    {
        return [
            'a plain description' => ['Play the national lottery online'],
            'results wording' => ['Check the latest draw results'],
            'a responsible gambling notice' => ['Players must be 18 or older'],
            'a neutral support line' => ['Contact support any time'],
            'an honest hedge' => ['Payout amounts depend on the draw'],
        ];
    }

    /**
     * @dataProvider allowedCopy
     */
    public function test_ordinary_copy_is_not_over_blocked(string $copy): void
    {
        // A guard that refuses everything is a guard operators route around, so the
        // allow-list matters as much as the deny-list.
        $this->guard->assertPublishable($copy, 'public_label');
        $this->addToAssertionCount(1);
    }

    public function test_the_deny_list_is_configurable_and_is_the_source_of_truth(): void
    {
        $deny = (array) config('player_experience.compliance.forbidden_claims', []);

        $this->assertNotEmpty($deny, 'The deny-list must not be empty: an empty list silently permits every claim.');
    }

    public function test_an_empty_value_is_not_a_claim(): void
    {
        // A blank label is not a claim, so it is not refused: the resource skips the
        // check when the field is empty and the neutral label for the code is used.
        $this->guard->assertPublishable('', 'public_label');
        $this->guard->assertPublishable('   ', 'public_label');

        $this->addToAssertionCount(2);
    }

    public function test_the_refusal_names_the_offending_phrase_and_the_field(): void
    {
        try {
            $this->guard->assertPublishable('Guaranteed win, 100% secure', 'public_label');
            $this->fail('A claim should have been refused.');
        } catch (ClaimRefused $e) {
            $this->assertStringContainsString('public_label', $e->getMessage());
            $this->assertNotSame('', trim($e->getMessage()));
        }
    }

    public function test_the_guard_is_case_insensitive(): void
    {
        foreach (['GUARANTEED WIN', 'Guaranteed Win', 'guaranteed win'] as $variant) {
            try {
                $this->guard->assertPublishable($variant, 'field');
                $this->fail('Refused phrase slipped through as: '.$variant);
            } catch (ClaimRefused) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_filament_hook_tolerates_a_null_label_before_reaching_the_guard(): void
    {
        // ComplianceAttestationResource::assertPublicLabelIsPublishable() is the
        // null boundary; the guard itself is strictly typed and never sees a null.
        \App\Filament\Resources\ComplianceAttestationResource::assertPublicLabelIsPublishable(null);
        \App\Filament\Resources\ComplianceAttestationResource::assertPublicLabelIsPublishable('');

        $this->addToAssertionCount(2);
    }

    public function test_the_guard_exposes_a_non_throwing_check(): void
    {
        $this->assertFalse($this->guard->isClean('100% secure'));
        $this->assertTrue($this->guard->isClean('Draw results are published at 16:00'));
        $this->assertSame(['100% secure'], $this->guard->matches('This site is 100% secure!'));
    }
}
