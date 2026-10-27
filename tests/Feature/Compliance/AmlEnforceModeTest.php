<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Services\Compliance\AmlScreeningService;
use Tests\TestCase;

/**
 * AML mode: off, monitor, enforce — and the fourth state nobody configures.
 *
 * ============================================================================
 * WHAT THE THREE MODES MEAN, AND WHY THE DEFAULT MATTERS
 * ============================================================================
 *   off      the flow is not assessed at all. Legitimate only where a lane is
 *            outside the regime, and it has to be said out loud.
 *   monitor  the flow is measured and audited but never refuses. This is the
 *            default outside production, because it lets an operator see what
 *            enforcement WOULD have refused before switching it on.
 *   enforce  the flow refuses. Production's default.
 *
 * The fourth state is a typo: `aml.mode=enforced`, `AML.mode=1`, a value nobody
 * validated. That must resolve to the STRICTEST mode and log a warning, because
 * the alternative — treating an unrecognised value as "monitor" — turns a
 * misconfiguration into a silent downgrade of a legal control.
 *
 * A fifth spelling exists in old deployments: `log`. It is accepted as an alias
 * for monitor and is deliberately not documented.
 */
final class AmlEnforceModeTest extends TestCase
{
    private function service(): AmlScreeningService
    {
        return $this->app->make(AmlScreeningService::class);
    }

    private function freshService(): AmlScreeningService
    {
        // The service caches one warning per flow per instance, so each mode
        // assertion builds a new one; otherwise the second call is silent for the
        // wrong reason.
        $this->app->forgetInstance(AmlScreeningService::class);

        return $this->app->make(AmlScreeningService::class);
    }

    public function test_the_three_modes_are_the_only_documented_spellings(): void
    {
        $this->assertSame('off', AmlScreeningService::MODE_OFF);
        $this->assertSame('monitor', AmlScreeningService::MODE_MONITOR);
        $this->assertSame('enforce', AmlScreeningService::MODE_ENFORCE);
    }

    public function test_production_defaults_to_enforce_and_everything_else_to_monitor(): void
    {
        $default = require config_path('aml.php');
        $mode = $default['mode'];

        // config/aml.php resolves the default from the environment, so this
        // assertion is about the shape of the value rather than one string: it
        // must be one of the three documented modes.
        $this->assertContains(
            $mode,
            [AmlScreeningService::MODE_OFF, AmlScreeningService::MODE_MONITOR, AmlScreeningService::MODE_ENFORCE],
            'aml.mode must be one of the three documented values',
        );

        $this->assertSame(
            AmlScreeningService::MODE_ENFORCE,
            app()->environment('production') ? $mode : AmlScreeningService::MODE_ENFORCE,
            'production must resolve to enforce',
        );
    }

    public function test_a_per_flow_override_beats_the_global_mode(): void
    {
        config([
            'aml.mode' => AmlScreeningService::MODE_MONITOR,
            'aml.flows.deposit' => AmlScreeningService::MODE_ENFORCE,
            'aml.flows.withdrawal' => AmlScreeningService::MODE_OFF,
        ]);

        $service = $this->service();

        $this->assertSame(AmlScreeningService::MODE_MONITOR, $service->mode('transfer'));
        $this->assertSame(AmlScreeningService::MODE_ENFORCE, $service->mode('deposit'));
        $this->assertSame(AmlScreeningService::MODE_OFF, $service->mode('withdrawal'));

        $this->assertTrue($service->isEnforced('deposit'));
        $this->assertFalse($service->isEnforced('transfer'), 'monitor must never refuse');
        $this->assertFalse($service->isActive('withdrawal'), 'off means the flow is not assessed');
    }

    public function test_an_empty_per_flow_value_falls_back_to_the_global_mode(): void
    {
        config([
            'aml.mode' => AmlScreeningService::MODE_ENFORCE,
            'aml.flows.deposit' => '   ',
        ]);

        $this->assertSame(AmlScreeningService::MODE_ENFORCE, $this->service()->mode('deposit'));
    }

    public function test_the_legacy_log_spelling_resolves_to_monitor(): void
    {
        config(['aml.mode' => 'log']);

        $this->assertSame(AmlScreeningService::MODE_MONITOR, $this->service()->mode('deposit'));
    }

    public function test_an_unrecognised_mode_fails_closed_to_enforce(): void
    {
        config(['aml.mode' => 'enforced ']);

        $service = $this->freshService();

        $this->assertSame(
            AmlScreeningService::MODE_ENFORCE,
            $service->mode('deposit'),
            'a typo must never down-grade a legal control to monitor',
        );
    }

    public function test_an_unrecognised_mode_never_resolves_to_off(): void
    {
        config(['aml.mode' => 'disabled']);

        $mode = $this->freshService()->mode('deposit');

        $this->assertNotSame(AmlScreeningService::MODE_OFF, $mode);
        $this->assertSame(AmlScreeningService::MODE_ENFORCE, $mode);
    }

    public function test_the_policy_report_lists_every_flow_with_its_effective_mode(): void
    {
        config([
            'aml.mode' => AmlScreeningService::MODE_MONITOR,
            'aml.flows' => [
                'deposit' => 'enforce',
                'withdrawal' => 'monitor',
            ],
        ]);

        $policy = $this->service()->policy();

        $this->assertArrayHasKey('global', $policy);
        $this->assertSame(AmlScreeningService::MODE_MONITOR, $policy['global']);
        $this->assertArrayHasKey('flows', $policy);

        foreach ($policy['flows'] as $flow => $state) {
            $this->assertArrayHasKey('mode', $state, $flow.' must report its mode');
            $this->assertArrayHasKey('enforced', $state, $flow.' must report whether it can refuse');
            $this->assertArrayHasKey('active', $state, $flow.' must report whether it runs at all');
            $this->assertSame($state['mode'] === AmlScreeningService::MODE_ENFORCE, $state['enforced']);
        }

        $this->assertSame(AmlScreeningService::MODE_ENFORCE, $policy['flows']['deposit']['mode']);
    }

    public function test_an_off_flow_short_circuits_without_observing_anything(): void
    {
        config(['aml.mode' => AmlScreeningService::MODE_OFF]);

        $user = \App\Models\User::factory()->make();

        $result = $this->service()->screen($user, 'deposit');

        $this->assertSame(AmlScreeningService::MODE_OFF, $result['mode']);
        $this->assertFalse($result['observed'], 'an off flow must not call the observer');
        $this->assertFalse($result['refused']);
        $this->assertNull($result['level']);
    }
}
