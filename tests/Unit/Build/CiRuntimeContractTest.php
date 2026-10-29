<?php

declare(strict_types=1);

namespace Tests\Unit\Build;

use Dotenv\Dotenv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Build prerequisites must be explicit. A missing encoder or an unsupported
 * PHP version is an infrastructure failure, not a reason to weaken a test.
 */
final class CiRuntimeContractTest extends TestCase
{
    #[DataProvider('workflows')]
    public function test_every_php_setup_uses_the_project_php_version(string $workflow): void
    {
        $composer = json_decode(file_get_contents($this->path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('^8.4', $composer['require']['php']);
        $document = Yaml::parseFile($this->path($workflow));
        $checked = 0;

        foreach ($document['jobs'] as $job) {
            foreach ($job['steps'] as $step) {
                if (str_starts_with($step['uses'] ?? '', 'shivammathur/setup-php@')) {
                    $this->assertSame('8.4', (string) $step['with']['php-version']);
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(0, $checked);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function workflows(): array
    {
        return [
            'test pipeline' => ['.github/workflows/ci.yml'],
            'security pipeline' => ['.github/workflows/security.yml'],
        ];
    }

    public function test_php_test_jobs_declare_intl_gd_and_the_sqlite_test_driver(): void
    {
        $document = Yaml::parseFile($this->path('.github/workflows/ci.yml'));

        foreach (['test', 'runtime-and-rust-contracts', 'full-runtime-release'] as $name) {
            $setups = array_values(array_filter(
                $document['jobs'][$name]['steps'],
                static fn (array $step): bool => str_starts_with($step['uses'] ?? '', 'shivammathur/setup-php@'),
            ));
            $this->assertCount(1, $setups);
            $extensions = array_map('trim', explode(',', $setups[0]['with']['extensions']));

            foreach (['intl', 'gd', 'sqlite3', 'pdo_sqlite', 'pdo_mysql', 'bcmath'] as $required) {
                $this->assertContains($required, $extensions, "$name requires $required");
            }
        }
    }

    public function test_every_php_case_is_routed_to_a_strict_lane_and_reconciled(): void
    {
        $document = Yaml::parseFile($this->path('.github/workflows/ci.yml'));
        foreach (['test' => 'sqlite', 'full-runtime-release' => 'mysql'] as $job => $lane) {
            $steps = $document['jobs'][$job]['steps'];
            $index = $this->stepContaining($steps, 'python3 scripts/run_php_test_lane.py --lane '.$lane);
            $this->assertStringContainsString('--output evidence/php-suite/'.$lane, $steps[$index]['run']);
            $uploads = array_values(array_filter($steps, static fn (array $step): bool =>
                ($step['with']['name'] ?? '') === 'php-case-census-'.$lane));
            $this->assertCount(1, $uploads);
            $this->assertSame('always()', $uploads[0]['if']);
        }
        $census = $document['jobs']['php-case-census'];
        $this->assertSame(['test', 'full-runtime-release'], $census['needs']);
        $this->assertSame('always()', $census['if']);
        $this->stepContaining($census['steps'], 'python3 scripts/run_php_test_lane.py --reconcile');
        $results = $this->stepContaining($census['steps'], 'test "$SQLITE_JOB_RESULT" = success');
        $this->assertStringContainsString('test "$MYSQL_JOB_RESULT" = success', $census['steps'][$results]['run']);
        $downloads = array_column(array_column($census['steps'], 'with'), 'name');
        $this->assertContains('php-case-census-sqlite', $downloads);
        $this->assertContains('php-case-census-mysql', $downloads);
        $runner = file_get_contents($this->path('scripts/run_php_test_lane.py'));
        foreach (['--fail-on-skipped', '--fail-on-risky', '--fail-on-incomplete', '--fail-on-empty-test-suite',
            'check_junit', 'Counter(expected) - Counter(actual)', 'len(ids) != len(set(ids))'] as $required) {
            $this->assertStringContainsString($required, $runner);
        }
    }

    public function test_mysql_lane_provisions_a_local_socket_and_real_lock_observation(): void
    {
        $document = Yaml::parseFile($this->path('.github/workflows/ci.yml'));
        $steps = $document['jobs']['full-runtime-release']['steps'];
        $socket = $this->stepContaining($steps, 'UNIX-LISTEN:$socket');
        $grants = $this->stepContaining($steps, 'GRANT SELECT ON performance_schema.data_locks');
        $suite = $this->stepContaining($steps, 'python3 scripts/run_php_test_lane.py --lane mysql');
        $this->assertLessThan($suite, $socket);
        $this->assertLessThan($suite, $grants);
        foreach (['TCP:127.0.0.1:3306', 'test -S "$socket"', '--protocol=SOCKET', 'DB_SOCKET=$socket'] as $required) {
            $this->assertStringContainsString($required, $steps[$socket]['run']);
        }
        foreach (['performance_schema.data_locks', 'performance_schema.data_lock_waits',
            'performance_schema.threads', 'GRANT PROCESS ON *.*'] as $required) {
            $this->assertStringContainsString($required, $steps[$grants]['run']);
        }
        $this->assertStringNotContainsString('GRANT SUPER', $steps[$grants]['run']);
        foreach ([$socket, $grants] as $index) {
            $syntax = new Process(['/bin/bash', '-n', '-c', str_replace('${{ job.services.mysql.id }}', 'test-container', $steps[$index]['run'])]);
            $syntax->run();
            $this->assertSame(0, $syntax->getExitCode(), $syntax->getErrorOutput());
        }
    }

    public function test_mysql_release_service_allows_real_failure_injection_without_granting_super(): void
    {
        $document = Yaml::parseFile($this->path('.github/workflows/ci.yml'));
        $steps = $document['jobs']['full-runtime-release']['steps'];
        $trigger = $this->stepContaining($steps, 'SET GLOBAL log_bin_trust_function_creators=1');
        $suite = $this->stepContaining($steps, 'python3 scripts/run_php_test_lane.py --lane mysql');
        $this->assertLessThan($suite, $trigger);
        $run = $steps[$trigger]['run'];
        $this->assertStringContainsString('${{ job.services.mysql.id }}', $run);
        $this->assertStringContainsString('SELECT @@GLOBAL.log_bin_trust_function_creators', $run);
        $this->assertStringContainsString('test "$value" = 1', $run);
        $this->assertStringNotContainsString('GRANT SUPER', $run);
        $this->assertStringNotContainsString('|| true', $run);
    }

    public function test_mysql_release_builds_and_runs_the_live_worker_without_skipping_its_native_fixture(): void
    {
        $document = Yaml::parseFile($this->path('.github/workflows/ci.yml'));
        $job = $document['jobs']['full-runtime-release'];
        $this->assertSame('1', (string) $job['env']['SETTLEMENT_WORKER_LIVE_TEST']);
        $this->assertSame('http://127.0.0.1:9101', $job['env']['SETTLEMENT_WORKER_LIVE_URL']);
        $this->assertSame('1', (string) $job['env']['CONCURRENCY_TEST_ALLOW_DESTRUCTIVE']);
        $this->assertStringContainsString('mysql://concurrency_test:', $job['env']['CONCURRENCY_TEST_DSN']);
        $this->assertStringEndsWith('/thai_lottery_conc', $job['env']['CONCURRENCY_TEST_DSN']);
        $steps = $job['steps'];
        $worker = $this->stepContaining($steps, 'MYSQL_TEST_ALLOW_DESTRUCTIVE=1');
        $suite = $this->stepContaining($steps, 'python3 scripts/run_php_test_lane.py --lane mysql');
        $this->assertLessThan($suite, $worker);
        $run = $steps[$worker]['run'];
        foreach ([
            'CREATE DATABASE settlement_worker_destructive_test',
            'GRANT ALL PRIVILEGES ON settlement_worker_destructive_test.*',
            'GRANT SELECT ON thai_lottery_test.*',
            'CREATE DATABASE thai_lottery_conc',
            'GRANT ALL PRIVILEGES ON thai_lottery_conc.*',
            'cargo test --locked --manifest-path runtime/settlement-worker/Cargo.toml -- --include-ignored',
            '0 failed; 0 ignored;',
            'SETTLEMENT_WORKER_HMAC_SECRET=',
            'settlement-worker --healthcheck',
        ] as $required) {
            $this->assertStringContainsString($required, $run);
        }
        $this->assertStringNotContainsString('|| true', $run);
        $syntax = new Process(['/bin/bash', '-n', '-c', str_replace('${{ job.services.mysql.id }}', 'test-container', $run)]);
        $syntax->run();
        $this->assertSame(0, $syntax->getExitCode(), $syntax->getErrorOutput());
    }

    public function test_mysql_release_backup_restores_under_scoped_permissions_and_compares_data_and_schema(): void
    {
        $document = Yaml::parseFile($this->path('.github/workflows/ci.yml'));
        $job = $document['jobs']['full-runtime-release'];
        $this->assertSame('mysql:8.0', $job['services']['mysql']['image']);
        $steps = $job['steps'];
        $index = $this->stepContaining($steps, 'scripts/mysql-roundtrip-fingerprint.sql');
        $run = $steps[$index]['run'];

        foreach ([
            'set -euo pipefail', '--no-tablespaces', '--single-transaction',
            '--routines --events', 'docker exec -e MYSQL_PWD=ci_root_password',
            'CREATE DATABASE thailotto_restore', 'GRANT ALL PRIVILEGES ON thailotto_restore.*',
            '--no-create-info --skip-triggers',
            'cmp /tmp/thai_lottery_test.rows.sql /tmp/thailotto_restore.rows.sql',
            'cmp /tmp/thai_lottery_test.schema.txt /tmp/thailotto_restore.schema.txt',
        ] as $required) {
            $this->assertStringContainsString($required, $run);
        }
        $this->assertStringNotContainsString('|| true', $run);
        $this->assertStringNotContainsString('--user=thailotto_ci -e "CREATE DATABASE', $run);
        $syntax = new Process(['/bin/bash', '-n', '-c', str_replace('${{ job.services.mysql.id }}', 'test-container', $run)]);
        $syntax->run();
        $this->assertSame(0, $syntax->getExitCode(), $syntax->getErrorOutput());

        $fingerprint = file_get_contents($this->path('scripts/mysql-roundtrip-fingerprint.sql'));
        foreach (['SCHEMATA', 'TABLES', 'COLUMNS', 'STATISTICS', 'TABLE_CONSTRAINTS',
            'KEY_COLUMN_USAGE', 'REFERENTIAL_CONSTRAINTS', 'VIEWS', 'TRIGGERS', 'ROUTINES', 'EVENTS'] as $section) {
            $this->assertStringContainsString('information_schema.'.$section, $fingerprint);
        }
    }

    public function test_sqlite_lane_provisions_a_checksum_verified_helm_binary(): void
    {
        $document = Yaml::parseFile($this->path('.github/workflows/ci.yml'));
        $steps = $document['jobs']['test']['steps'];
        $helm = $this->stepContaining($steps, 'helm-v3.19.4-linux-amd64.tar.gz');
        $suite = $this->stepContaining($steps, 'python3 scripts/run_php_test_lane.py --lane sqlite');
        $this->assertLessThan($suite, $helm);
        $this->assertStringContainsString('759c656fbd9c11e6a47784ecbeac6ad1eb16a9e76d202e51163ab78504848862', $steps[$helm]['run']);
        $this->assertStringContainsString('sha256sum --check', $steps[$helm]['run']);
        $this->assertStringContainsString('$GITHUB_PATH', $steps[$helm]['run']);
    }

    public function test_primary_job_checks_the_runtime_before_installing_dependencies(): void
    {
        $document = Yaml::parseFile($this->path('.github/workflows/ci.yml'));
        $steps = $document['jobs']['test']['steps'];
        $check = $this->stepContaining($steps, 'php scripts/check-test-runtime.php');
        $install = $this->stepContaining($steps, 'composer install');

        $this->assertLessThan($install, $check);
    }

    public function test_environment_is_prepared_before_install_but_key_generation_waits_for_vendor(): void
    {
        $document = Yaml::parseFile($this->path('.github/workflows/ci.yml'));

        foreach (['test', 'full-runtime-release'] as $name) {
            $steps = $document['jobs'][$name]['steps'];
            $copy = $this->stepContaining($steps, 'cp .env.ci .env');
            $install = $this->stepContaining($steps, 'composer install');
            $key = $this->stepContaining($steps, 'php artisan key:generate --force');

            $this->assertLessThan($install, $copy, "$name must not read a developer's environment.");
            $this->assertGreaterThan($install, $key, "$name cannot run artisan before autoload.php exists.");
        }
    }

    public function test_ci_environment_is_isolated_and_keeps_automatic_payout_disabled(): void
    {
        $file = $this->path('.env.ci');
        $this->assertFileExists($file);
        $environment = Dotenv::parse(file_get_contents($file));

        $this->assertSame('testing', $environment['APP_ENV']);
        $this->assertSame('false', $environment['APP_DEBUG']);
        $this->assertSame('sqlite', $environment['DB_CONNECTION']);
        $this->assertSame('database/thai_lottery_test', $environment['DB_DATABASE']);
        $this->assertSame('DISABLED', $environment['PRIZE_PAYOUT_SAFETY_MODE']);
        $this->assertSame('false', $environment['LOTTERY_AUTO_PAYOUT']);
        $this->assertSame('false', $environment['AGENT_COMMISSION_ENABLED']);
    }

    public function test_runtime_gate_reports_actual_extension_and_encoder_availability(): void
    {
        $script = $this->path('scripts/check-test-runtime.php');
        $this->assertFileExists($script);
        $process = new Process([PHP_BINARY, $script]);
        $process->setTimeout(15);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $report = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($report['ready']);
        $this->assertSame([], $report['missing_extensions']);
        $this->assertTrue($report['jpeg_encoder']);
        $this->assertTrue($report['extensions']['intl']);
        $this->assertTrue($report['extensions']['gd']);
    }

    public function test_runtime_gate_fails_closed_if_the_jpeg_encoder_is_unavailable(): void
    {
        $script = $this->path('scripts/check-test-runtime.php');
        $this->assertFileExists($script);
        $process = new Process([PHP_BINARY, '-d', 'disable_functions=imagejpeg', $script]);
        $process->setTimeout(15);
        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $report = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($report['ready']);
        $this->assertFalse($report['jpeg_encoder']);
        $this->assertContains('GD must provide the JPEG encoder (imagejpeg).', $report['problems']);
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function stepContaining(array $steps, string $command): int
    {
        foreach ($steps as $index => $step) {
            if (str_contains($step['run'] ?? '', $command)) {
                return $index;
            }
        }

        $this->fail("Missing CI command: $command");
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 3).'/'.$relative;
    }
}
