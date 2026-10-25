<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TerraformDatabaseEngineMatchesAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_database_contract_and_aurora_engine_are_mysql_compatible(): void
    {
        $environment = (string) file_get_contents(base_path('.env.production.example'));
        $this->assertMatchesRegularExpression('/^DB_CONNECTION=mysql\s*$/m', $environment);

        $terraform = (string) file_get_contents(base_path('terraform/main.tf'));
        $outputs = (string) file_get_contents(base_path('terraform/outputs.tf'));
        $this->assertMatchesRegularExpression('/^\\s*engine\\s*=\\s*"aurora-mysql"\\s*$/m', $terraform);
        $this->assertStringContainsString('aurora-mysql8.0', $terraform);
        $this->assertStringContainsString('from_port       = 3306', $terraform);
        $this->assertStringContainsString('to_port         = 3306', $terraform);
        $this->assertStringNotContainsString('postgres', strtolower($terraform.$outputs));
        $this->assertStringNotContainsString('5432', $terraform);
    }
}
