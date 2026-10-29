<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\FetchGloOfficialDrawResultCommand;
use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

final class FetchGloCommandAliasTest extends TestCase
{
    public function test_fetch_result_uses_the_existing_command_and_keeps_its_signature(): void
    {
        $kernel = app(Kernel::class);
        $kernel->bootstrap();
        $commands = $kernel->all();

        $this->assertArrayHasKey('glo:fetch-official', $commands);
        $this->assertArrayHasKey('glo:fetch-result', $commands);
        $this->assertSame($commands['glo:fetch-official'], $commands['glo:fetch-result']);
        $this->assertInstanceOf(FetchGloOfficialDrawResultCommand::class, $commands['glo:fetch-result']);
        $this->assertSame('glo:fetch-result', $commands['glo:fetch-result']->getName());
        $definition = $commands['glo:fetch-result']->getDefinition();
        $this->assertTrue($definition->hasArgument('draw_code'));
        $this->assertFalse($definition->getArgument('draw_code')->isRequired());
        $this->assertTrue($definition->hasOption('dry-run'));
    }

    public function test_alias_keeps_the_production_fixture_refusal(): void
    {
        $this->app['env'] = 'production';
        config(['glo.official_source.mode' => 'fixture']);

        $this->artisan('glo:fetch-result', ['draw_code' => 'SYNTHETIC'])
            ->expectsOutputToContain('production requires')
            ->assertExitCode(1);
    }
}
