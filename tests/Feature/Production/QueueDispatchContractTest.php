<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RELEASE-BLOCKING queue dispatch contract.
 *
 * A job class that nothing ever dispatches is not dormant code -- it is a
 * feature that silently does not happen. The notification outbox was never
 * drained, expired sessions were never revoked, MFA challenges never expired
 * and self-exclusions never lapsed, all while the classes implementing those
 * behaviours sat in app/Jobs looking complete and passing their unit tests.
 *
 * The tests passed because they called handle() directly. A unit test calling
 * handle() is not a producer.
 *
 * This test makes the dispatch graph an asserted contract instead of a thing
 * reviewers are expected to notice. Every job must be in exactly one of two
 * states:
 *
 *   WIRED    -- some non-test code dispatches it, or the scheduler runs it.
 *   DEFERRED -- deliberately unwired, listed below with the reason.
 *
 * A new job that is neither fails this test. That is the point: you cannot
 * add a job to this codebase and forget to connect it.
 */
#[Group('production-safety')]
final class QueueDispatchContractTest extends TestCase
{
    /**
     * Jobs that are intentionally not dispatched yet, and why.
     *
     * These are NOT forgotten wiring. app/Console/Commands/Lottery/SettleDrawsCommand
     * states the contract plainly: settlement is the Phase 5.1 NON-MONETARY
     * simulation. It moves no money -- no wallet balance changes, no ledger
     * entry, no financial transaction, no payouts row, bets.payout_id stays
     * NULL. Real-money payout is Phase 5.2 and does not exist yet.
     *
     * config/finance.php agrees: prize_payout.safety_mode defaults to DISABLED,
     * where "settlement calculates and may prepare payout rows, but NEVER
     * credits a wallet".
     *
     * Wiring the disbursement jobs would therefore not be closing a defect --
     * it would be shipping Phase 5.2 without its approval gates, its
     * reconciliation, or its tests. They stay deferred until that phase is
     * commissioned, and this list is the record of that decision.
     *
     * @var array<string, string>
     */
    private const DEFERRED = [
        // ── Phase 5.2: real-money disbursement ──────────────────────────
        'DisburseApprovedPrizeJob' => 'Phase 5.2 real-money payout. Gated behind PRIZE_PAYOUT_SAFETY_MODE=LIVE, which is DISABLED by default and asserted by ProductionConfigSafetyTest.',
        'ExecutePayoutTransferJob' => 'Phase 5.2. Fans a payout batch into transfers; has no meaning until disbursement exists.',
        'DisburseWithdrawalJob' => 'Phase 5.2. Withdrawal disbursement shares the payout rails.',
        'ProcessPrizeSettlementJob' => 'Phase 5.2. The monetary counterpart of the non-monetary settlement simulation.',
        'ProcessFinancialReconciliationJob' => 'Phase 5.2. Reconciles money movements that cannot occur while payout is DISABLED.',

        // ── Operator-triggered by design ────────────────────────────────
        'CertifyDrawResultJob' => 'Certification is a deliberate human act. TickCommand excludes publication from the automated lifecycle for the same reason.',
        'PublishCertifiedDrawJob' => 'Publication is human-approved by design; see TickCommand::STEPS, which omits lottery:publish-result on purpose.',

        // ── Awaiting an upstream producer that does not exist yet ───────
        'MatchDrawPrizesJob' => 'Self-guards on certification plus result fingerprint, so it fails closed. Its producer is the certification flow, which is operator-triggered and not yet automated.',
        'ReconcileCompletedDrawJob' => 'Post-settlement reconciliation; belongs with the Phase 5.2 financial close.',
        'SendWinnerNotificationJob' => 'Consumes a winner_notification row that only prize matching creates, and prize matching is not yet automated.',
        'AllocateRetailTicketQuotaJob' => 'Retail quota allocation is triggered by product activation in the admin panel, which is not yet built.',
        'AllocateTicketInventoryJob' => 'Supply-side inventory allocation is triggered by product activation in the same operator console, which does not exist yet. Nothing retail is minted by it, and it self-guards on the quota it is handed, so it is unwired rather than dangerous — but unwired it is, and this list says so.',
    ];

