<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GloL6Sale;
use App\Models\GloN3Sale;
use App\Models\GloResultImport;
use App\Models\GloSalesReconciliation;
use App\Services\Lottery\GloFixtureResultProvider;
use App\Services\Lottery\GloOfficialResultProvider;
use App\Services\Lottery\Support\BreakerPolicy;
use App\Services\Lottery\Support\CircuitBreaker;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * glo:provider-health — reports configured result providers, their circuit
 * breaker state, and seat/import counters. Read-only. Honest NOT_CONFIGURED
 * surfaced for the official lane.
 *
 * WHY THE BREAKER STATE BELONGS HERE AND NOT IN A SEPARATE VIEW.
 *
 * `configured = true` answers "are the credentials present". It does not answer
 * the question an operator actually has during an incident, which is "is the
 * source WORKING" — and until this command reported breaker state, the only way
 * to tell a source that is merely configured from one that is refusing every
 * fetch was to read the application log. A result lane that is quietly open-
 * circuit is the failure mode that costs a draw: imports stop, nothing alerts,
 * and the draw sits unpublished until somebody notices by hand.
 *
 * THIS COMMAND MUTATES NOTHING. It calls describe(), which is a pure read, and
 * never allows() — and it deliberately does NOT consult the circuit before
 * reporting, so an open circuit is visible rather than hidden by the breaker
 * short-circuiting the report itself.
 */
class GloProviderHealth extends Command
{
    protected $signature = 'glo:provider-health {--json : JSON output}';
    protected $description = 'Report GLO result-provider configuration and sales-seat health (read-only)';

    public function handle(
        GloFixtureResultProvider $fixture,
        GloOfficialResultProvider $official,
        CircuitBreaker $breaker,
        ConfigRepository $config,
    ): int {
        $mode = (string) config('glo.official_source.mode', 'fixture');
        $policy = BreakerPolicy::fromConfig($config);
        $base = rtrim((string) config('glo.official_source.base_url', ''), '/');
        $latest = (string) config('glo.official_source.latest_lottery_path', '');
        $result = (string) config('glo.official_source.lottery_result_path', '');

        $health = [
            'command' => 'glo:provider-health',
            'mode' => $mode,
            'providers' => [
                'fixture' => [
                    'configured' => $fixture->isConfigured(),
                    'status' => $fixture->isConfigured() ? 'ready' : 'not_configured',
                ],
                'official' => [
                    'configured' => $official->isConfigured(),
                    'status' => $official->isConfigured() ? 'configured_not_probed' : 'not_configured',
                    'endpoints' => [
                        'base' => $base,
                        'latest' => $base.$latest,
                        'result' => $base.$result,
                    ],
                    'note' => 'Only documented glo.or.th catalog endpoints are ever called.',
                ],
            ],
            'ladder' => [
                'priority' => array_values((array) config('glo.sources.priority', [])),
                'configured_from' => 'glo.sources.priority',
                'fall_through_to_fixture' => (bool) config('glo.sources.fall_through_to_fixture', false),
                'effective_order' => $this->effectiveOrder(),
                'note' => 'An empty priority means the ladder is the single source named by `mode`. '
                    .'fall_through_to_fixture answers with SYNTHETIC vectors when the official fetch fails, '
                    .'and ProductionSafetyServiceProvider refuses to boot production while it is true.',
            ],
            'breakers' => [
                'enabled' => $policy->enabled,
                'failure_threshold' => $policy->failureThreshold,
                'cooldown_seconds' => $policy->cooldownSeconds,
                'half_open_probes' => $policy->halfOpenProbes,
                'sources' => [
                    'official' => $breaker->describe('official', $policy),
                    'fixture' => $breaker->describe('fixture', $policy),
                ],
            ],
            'counters' => $this->safeCounters(),
            'conflict_gate' => (string) config('glo.reconciliation.conflict_gate', 'GLON3_SALES_CONFLICT'),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('GLO provider health');
            $this->line('  mode: '.$mode);
            $this->line('  fixture: '.($health['providers']['fixture']['status']));
            $this->line('  official: '.($health['providers']['official']['status']));
            $this->line('  ladder: '.($health['ladder']['effective_order'] === []
                ? '(none configured)'
                : implode(' -> ', $health['ladder']['effective_order'])));
            $this->line('  fall_through_to_fixture: '.($health['ladder']['fall_through_to_fixture'] ? 'true (synthetic results reachable)' : 'false'));

            foreach ($health['breakers']['sources'] as $source => $state) {
                $this->line(sprintf(
                    '  breaker[%s]: %s failures=%d%s',
                    $source,
                    $state['state'],
                    $state['failures'],
                    $state['retry_after_seconds'] > 0 ? ' retry_after='.$state['retry_after_seconds'].'s' : '',
                ));
            }
            $this->line('  l6_seats='.$health['counters']['l6_seats']
                .' n3_seats='.$health['counters']['n3_seats']
                .' n3_conflicted='.$health['counters']['n3_conflicted']
                .' imports='.$health['counters']['imports_total']
                .' not_configured='.$health['counters']['imports_not_configured']
                .' reconciliations='.$health['counters']['reconciliations']);
        }

        return self::SUCCESS;
    }

