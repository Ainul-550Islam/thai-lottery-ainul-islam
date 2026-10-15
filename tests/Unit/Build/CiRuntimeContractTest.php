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
