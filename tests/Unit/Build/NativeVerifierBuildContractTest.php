<?php

declare(strict_types=1);

namespace Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/** Native tests must be provisioned, not skipped or pointed at a fake executable. */
final class NativeVerifierBuildContractTest extends TestCase
{
    public function test_build_gate_is_valid_bash_and_rejects_arguments_before_tool_lookup(): void
    {
        $script = $this->path('scripts/build-weekly-native-verifier.sh');
        $syntax = new Process(['/bin/bash', '-n', $script], '/');
        $syntax->setTimeout(5)->run();
        $this->assertSame(0, $syntax->getExitCode(), $syntax->getErrorOutput());
        $bad = new Process(['/bin/bash', $script, 'unexpected'], '/', ['PATH' => '/native-tools-do-not-exist']);
        $bad->setTimeout(5)->run();
        $this->assertSame(64, $bad->getExitCode());
        $this->assertStringContainsString('Usage:', $bad->getErrorOutput());
    }

    public function test_missing_tool_is_an_explicit_failure_not_a_skipped_success(): void
    {
        $process = new Process(['/bin/bash', $this->path('scripts/build-weekly-native-verifier.sh')], '/', ['PATH' => '/native-tools-do-not-exist']);
        $process->setTimeout(5)->run();
        $this->assertSame(69, $process->getExitCode());
        $this->assertStringContainsString('Required build tool is missing: cargo', $process->getErrorOutput());
        $this->assertStringNotContainsString('NATIVE_VERIFIER_BUILD_READY', $process->getOutput());
    }

    public function test_build_gate_is_locked_host_specific_and_cannot_target_the_money_worker(): void
    {
        $script = $this->source('scripts/build-weekly-native-verifier.sh');
        $this->assertStringContainsString('set -euo pipefail', $script);
        $this->assertStringContainsString('security/weekly-result-integrity', $script);
        $this->assertStringContainsString('--target "$HOST"', $script);
        $this->assertStringContainsString('--target-dir "$TARGET"', $script);
        $this->assertStringContainsString('cargo fmt', $script);
        $this->assertStringContainsString('cargo clippy --locked', $script);
        $this->assertStringContainsString('cargo test --locked --release', $script);
        $this->assertStringContainsString('cargo build --locked --release', $script);
        $this->assertStringContainsString('"$BEFORE" != "$AFTER"', $script);
        $this->assertStringContainsString('cmp -s', $script);
        $this->assertStringContainsString('mv -f', $script);
        $this->assertStringNotContainsString('runtime/settlement-worker', $script);
        $this->assertStringNotContainsString('php artisan', $script);
        $this->assertStringNotContainsString('cargo update', $script);
        $this->assertStringNotContainsString('cargo install', $script);
    }

    public function test_every_unfiltered_php_ci_gate_builds_native_before_the_suite(): void
    {
        $workflow = Yaml::parseFile($this->path('.github/workflows/ci.yml'));
        $fullJobs = [];

        foreach ($workflow['jobs'] as $name => $job) {
            $native = null;
            $rust = null;

            foreach ($job['steps'] as $index => $step) {
                if (str_starts_with($step['uses'] ?? '', 'dtolnay/rust-toolchain@')) {
                    $rust = $index;
                }

                if (str_contains($step['run'] ?? '', 'bash scripts/build-weekly-native-verifier.sh')) {
                    $native = $index;
                }

                // Recognise an unfiltered suite with strict failure flags;
                // the filtered seam suite continues onto another line and
                // must not be mistaken for this full-suite prerequisite.
                if (preg_match('/^[ \t]*php[ \t]+artisan[ \t]+test(?:[ \t]+--fail-on-(?:skipped|incomplete|empty-test-suite))*[ \t]*$/m', $step['run'] ?? '') === 1) {
                    $fullJobs[] = $name;
                    $this->assertNotNull($rust, $name.' must provision Rust.');
                    $this->assertNotNull($native, $name.' must build the real executable.');
                    $this->assertLessThan($native, $rust);
                    $this->assertLessThan($index, $native);
                }
            }
        }

        sort($fullJobs);
        $this->assertSame(['full-runtime-release', 'test'], $fullJobs);
    }

    public function test_isolated_cross_language_job_uses_real_native_and_an_isolated_environment(): void
    {
        $workflow = Yaml::parseFile($this->path('.github/workflows/ci.yml'));
        $steps = $workflow['jobs']['runtime-and-rust-contracts']['steps'];
        $joined = implode("\n", array_map(fn ($step) => $step['run'] ?? '', $steps));
        $this->assertStringContainsString('cp .env.ci .env', $joined);
        $this->assertStringNotContainsString('cp .env.example .env', $joined);
        $native = null;
        $crosscheck = null;

        foreach ($steps as $index => $step) {
            if (str_contains($step['run'] ?? '', 'bash scripts/build-weekly-native-verifier.sh')) {
                $native = $index;
            }

            if (str_contains($step['run'] ?? '', 'WeeklyNativeVerifierRuntimeTest.php')) {
                $crosscheck = $index;
                $this->assertStringContainsString('WeeklyLotteryPublicPageTest.php', $step['run']);
            }
        }

        $this->assertNotNull($native);
        $this->assertNotNull($crosscheck);
        $this->assertLessThan($crosscheck, $native);
    }

    public function test_required_native_runtime_tests_have_no_skip_or_fake_process_guard(): void
    {
        $tests = $this->source('tests/Feature/WeeklyLottery/WeeklyNativeVerifierRuntimeTest.php');
        $this->assertStringContainsString('isNativeVerifierAvailable()', $tests);
        $this->assertStringContainsString('assertTrue', $tests);
        $this->assertStringNotContainsString('markTestSkipped', $tests);
        $this->assertStringNotContainsString('Process::fake', $tests);
        $this->assertStringContainsString('sodium_crypto_sign_detached', $tests);
        $this->assertStringContainsString('INCOMPLETE_SIGNATURE_MATERIAL', $tests);
        $this->assertStringContainsString('SYNTHETIC-F40', $tests);
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 3).'/'.$relative;
    }

    private function source(string $relative): string
    {
        $contents = file_get_contents($this->path($relative));
        $this->assertIsString($contents);

        return $contents;
    }
}