    /**
     * The order the ladder will actually walk, expanded from the same rules
     * GloResultProviderChain uses.
     *
     * DUPLICATED ON PURPOSE, AND NARROWLY. The chain's selection is private
     * because it must be the only authority on what it fetches; a health command
     * that could change the ladder would be a health command that causes the
     * incident. What is duplicated is eleven lines of ordering with no I/O and no
     * side effects, and the alternative — reporting `priority: []` and leaving an
     * operator to work out what that expands to — is the kind of gap that makes a
     * diagnostic command useless exactly when it is needed.
     *
     * If these ever disagree, the chain is right and this method is a bug.
     *
     * @return list<string>
     */
    private function effectiveOrder(): array
    {
        $configured = config('glo.sources.priority');

        if (is_array($configured) && $configured !== []) {
            $names = array_map(
                static fn ($value): string => strtolower(trim((string) $value)),
                $configured,
            );
        } else {
            $names = [(string) config('glo.official_source.mode', 'fixture')];

            if ((bool) config('glo.sources.fall_through_to_fixture', false)) {
                $names[] = 'fixture';
            }
        }

        $implemented = ['official', 'fixture'];
        $out = [];

        foreach ($names as $name) {
            if ($name !== '' && in_array($name, $implemented, true)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Counters are best-effort: when the default connection is unavailable
     * (e.g. MySQL not running in a sandbox), report unavailable rather than
     * crash — provider config health must not depend on the DB.
     *
     * @return array<string, int|string>
     */
    private function safeCounters(): array
    {
        try {
            return [
                'l6_seats' => GloL6Sale::query()->count(),
                'n3_seats' => GloN3Sale::query()->count(),
                'n3_conflicted' => GloN3Sale::query()->where('seat_state', 'conflicted')->count(),
                'imports_total' => GloResultImport::query()->count(),
                'imports_imported' => GloResultImport::query()->where('status', 'imported')->count(),
                'imports_not_configured' => GloResultImport::query()->where('status', 'not_configured')->count(),
                'reconciliations' => GloSalesReconciliation::query()->count(),
                'reconciliations_conflicted' => GloSalesReconciliation::query()->where('status', 'conflicted')->count(),
            ];
        } catch (\Throwable $e) {
            return [
                'l6_seats' => 'unavailable',
                'n3_seats' => 'unavailable',
                'n3_conflicted' => 'unavailable',
                'imports_total' => 'unavailable',
                'imports_imported' => 'unavailable',
                'imports_not_configured' => 'unavailable',
                'reconciliations' => 'unavailable',
                'reconciliations_conflicted' => 'unavailable',
                'db_error' => $e->getMessage(),
            ];
        }
    }
}