    /**
     * @return list<string> every job class name under app/Jobs
     */
    private function jobClasses(): array
    {
        $names = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Jobs'), \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/^\s*(?:final\s+)?(?:abstract\s+)?class\s+(\w+)/m', $source, $m) === 1) {
                $names[] = $m[1];
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Every PHP file that could legitimately dispatch a job.
     *
     * tests/ is excluded on purpose: a test calling handle() or dispatching a
     * job inside its own arrangement does not make the job reachable in
     * production, and counting it is precisely the mistake that let twelve
     * dormant jobs ship.
     *
     * @return array<string, string> path => contents
     */
    private function producerCorpus(): array
    {
        $corpus = [];

        foreach (['app', 'routes', 'config', 'database', 'bootstrap'] as $dir) {
            $path = base_path($dir);

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $corpus[$file->getPathname()] = self::codeOnly((string) file_get_contents($file->getPathname()));
            }
        }

        return $corpus;
    }

    /**
     * The file's CODE, with comments removed.
     *
     * A job is WIRED when code dispatches it, not when a docblock mentions its name.
     * Matching raw text made this contract report the opposite of the truth in both
     * directions: a comment naming a dormant job read as "dispatched", and a comment
     * explaining why a job is deferred read as "wired". The write-boundary gate strips
     * comments for exactly this reason, and the fix there left the explanations in
     * place instead of deleting them to satisfy a grep.
     *
     * String literals are KEPT: dispatching by class name from a string
     * (`Bus::dispatch('App\Jobs\X')`) is still dispatching.
     */
    private static function codeOnly(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }

    #[Test]
    public function every_job_is_either_dispatched_or_explicitly_deferred(): void
    {
        $corpus = $this->producerCorpus();
        $orphans = [];

        foreach ($this->jobClasses() as $job) {
            if (array_key_exists($job, self::DEFERRED)) {
                continue;
            }

            $hasProducer = false;

            foreach ($corpus as $path => $contents) {
                // Skip the job's own definition file.
                if (str_contains($path, DIRECTORY_SEPARATOR.$job.'.php')) {
                    continue;
                }

                if (preg_match('/\b'.preg_quote($job, '/').'\b/', $contents) === 1) {
                    $hasProducer = true;
                    break;
                }
            }

            if (! $hasProducer) {
                $orphans[] = $job;
            }
        }

        $this->assertSame(
            [],
            $orphans,
            'These jobs have no producer anywhere outside tests/. Either dispatch them, schedule them, '
            ."or add them to self::DEFERRED with a written reason:\n  - ".implode("\n  - ", $orphans)
        );
    }

    #[Test]
    public function the_deferred_list_contains_no_jobs_that_have_since_been_wired(): void
    {
        $corpus = $this->producerCorpus();
        $stale = [];

        foreach (array_keys(self::DEFERRED) as $job) {
            foreach ($corpus as $path => $contents) {
                if (str_contains($path, DIRECTORY_SEPARATOR.$job.'.php')) {
                    continue;
                }

                if (preg_match('/\b'.preg_quote($job, '/').'\b/', $contents) === 1) {
                    $stale[] = $job;
                    break;
                }
            }
        }

        $this->assertSame(
            [],
            $stale,
            'These jobs are listed as deferred but something now dispatches them. '
            ."Remove them from self::DEFERRED so the list stays truthful:\n  - ".implode("\n  - ", $stale)
        );
    }

    #[Test]
    public function the_deferred_list_contains_no_jobs_that_no_longer_exist(): void
    {
        $existing = $this->jobClasses();
        $ghosts = array_values(array_diff(array_keys(self::DEFERRED), $existing));

        $this->assertSame(
            [],
            $ghosts,
            'These jobs are deferred but no longer exist: '.implode(', ', $ghosts)
        );
    }

    #[Test]
    public function every_deferred_job_carries_a_substantive_reason(): void
    {
        foreach (self::DEFERRED as $job => $reason) {
            $this->assertGreaterThan(
                40,
                strlen($reason),
                "The deferral reason for {$job} is too short to be a real justification."
            );
        }
    }
}
