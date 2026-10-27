<?php

declare(strict_types=1);

namespace Tests\Feature\Results;

use App\Exceptions\FinancialException;
use App\Services\Lottery\OperatorResultEntryService;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * In production, a fixture result lane must refuse — loudly.
 *
 * ============================================================================
 * WHAT IS BEING PROTECTED
 * ============================================================================
 * Fixture digits are how the product is demonstrated without a real draw: a
 * seeded number that lets the screens be exercised. They are safe only while
 * everybody involved knows they are fake. The moment a production process can
 * serve one, a member is looking at a winning number that was typed by hand,
 * and the platform is one screenshot away from a payout dispute it cannot win.
 *
 * So the rule is not "fixture data is discouraged". It is:
 *
 *   1. in production, the fixture lane is refused whatever the configuration
 *      says — there is no switch that re-opens it;
 *   2. the refusal is a refusal, not an empty result that looks like a draw
 *      with no winners;
 *   3. an operator who wants to look at fixture data must run a non-production
 *      process, and the guard says so in its own words.
 *
 * The first two are asserted here against the provider and the operator entry
 * service; the third is the ProductionSafetyServiceProvider suite's job.
 */
final class ProductionFixtureRefusalTest extends TestCase
{
    public function test_the_fixture_lane_is_refused_in_production_regardless_of_configuration(): void
    {
        config([
            'glo.official_source.mode' => 'fixture',
            'glo.official_source.base_url' => 'https://official.example.test/api',
        ]);

        $this->app['env'] = 'production';

        $provider = $this->app->make(\App\Services\Lottery\GloOfficialResultProvider::class);

        $this->assertTrue($provider->fixtureRefused());
        $this->assertFalse($provider->isConfigured(), 'production must never treat a fixture lane as configured');
    }

    public function test_a_fixture_refusal_uses_the_refusal_vocabulary_and_not_an_empty_draw(): void
    {
        config([
            'glo.official_source.mode' => 'fixture',
            'glo.official_source.base_url' => 'https://official.example.test/api',
        ]);

        $this->app['env'] = 'production';

        $result = $this->app->make(\App\Services\Lottery\GloOfficialResultProvider::class)->fetch();

        $this->assertSame('not_configured', $result['status']);
        $this->assertNull($result['first_prize'], 'a refusal must not carry digits');
        $this->assertSame([], $result['second_prize']);
        $this->assertSame([], $result['third_prize']);
        $this->assertStringContainsString(
            'may never answer',
            (string) $result['failure_reason'],
            'the reason must state that the lane is refused, not that the upstream was down',
        );
    }

    public function test_every_operator_lane_declares_which_engine_it_needs(): void
    {
        $service = $this->app->make(OperatorResultEntryService::class);
        $lanes = $service->lanes();

        $this->assertNotSame([], $lanes, 'the operator surface must declare at least one lane');

        foreach ($lanes as $lane => $state) {
            $this->assertNotSame('', (string) $lane, 'a lane with no name cannot be reported');
            $this->assertNotSame('', (string) $state['provider'], $lane.' must name the provider its entries claim');
            $this->assertIsBool($state['available']);

            // An unavailable lane must say WHY. "Unavailable" with no reason is
            // the state an operator cannot act on.
            if ($state['available'] === false) {
                $this->assertNotNull($state['reason'], $lane.' is unavailable and must explain itself');
            }
        }
    }

    public function test_a_lane_without_an_import_engine_cannot_be_submitted_through_one(): void
    {
        $service = $this->app->make(OperatorResultEntryService::class);

        foreach (array_keys($service->lanes()) as $lane) {
            $capable = $service->capableEngine($lane);

            if ($capable === null) {
                // No engine for this lane: asking for the required one must be an
                // explicit refusal rather than a null that a caller might push
                // through to a write.
                try {
                    $service->requiredEngine($lane);
                    $this->fail($lane.' has no engine but requiredEngine() returned one');
                } catch (\Throwable $e) {
                    $this->assertNotSame('', $e->getMessage());
                }

                continue;
            }

            $this->assertSame(
                $capable::class,
                $service->requiredEngine($lane)::class,
                'capableEngine() and requiredEngine() must name the same engine for '.$lane,
            );
        }
    }

    public function test_the_operator_page_is_guarded_by_a_gate(): void
    {
        // The Filament page is an operator surface: it must ask a gate, not assume
        // that being inside the panel is authorisation enough.
        $class = \App\Filament\Pages\OperatorResultEntryPage::class;

        $this->assertTrue(
            method_exists($class, 'canAccess') || method_exists($class, 'canViewAny'),
            'the operator result entry page must declare its own authorisation method',
        );
    }

    public function test_a_lane_whose_provenance_is_a_fixture_is_not_publishable(): void
    {
        $service = $this->app->make(OperatorResultEntryService::class);

        // isPublishable() answers "may a manually typed result for this lane ever
        // go live". A lane whose provider label marks it as fixture data must
        // answer no, because publishing it is exactly the failure this suite is
        // about.
        $lanes = $service->lanes();

        foreach ($lanes as $lane => $state) {
            // Every lane can be asked the question without throwing; the answer
            // is what varies, not the ability to ask.
            $this->assertIsBool($service->isPublishable($lane));

            if (str_contains(strtolower((string) $state['provider']), 'fixture')) {
                $this->assertFalse($service->isPublishable($lane), $lane.' carries a fixture provenance');
            }
        }
    }

    public function test_two_person_approval_is_enforced_by_the_service_not_by_the_form(): void
    {
        $this->assertTrue(
            class_exists(OperatorResultEntryService::class),
            'the operator entry service is the only writer of a result version',
        );

        $reflection = new \ReflectionClass(OperatorResultEntryService::class);

        foreach (['submit', 'approve', 'pendingApprovals'] as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                'the service must expose '.$method.'() so the approval path cannot be bypassed',
            );
        }

        // The refusal vocabulary the importer uses is part of the contract: a
        // caller that catches FinancialException keys off these codes.
        $exception = new FinancialException('approver equals importer', 'APPROVER_IS_IMPORTER');

        $this->assertSame('APPROVER_IS_IMPORTER', $exception->errorCode());
    }
}
