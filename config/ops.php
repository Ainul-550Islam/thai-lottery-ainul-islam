<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Operational lanes
|--------------------------------------------------------------------------
|
| Every flag in this file switches one entry in the scheduler (bootstrap/
| app.php). Nothing else reads them, which is the point: a monitoring command
| that is neither scheduled nor switched off by an operator is a command that
| runs when a human remembers it.
|
| The defaults here are all `true`, and that is a deliberate difference from
| `finance.ledger.auto_reconcile`, whose config default is true while the
| shipped .env template sets it false. The lanes below write nothing to the
| database and change no state (proven by
| tests/Feature/Console/OperationalLaneTruthTest.php, which runs each one and
| compares row counts), so leaving them on cannot damage a customer record.
| A lane that is on by default can still be switched off per deployment; a lane
| that is off by default is silently absent from every deployment that copies
| the template, which is how five of these commands sat unwired.
|
| The pairing between these keys and the .env template is asserted by
| OperationalLaneTruthTest::test_every_lane_flag_is_documented_in_the_env
| _template(), because an env key nobody documented is an env key nobody will
| dare to change at 3am.
|
*/

return [

    'lanes' => [

        // ops:evaluate-alerts - the alerting brain. OperationalAlertService
        // throttles one alert per fingerprint per DEFAULT_THROTTLE_SECONDS;
        // if the lane runs less often than that window, the evaluation that
        // would have raised the alert happens after the cooldown has already
        // been spent on the previous one, and the condition goes unreported.
        'alerts' => (bool) env('OPS_LANE_ALERTS', true),

        // queue:health --json - pending, failed, stuck and worker liveness.
        // Its own option text says the JSON form exists "for automated
        // monitoring"; a scheduler entry is that automation.
        'queue_health' => (bool) env('OPS_LANE_QUEUE_HEALTH', true),

        // glo:provider-health --json - result-provider configuration plus the
        // circuit-breaker state for each source. The breaker it reports opens
        // for config('glo.breaker.cooldown_seconds') and counts failures over
        // config('glo.sources.breaker.window_seconds'), so a report older than that
        // window describes a source that may have already recovered.
        'provider_health' => (bool) env('OPS_LANE_PROVIDER_HEALTH', true),

        // contact:delivery-health - reports whether support forwarding is
        // configured at all. A configuration fact, not a live queue, so the
        // house's daily retention hour is the honest cadence, and it is why
        // this flag is expected to be turned off by nobody.
        'delivery_health' => (bool) env('OPS_LANE_DELIVERY_HEALTH', true),

        // chat:health - the live-chat desk's transport and configuration
        // report. Gated twice in the scheduler: this flag AND chat.enabled,
        // because a health report for a feature that is switched off is noise
        // that looks like a finding.
        'chat_health' => (bool) env('OPS_LANE_CHAT_HEALTH', true),

        // The scheduler's own liveness. This is the one lane whose absence
        // cannot be detected from any other lane: if the scheduler stops,
        // nothing else in this file ever runs to say so. `ops:health` fails the
        // probe when the heartbeat is older than
        // ops.thresholds.scheduler_heartbeat_minutes.
        'heartbeat' => (bool) env('OPS_LANE_HEARTBEAT', true),

        // Encrypted database backup. Also its own lane: a backup that is
        // switched off produces no error anywhere, and the first symptom is a
        // restore that has nothing to restore.
        'backup' => (bool) env('OPS_LANE_BACKUP', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring thresholds
    |--------------------------------------------------------------------------
    |
    | Read by `ops:health` (exit codes 0/1/2) so an external monitor can alert
    | on the command's exit code alone, without parsing output. Values are
    | deliberately conservative: a degraded signal that fires a little early is
    | cheap, a critical signal that fires after payouts stopped is not.
    |
    */
    'thresholds' => [
        'failed_jobs_degraded' => (int) env('OPS_THRESHOLD_FAILED_JOBS', 10),
        'scheduler_heartbeat_minutes' => (int) env('OPS_THRESHOLD_HEARTBEAT_MINUTES', 10),
        'backup_age_hours' => (int) env('OPS_THRESHOLD_BACKUP_AGE_HOURS', 26),
        'disk_free_percent_critical' => (float) env('OPS_THRESHOLD_DISK_FREE_CRITICAL', 5.0),
        'disk_free_percent_degraded' => (float) env('OPS_THRESHOLD_DISK_FREE_DEGRADED', 15.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup schedule
    |--------------------------------------------------------------------------
    |
    | `routes/console.php` schedules `backup:execute-encrypted-worm` using these
    | values. They live in config rather than in the schedule closure so an
    | operator can move the backup window without editing code (and so the
    | values appear in a config dump during an incident).
    |
    */
    'backup_schedule' => [
        'enabled' => (bool) env('OPS_LANE_BACKUP', true),
        'at' => env('OPS_BACKUP_AT', '02:20'),
        'timezone' => env('OPS_BACKUP_TIMEZONE'),
    ],

];
