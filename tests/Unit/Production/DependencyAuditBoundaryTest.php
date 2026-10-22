<?php

declare(strict_types=1);

namespace Tests\Unit\Production;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class DependencyAuditBoundaryTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function test_locked_yaml_parser_includes_the_recursion_security_patch(): void
    {
        $lock = json_decode(file_get_contents($this->root().'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $packages = array_merge($lock['packages'], $lock['packages-dev']);
        $yaml = array_values(array_filter($packages, fn (array $package): bool => $package['name'] === 'symfony/yaml'));
        $this->assertCount(1, $yaml);
        $version = ltrim($yaml[0]['version'], 'v');
        $this->assertStringStartsWith('7.4.', $version);
        $this->assertTrue(version_compare($version, '7.4.18', '>='));
    }

    public function test_deep_inline_input_is_rejected_by_the_real_parser_without_unbounded_recursion(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Maximum nesting depth');
        Yaml::parse('payload: '.str_repeat('[', 2000).'0'.str_repeat(']', 2000));
    }

    #[DataProvider('currencies')]
    public function test_normal_configuration_retains_exact_string_values(string $currency): void
    {
        $value = Yaml::parse("finance: { currency: $currency, amount: '100.00', reference: '007', enabled: false }");
        $this->assertSame(['currency' => $currency, 'amount' => '100.00', 'reference' => '007', 'enabled' => false], $value['finance']);
    }

    public static function currencies(): array
    {
        return [['THB'], ['BDT'], ['USD']];
    }

    public function test_all_workflows_parse_and_each_locked_install_has_a_dependency_gate(): void
    {
        $nodeSteps = 0;
        foreach (['ci.yml', 'security.yml', 'financial-concurrency.yml'] as $file) {
            $workflow = Yaml::parseFile($this->root().'/.github/workflows/'.$file);
            $this->assertIsArray($workflow['jobs']);
            foreach ($workflow['jobs'] as $job) {
                foreach ($job['steps'] ?? [] as $step) {
                    if (str_starts_with($step['uses'] ?? '', 'shivammathur/setup-php@')) {
                        $this->assertContains('gd', array_map('trim', explode(',', $step['with']['extensions'] ?? '')));
                        $this->assertContains('pdo_mysql', array_map('trim', explode(',', $step['with']['extensions'] ?? '')));
                    }
                    if (str_starts_with($step['uses'] ?? '', 'actions/setup-node@')) {
                        $nodeSteps++;
                        $this->assertSame('.nvmrc', $step['with']['node-version-file']);
                    }
                    if (str_contains($step['run'] ?? '', 'npm ci')) {
                        $this->assertStringContainsString('npm run security:dependencies', $step['run']);
                    }
                    if (str_contains($step['run'] ?? '', 'composer audit')) {
                        $this->assertStringContainsString('--locked --abandoned=fail', $step['run']);
                    }
                }
            }
        }
        $this->assertSame(5, $nodeSteps);
    }
}
