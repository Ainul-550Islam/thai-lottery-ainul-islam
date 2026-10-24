<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Database backup lane
|--------------------------------------------------------------------------
|
| This file configures the encrypted database backup and restore-verification
| lane implemented by:
|
|   app/Support/Backup/BackupCipher.php
|   app/Services/Infrastructure/DatabaseDumpService.php
|   app/Console/Commands/Infrastructure/ExecuteEncryptedBackupCommand.php
|   app/Console/Commands/Infrastructure/VerifyBackupCommand.php
|   scripts/ops/backup.sh, scripts/ops/restore.sh, scripts/ops/verify-backup.sh
|
| WHY A KEY IS REQUIRED AND NOT DEFAULTED
| The previous implementation of this lane fell back to a literal string
| ("HardenedBackupKey2026MasterSecret!") that was committed to the repository.
| Every backup ever produced with it is decryptable by anybody who can read
| the source, which makes the encryption decorative. There is deliberately no
| default here: `backup:execute-encrypted-worm` refuses to run without
| BACKUP_ENCRYPTION_KEY (or BACKUP_ENCRYPTION_KEY_FILE), and
| `ops:preflight` refuses to pass a production preflight without it.
|
| BACKUP_ENCRYPTION_KEY_FILE exists for container and orchestrator
| deployments that mount secrets as files (Docker secrets, Kubernetes
| secrets). It takes precedence over the inline variable so a leaked
| environment dump does not carry the key.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Master key
    |----------------------------------------------------------------------
    |
    | Minimum length is enforced in two places, on purpose:
    |   * the cipher accepts >= 16 characters so a local drill can use a short
    |     disposable key;
    |   * ops:preflight requires >= 32 characters in production, because a
    |     backup key is the single secret that protects the entire financial
    |     ledger when the archive leaves the host.
    |
    */
    'key' => env('BACKUP_ENCRYPTION_KEY'),
    'key_file' => env('BACKUP_ENCRYPTION_KEY_FILE'),

    /*
    |----------------------------------------------------------------------
    | Where archives live
    |----------------------------------------------------------------------
    |
    | `path` is resolved relative to the application base path when it is not
    | absolute. It must be writable by the runtime user and must NOT be inside
    | the web root (public/): an unauthenticated download of the ledger dump
    | would be a total loss of confidentiality even though the file is
    | encrypted.
    |
    */
    'path' => env('BACKUP_PATH', storage_path('app/backups')),
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),
    'minimum_keep' => (int) env('BACKUP_MINIMUM_KEEP', 7),

    /*
    |----------------------------------------------------------------------
    | Dump behaviour
    |----------------------------------------------------------------------
    |
    | Volatile tables are excluded by default. They carry no business state and
    | restoring them over a live system would resurrect expired sessions,
    | replay old queued jobs and re-deliver notifications. A restore that
    | re-sends a payout notification is a customer-visible incident.
    |
    */
    'restore' => [
        // The RESTORE path must not reuse the dump binary. Using mysqldump to
        // import a file silently produces a valid, empty database: mysqldump
        // ignores stdin, exits 0, and writes a dump of the empty target to
        // stdout. That failure mode was found by the restore drill and is the
        // reason these are configured separately.
        'mysql_binary' => env('BACKUP_MYSQL_CLIENT_BINARY', 'mysql'),
        'pgsql_binary' => env('BACKUP_PSQL_BINARY', 'psql'),
        'refuse_database_patterns' => [
            'prod',
            'production',
            'live',
            'primary',
        ],
        'allow_when_production' => (bool) env('BACKUP_RESTORE_ALLOW_PRODUCTION', false),
    ],

    'dump' => [
        'timeout_seconds' => (int) env('BACKUP_DUMP_TIMEOUT', 3600),
        'exclude_tables' => [
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'failed_jobs',
            'sessions',
            'password_reset_tokens',
            'telescope_entries',
            'telescope_entries_tags',
            'telescope_monitoring',
        ],
        'mysql' => [
            'binary' => env('BACKUP_MYSQLDUMP_BINARY', 'mysqldump'),
            // --single-transaction gives a consistent snapshot of InnoDB
            // without locking the ledger against live writes.
            'options' => [
                '--single-transaction',
                '--quick',
                '--skip-lock-tables',
                '--default-character-set=utf8mb4',
                '--hex-blob',
                '--routines',
                '--events',
                '--triggers',
                // --set-gtid-purged only exists on MySQL 8; it is filtered out
                // automatically when the server is MariaDB (see the service).
                '--set-gtid-purged=OFF',
            ],
        ],
        'pgsql' => [
            'binary' => env('BACKUP_PG_DUMP_BINARY', 'pg_dump'),
            'options' => [
                '--no-owner',
                '--no-privileges',
                '--clean',
                '--if-exists',
            ],
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Off-host copy
    |----------------------------------------------------------------------
    |
    | Optional. The lane never claims to have shipped an archive it did not
    | ship: when `disk` is null the command reports UPLOAD=SKIPPED, and when
    | `--upload` is passed without a configured disk the command FAILS.
    |
    */
    'upload' => [
        'disk' => env('BACKUP_DISK'),
        'prefix' => env('BACKUP_DISK_PREFIX', 'database'),
    ],

    /*
    |----------------------------------------------------------------------
    | Restore safety
    |----------------------------------------------------------------------
    |
    | A restore is destructive by definition. `scripts/ops/restore.sh` refuses
    | to run unless the target database name is echoed back explicitly, and it
    | refuses outright when the target looks like a production database unless
    | the caller also passes --i-understand-this-overwrites-data.
    |
    */
];
