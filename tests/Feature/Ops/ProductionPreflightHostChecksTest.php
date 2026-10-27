<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Support\Ops\ProductionPreflight;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ProductionPreflightHostChecksTest extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'app.field_encryption_key' => 'distinct-production-field-key',
            'app.url' => 'https://lottery.test',
            'session.domain' => 'lottery.test',
            'mail.mailers.smtp.host' => 'smtp.test',
            'broadcasting.connections.reverb.options.host' => 'ws.test',
            'network.trusted_proxies_setting' => '10.0.0.0/8,192.168.0.0/16',
            'network.allow_wildcard_trusted_proxies' => false,
            'broadcasting.default' => 'log',
            'chat.enabled' => true,
            'chat.transport' => 'poll',
            'mail.default' => 'log',
            'payment.gateways.promptpay.enabled' => false,
            'payment.gateways.promptpay.merchant_id' => null,
        ]);
    }

    public function test_field_encryption_requires_a_nonempty_key_distinct_from_app_key(): void
    {
        config(['app.field_encryption_key' => null]);
        $this->assertSame('FAIL', $this->check('field_encryption.key')['status']);

        config(['app.field_encryption_key' => config('app.key')]);
        $this->assertSame('FAIL', $this->check('field_encryption.key')['status']);

        config(['app.field_encryption_key' => str_repeat('a', 32)]);
        $this->assertSame('FAIL', $this->check('field_encryption.key')['status'], 'Equivalent raw/base64 key material must not pass as distinct.');

        config(['app.field_encryption_key' => 'base64:not-valid-base64!']);
        $this->assertSame('FAIL', $this->check('field_encryption.key')['status']);

        config(['app.field_encryption_key' => 'another-distinct-key']);
        $this->assertSame('PASS', $this->check('field_encryption.key')['status']);
    }

    /** @return array<string, array{string, string}> */
    public static function placeholderHosts(): array
    {
        return [
            'app url' => ['app.url', 'https://localhost'],
            'localhost subdomain' => ['app.url', 'https://payments.localhost'],
            'session domain' => ['session.domain', '.example'],
            'mail host' => ['mail.mailers.smtp.host', 'mail.example.com'],
            'reverb host' => ['broadcasting.connections.reverb.options.host', '127.0.0.1'],
        ];
    }

    #[DataProvider('placeholderHosts')]
    public function test_production_placeholder_hosts_fail_and_real_hosts_pass(string $key, string $placeholder): void
    {
        config([$key => $placeholder]);
        $this->assertSame('FAIL', $this->check('host.placeholder')['status']);

        config([$key => str_starts_with($key, 'app.') ? 'https://lottery.test' : 'lottery.test']);
        $this->assertSame('PASS', $this->check('host.placeholder')['status']);
    }

    public function test_trusted_proxies_require_explicit_cidrs_or_a_wildcard_override(): void
    {
        config(['network.trusted_proxies_setting' => '']);
        $this->assertSame('FAIL', $this->check('proxy.trusted')['status']);

        config(['network.trusted_proxies_setting' => ' , ']);
        $this->assertSame('FAIL', $this->check('proxy.trusted')['status']);

        config(['network.trusted_proxies_setting' => '*']);
        $this->assertSame('FAIL', $this->check('proxy.trusted')['status']);

        config(['network.trusted_proxies_setting' => '10.0.0.0/8, *']);
        $this->assertSame('FAIL', $this->check('proxy.trusted')['status']);

        config(['network.allow_wildcard_trusted_proxies' => true]);
        $this->assertSame('PASS', $this->check('proxy.trusted')['status']);

        config(['network.trusted_proxies_setting' => '203.0.113.10/32']);
        $this->assertSame('PASS', $this->check('proxy.trusted')['status']);
    }

    public function test_log_broadcaster_warns_only_when_realtime_is_enabled(): void
    {
        config(['chat.transport' => 'broadcast']);
        $this->assertSame('WARN', $this->check('broadcast.driver')['status']);

        config(['chat.enabled' => false]);
        $this->assertSame('PASS', $this->check('broadcast.driver')['status']);

        config(['chat.enabled' => true, 'chat.transport' => 'poll']);
        $this->assertSame('PASS', $this->check('broadcast.driver')['status']);
    }

    public function test_smtp_requires_a_host(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '']);
        $this->assertSame('FAIL', $this->check('mail.host')['status']);

        config(['mail.mailers.smtp.host' => 'smtp.test']);
        $this->assertSame('PASS', $this->check('mail.host')['status']);
    }

    public function test_enabled_promptpay_requires_a_valid_merchant_identifier(): void
    {
        config(['payment.gateways.promptpay.enabled' => true, 'payment.gateways.promptpay.merchant_id' => 'invalid']);
        $this->assertSame('FAIL', $this->check('promptpay.merchant')['status']);

        config(['payment.gateways.promptpay.merchant_id' => '+66 81-234-5678']);
        $this->assertSame('PASS', $this->check('promptpay.merchant')['status']);

        config(['payment.gateways.promptpay.enabled' => false, 'payment.gateways.promptpay.merchant_id' => 'invalid']);
        $this->assertSame('PASS', $this->check('promptpay.merchant')['status']);
    }

    /** @return array{id: string, status: string, detail: string, severity: string} */
    private function check(string $id): array
    {
        $report = (new ProductionPreflight(production: true, withConnectivity: false))->run();
        $check = collect($report['checks'])->firstWhere('id', $id);
        $this->assertIsArray($check, 'Missing preflight check '.$id);

        return $check;
    }
}
