<?php

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            // SQLiteConnector skips the pragma entirely when this key is null,
            // so hardcoding null meant the `DB_BUSY_TIMEOUT` phpunit.xml sets
            // for the disposable test store never reached the driver: a second
            // writer got SQLITE_BUSY immediately instead of waiting. Read the
            // value, and stay null (driver default) when it is unset or empty.
            'busy_timeout' => filled(env('DB_BUSY_TIMEOUT')) ? (int) env('DB_BUSY_TIMEOUT') : null,
            // Read from env for the same reason as busy_timeout above: a hardcoded
            // null means SQLiteConnector skips the pragma and no deployment can
            // choose a journal mode without editing this file. Stay null (driver
            // default) when unset or empty.
            //
            // MEASURED, NOT ASSUMED: setting this to `wal` for the test store made
            // concurrency WORSE, not better. Three consecutive runs of
            // tests/Feature/About + AccountGradeProgrammeTest failed 1/3, 1/3, 1/3
            // with WAL against 0/3, 0/3, 0/3 without it, and a full Feature run
            // picked up 7 new "database is locked" QueryExceptions in the first two
            // directories - on tests that touch no database at all, the lock landing
            // in TestCase::setUp's LedgerAccountSeeder. WAL also leaves -wal/-shm
            // files behind that outlive a killed run. So nothing in this repository
            // sets DB_JOURNAL_MODE; the knob exists because a deployment on a
            // single-writer disk may want it, not because the test suite does.
            // BetPurchaseAtomicityTest::skipIfTheDatabaseRefusedToRunTheProbe() is
            // what makes the real-process concurrency probes honest on SQLite.
            'journal_mode' => filled(env('DB_JOURNAL_MODE')) ? env('DB_JOURNAL_MODE') : null,
            'synchronous' => null,
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'thai_lottery'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                // PHP 8.5 deprecated the PDO::MYSQL_* constants in favour of the dedicated
                // Pdo\Mysql class. Both NAMES reach the same integer for a given option -
                // SSL_CA is 1009 and SSL_CERT is 1008, and they are not interchangeable:
                // this array is keyed by that integer, so a wrong constant here sends the
                // CA path to the driver as a client certificate and CA verification is
                // silently not applied while the connection still succeeds. Preferring the
                // new name preserves behaviour and drops the deprecation notice.
                //
                // The fallback is reached only on PHP 8.2 to 8.4, where Pdo\Mysql does
                // not exist and the old constant is not deprecated. It is fetched with
                // constant() rather than written as a compiled constant reference so
                // that neither class name is compiled in on a PHP version where it is
                // absent or deprecated, and so that the formatter leaves both alone.
                (class_exists('Pdo\Mysql')
                    ? constant('Pdo\Mysql::ATTR_SSL_CA')
                    : constant('PDO::MYSQL_ATTR_SSL_CA')) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'thai_lottery'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', 'thai_lottery_'),
        ],
        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],
    ],

];
