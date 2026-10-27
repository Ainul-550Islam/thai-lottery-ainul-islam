<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class ManifestSanityTest extends TestCase
{
    use RefreshDatabase;

    public function test_kubernetes_manifests_are_valid_and_workloads_are_hardened(): void
    {
        $files = glob(base_path('k8s/*.yaml'));
        $this->assertNotFalse($files);
        $this->assertNotEmpty($files);

        $documents = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $this->assertStringNotContainsString(':latest', $source, basename($file).' must not use a mutable image tag.');
            foreach (preg_split('/^---\s*$/m', $source) ?: [] as $chunk) {
                if (trim($chunk) === '') {
                    continue;
                }
                $document = Yaml::parse($chunk);
                if (is_array($document)) {
                    $documents[] = $document;
                }
            }
            $this->assertStringNotContainsString('hostPath:', $source, basename($file).' must not mount hostPath.');
        }

        $workloadCount = 0;
        foreach ($documents as $document) {
            if (! in_array($document['kind'] ?? null, ['Deployment', 'Job'], true)) {
                continue;
            }
            $podSpec = $document['spec']['template']['spec'] ?? [];
            $containers = $podSpec['containers'] ?? [];
            $initContainers = $podSpec['initContainers'] ?? [];
            $this->assertNotEmpty($containers, (string) ($document['metadata']['name'] ?? 'workload').' must define containers.');
            foreach ($containers as $container) {
                $workloadCount++;
                $this->assertArrayHasKey('resources', $container, (string) ($container['name'] ?? 'container').' must declare resources.');
                $this->assertArrayHasKey('securityContext', $container, (string) ($container['name'] ?? 'container').' must declare a container security context.');
                $this->assertTrue(
                    isset($container['readinessProbe']) || ($document['kind'] ?? null) === 'Job',
                    (string) ($container['name'] ?? 'container').' must declare readiness or be a one-shot Job.'
                );
                if (($document['kind'] ?? null) === 'Deployment') {
                    $this->assertArrayHasKey('livenessProbe', $container);
                    $this->assertArrayHasKey('startupProbe', $container);
                }
            }
            foreach ($initContainers as $container) {
                $workloadCount++;
                $this->assertArrayHasKey('resources', $container, (string) ($container['name'] ?? 'init container').' must declare resources.');
                $this->assertArrayHasKey('securityContext', $container, (string) ($container['name'] ?? 'init container').' must declare a container security context.');
            }
            $podContext = $podSpec['securityContext'] ?? [];
            $this->assertTrue((bool) ($podContext['runAsNonRoot'] ?? false));
            $this->assertSame('RuntimeDefault', $podContext['seccompProfile']['type'] ?? null);
        }

        $this->assertGreaterThanOrEqual(5, $workloadCount);
        $kinds = array_column($documents, 'kind');
        $this->assertContains('Ingress', $kinds);
        $this->assertContains('NetworkPolicy', $kinds);
        $this->assertContains('PodDisruptionBudget', $kinds);
        $this->assertContains('Job', $kinds);
        $this->assertContains('ConfigMap', $kinds);
        $this->assertTrue(in_array('Secret', $kinds, true) || in_array('ExternalSecret', $kinds, true));
        $this->assertContains('PersistentVolumeClaim', $kinds);
        $this->assertContains('ServiceAccount', $kinds);
        $this->assertFileExists(base_path('k8s/deployment-scheduler.yaml'));
        $this->assertFileExists(base_path('k8s/deployment-settlement-worker.yaml'));

        $workerDeployments = array_values(array_filter(
            $documents,
            static fn (array $document): bool => ($document['kind'] ?? null) === 'Deployment'
                && ($document['metadata']['name'] ?? null) === 'thai-lottery-settlement-worker',
        ));
        $this->assertCount(1, $workerDeployments);
        $worker = $workerDeployments[0]['spec']['template']['spec']['containers'][0] ?? [];
        $this->assertSame('/health', $worker['readinessProbe']['httpGet']['path'] ?? null);
        $this->assertArrayHasKey('tcpSocket', $worker['startupProbe'] ?? []);
        $this->assertArrayHasKey('tcpSocket', $worker['livenessProbe'] ?? []);
    }

    public function test_helm_chart_renders_valid_hardened_workloads_when_helm_is_installed(): void
    {
        $process = new Process([
            'helm', 'template', 'sanity', base_path('helm/thai-lottery-ledger'),
            '--set', 'ingress.host=unit-test.invalid',
            '--set', 'externalSecretStoreName=fintech-production',
            '--set', 'storage.className=unit-test-rwx',
            '--set', 'storage.size=20Gi',
            '--set', 'networkPolicy.databaseCidrs[0]=10.100.0.0/24',
            '--set', 'networkPolicy.redisCidrs[0]=10.101.0.0/24',
            '--set', 'networkPolicy.providerCidrs[0]=198.51.100.0/24',
            '--set', 'image.tag=sha-0123456789012345678901234567890123456789',
        ]);
        $process->setTimeout(30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringNotContainsString(':latest', $process->getOutput());

        $helmKinds = [];
        $helmWorkloads = [];
        $helmSettlementWorker = null;
        foreach (preg_split('/^---\s*$/m', $process->getOutput()) ?: [] as $chunk) {
            if (trim($chunk) === '') {
                continue;
            }
            $document = Yaml::parse($chunk);
            if (! is_array($document)) {
                continue;
            }
            $helmKinds[] = $document['kind'] ?? '';
            if (! in_array($document['kind'] ?? null, ['Deployment', 'Job'], true)) {
                continue;
            }
            $helmWorkloads[] = (string) ($document['metadata']['name'] ?? '');
            $podSpec = $document['spec']['template']['spec'] ?? [];
            $this->assertTrue((bool) ($podSpec['securityContext']['runAsNonRoot'] ?? false));
            $this->assertSame('RuntimeDefault', $podSpec['securityContext']['seccompProfile']['type'] ?? null);
            foreach ($podSpec['containers'] ?? [] as $container) {
                $this->assertArrayHasKey('resources', $container);
                $this->assertArrayHasKey('securityContext', $container);
                if (($document['kind'] ?? null) === 'Deployment') {
                    $this->assertArrayHasKey('readinessProbe', $container);
                    $this->assertArrayHasKey('livenessProbe', $container);
                    $this->assertArrayHasKey('startupProbe', $container);
                }
                if (($container['name'] ?? null) === 'settlement-worker') {
                    $helmSettlementWorker = $container;
                }
            }
            foreach ($podSpec['initContainers'] ?? [] as $container) {
                $this->assertArrayHasKey('resources', $container);
                $this->assertArrayHasKey('securityContext', $container);
            }
        }

        foreach (['Ingress', 'NetworkPolicy', 'PodDisruptionBudget', 'PersistentVolumeClaim', 'Job', 'ConfigMap', 'ExternalSecret', 'Deployment', 'Service'] as $kind) {
            $this->assertContains($kind, $helmKinds, 'Helm chart must render '.$kind.'.');
        }
        $this->assertContains('sanity-scheduler', $helmWorkloads);
        $this->assertContains('sanity-settlement-worker', $helmWorkloads);
        $this->assertIsArray($helmSettlementWorker);
        $this->assertSame('/health', $helmSettlementWorker['readinessProbe']['httpGet']['path'] ?? null);
        $this->assertArrayHasKey('tcpSocket', $helmSettlementWorker['startupProbe'] ?? []);
        $this->assertArrayHasKey('tcpSocket', $helmSettlementWorker['livenessProbe'] ?? []);
    }
}
