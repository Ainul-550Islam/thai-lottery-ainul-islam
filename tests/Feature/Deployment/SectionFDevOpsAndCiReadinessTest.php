<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class SectionFDevOpsAndCiReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_f1_f2_ci_workflows_target_php_84_build_assets_and_match_composer_version(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('^8.4', $composer['require']['php']);
        $this->assertArrayNotHasKey('platform', $composer['config'] ?? []);

        foreach (['.github/workflows/ci.yml', '.github/workflows/security.yml'] as $workflowPath) {
            $raw = (string) file_get_contents(base_path($workflowPath));
            $this->assertStringNotContainsString('Setup PHP 8.3', $raw);

            $doc = Yaml::parseFile(base_path($workflowPath));
            foreach ($doc['jobs'] as $job) {
                foreach ($job['steps'] as $step) {
                    if (str_starts_with((string) ($step['uses'] ?? ''), 'shivammathur/setup-php@')) {
                        $this->assertSame('8.4', (string) $step['with']['php-version']);
                    }
                }
            }
        }

        $this->assertFileExists(public_path('build/manifest.json'));
    }

    public function test_f3_f4_f5_f6_f7_nginx_upstream_compression_immutable_cache_hsts_and_dead_php_fpm_removed(): void
    {
        $this->assertDirectoryDoesNotExist(base_path('docker/php-fpm'));
        $this->assertFileExists(base_path('docker/php/Dockerfile'));

        $nginx = (string) file_get_contents(base_path('docker/nginx/nginx.conf'));
        $snippet = (string) file_get_contents(base_path('docker/nginx/snippets/security-headers.conf'));

        // F3: canonical upstream matching docker-compose service `app:9000`
        $this->assertStringContainsString('server app:9000;', $nginx);
        $this->assertStringNotContainsString('app-node-1', $nginx);

        // F5: gzip compression enabled for text/css/js/json/svg/manifest
        $this->assertStringContainsString('gzip on;', $nginx);
        $this->assertStringContainsString('gzip_vary on;', $nginx);
        $this->assertStringContainsString('application/javascript', $nginx);

        // F6: 1-year immutable Cache-Control on hashed Vite `/build/` bundles
        $this->assertStringContainsString('location ^~ /build/', $nginx);
        $this->assertStringContainsString('expires 1y;', $nginx);
        $this->assertStringContainsString('public, max-age=31536000, immutable', $nginx);

        // F7: HSTS header configured at the nginx edge
        $this->assertStringContainsString('Strict-Transport-Security "max-age=31536000; includeSubDomains" always;', $snippet);
    }

    public function test_f8_reconciliation_group_concat_helper_uses_string_agg_for_pgsql_and_group_concat_elsewhere(): void
    {
        $traitSource = (string) file_get_contents(app_path('Services/Concerns/GuardsGroupConcat.php'));
        $this->assertStringContainsString("STRING_AGG(({\$expression})::text, ',')", $traitSource);

        foreach ([
            app_path('Services/Finance/FinancialReconciliationService.php'),
            app_path('Services/Finance/PayoutReconciliationService.php'),
        ] as $serviceFile) {
            $source = (string) file_get_contents($serviceFile);
            $this->assertStringNotContainsString('GROUP_CONCAT(', $source, basename($serviceFile).' should use groupConcatSql() helper');
        }
    }

    public function test_f9_f10_repository_bloat_is_untracked_but_kept_on_disk_and_gitignored(): void
    {
        $untrackList = (string) file_get_contents(base_path('UNTRACK-LIST.txt'));
        $paths = [
            'images/',
            'artifacts/',
            'design-previews/',
            'qa-fixes/',
            'files/',
            'evidence/',
            'repo/',
            'uploads/SECURITY_MASTER_PROMPT_01-05.md',
            '.profile',
            '.sudo_as_admin_successful',
        ];

        foreach ($paths as $junkPath) {
            $this->assertContains($junkPath, preg_split('/\\R/', trim($untrackList)) ?: []);
            $process = new Process(['git', 'ls-files', '--', $junkPath], base_path());
            $process->setTimeout(10);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame('', trim($process->getOutput()), "Repository bloat [{$junkPath}] must not remain tracked.");
        }

        $gitignore = (string) file_get_contents(base_path('.gitignore'));
        foreach (['/.sudo_as_admin_successful', '/.profile', '/evidence/', '/images/', '/artifacts/', '/design-previews/', '/qa-fixes/', '/files/', '/repo/'] as $rule) {
            $this->assertStringContainsString($rule, $gitignore);
        }
    }

    public function test_f11_environment_templates_pass_zero_drift_parity_script(): void
    {
        $process = new Process([PHP_BINARY, base_path('scripts/check-env-parity.php')]);
        $process->setTimeout(15);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $report = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($report['ok']);
        $this->assertSame([], $report['errors']);
        $this->assertSame(
            $report['templates']['.env.example'],
            $report['templates']['.env.ci'],
        );
        $this->assertSame(
            $report['templates']['.env.example'],
            $report['templates']['.env.production.example'],
        );
    }
    public function test_f12_immutable_images_and_kubernetes_safety_files_are_present(): void
    {
        foreach ([
            '.github/workflows/build-image.yml',
            '.github/workflows/deploy.yml',
            'k8s/deployment-scheduler.yaml',
            'k8s/deployment-settlement-worker.yaml',
            'k8s/service-ingress.yaml',
            'k8s/config.yaml',
            'k8s/jobs.yaml',
            'k8s/policies.yaml',
            'k8s/kustomization.yaml',
            'deployment/RUNBOOK-secrets-and-key-rotation.md',
            'deployment/RUNBOOK-payments-incidents.md',
            'deployment/RUNBOOK-backup-restore.md',
        ] as $requiredPath) {
            $this->assertFileExists(base_path($requiredPath), "Required deployment hardening file [{$requiredPath}] is missing.");
        }

        foreach (['k8s', 'helm/thai-lottery-ledger'] as $manifestDirectory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($manifestDirectory), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['yaml', 'yml'], true)) {
                    continue;
                }
                $this->assertStringNotContainsString(':latest', (string) file_get_contents($file->getPathname()), $file->getPathname().' must use immutable image tags.');
            }
        }

        $terraform = (string) file_get_contents(base_path('terraform/main.tf'));
        $this->assertMatchesRegularExpression('/^\\s*engine\\s*=\\s*"aurora-mysql"$/m', $terraform);
        $this->assertStringNotContainsString('aurora-postgresql', $terraform);
    }

}
