<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

final class WorkflowSanityTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_github_workflow_declares_permissions_and_uses_pinned_actions(): void
    {
        $files = glob(base_path('.github/workflows/*.yml'));
        $this->assertNotFalse($files);
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $workflow = Yaml::parseFile($file);
            $this->assertIsArray($workflow, basename($file).' must parse as YAML.');
            $this->assertArrayHasKey('permissions', $workflow, basename($file).' must declare explicit token permissions.');

            $source = (string) file_get_contents($file);
            $this->assertStringNotContainsString('pull_request_target', $source, basename($file).' must not use the privileged pull_request_target trigger.');

            foreach ($workflow['jobs'] ?? [] as $job) {
                foreach ($job['steps'] ?? [] as $step) {
                    $uses = $step['uses'] ?? null;
                    if (! is_string($uses)) {
                        continue;
                    }

                    $this->assertMatchesRegularExpression(
                        '~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)+@(v[0-9]+(?:\.[0-9]+(?:\.[0-9]+)?)?|[0-9a-f]{40})$~',
                        $uses,
                        basename($file).' has an action that is not pinned to a major release tag or full SHA: '.$uses
                    );
                }
            }
        }
    }
}
