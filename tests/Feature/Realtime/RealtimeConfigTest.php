<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The config files that decide whether the lane is live, who may connect, and where mail
 * goes - evaluated as plain PHP under controlled environment values, so the production
 * branches are tested without booting a production application.
 */
final class RealtimeConfigTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv($key.'='.$value);
            }
        }

        $this->savedEnv = [];

        parent::tearDown();
    }

    /** @param array<string, string|null> $values */
    private function withEnv(array $values, string $configFile): array
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $this->savedEnv)) {
                $this->savedEnv[$key] = $_ENV[$key] ?? $_SERVER[$key] ?? (getenv($key) !== false ? getenv($key) : null);
            }

            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv($key.'='.$value);
            }
        }

        return require base_path('config/'.$configFile.'.php');
    }

    #[Test]
    public function realtime_is_dark_by_default_and_the_client_block_never_carries_the_secret(): void
    {
        $config = $this->withEnv([
            'REALTIME_ENABLED' => null,
            'REVERB_APP_KEY' => 'public-key',
            'REVERB_APP_SECRET' => 'very-secret',
        ], 'realtime');

        $this->assertFalse($config['enabled'], 'a fresh install must not pretend to have a socket');
        $this->assertSame('/broadcasting/auth', $config['client']['auth_endpoint']);
        $this->assertStringNotContainsString('very-secret', json_encode($config['client'], JSON_THROW_ON_ERROR));
        $this->assertGreaterThanOrEqual(250, $config['client']['reconnect_min_ms']);
        $this->assertGreaterThanOrEqual($config['client']['reconnect_min_ms'], $config['client']['reconnect_max_ms']);
        $this->assertSame(['completed', 'rejected', 'failed'], $config['notification_sms_states']);
        $this->assertGreaterThan(0, $config['dedupe_ttl_seconds']);

        $enabled = $this->withEnv(['REALTIME_ENABLED' => 'true'], 'realtime');
        $this->assertTrue($enabled['enabled']);
    }

    #[Test]
    public function reverb_origins_come_from_a_comma_list_and_the_wildcard_is_refused_outside_local(): void
    {
        $production = $this->withEnv([
            'APP_ENV' => 'production',
            'REVERB_ALLOWED_ORIGINS' => 'https://example.com, https://www.example.com ,',
        ], 'reverb');

        $this->assertSame(['https://example.com', 'https://www.example.com'], $production['apps']['apps'][0]['allowed_origins']);

        $wildcardInProduction = $this->withEnv([
            'APP_ENV' => 'production',
            'REVERB_ALLOWED_ORIGINS' => '*',
        ], 'reverb');

        $this->assertNotContains('*', $wildcardInProduction['apps']['apps'][0]['allowed_origins'], 'a wildcard origin in production admits any page');

        $emptyInProduction = $this->withEnv([
            'APP_ENV' => 'production',
            'REVERB_ALLOWED_ORIGINS' => null,
        ], 'reverb');

        $this->assertSame([], $emptyInProduction['apps']['apps'][0]['allowed_origins'], 'no origins configured means no browser is admitted, not every browser');

        $local = $this->withEnv([
            'APP_ENV' => 'local',
            'REVERB_ALLOWED_ORIGINS' => null,
        ], 'reverb');

        $this->assertSame(['*'], $local['apps']['apps'][0]['allowed_origins']);
    }

    #[Test]
    public function reverb_scaling_is_an_explicit_switch(): void
    {
        $off = $this->withEnv(['REVERB_SCALING_ENABLED' => null], 'reverb');
        $this->assertFalse($off['servers']['reverb']['scaling']['enabled']);

        $on = $this->withEnv(['REVERB_SCALING_ENABLED' => 'true'], 'reverb');
        $this->assertTrue($on['servers']['reverb']['scaling']['enabled']);
        $this->assertNotSame('', (string) $on['servers']['reverb']['scaling']['channel']);
    }

    #[Test]
    public function the_broadcast_connection_reads_reverb_from_the_environment(): void
    {
        $config = $this->withEnv([
            'BROADCAST_CONNECTION' => 'reverb',
            'REVERB_APP_KEY' => 'k',
            'REVERB_APP_SECRET' => 's',
            'REVERB_APP_ID' => 'a',
            'REVERB_HOST' => 'reverb.internal',
            'REVERB_PORT' => '8080',
            'REVERB_SCHEME' => 'http',
        ], 'broadcasting');

        $this->assertSame('reverb', $config['default']);
        $this->assertSame('reverb', $config['connections']['reverb']['driver']);
        $this->assertSame('reverb.internal', $config['connections']['reverb']['options']['host']);
        $this->assertSame(8080, (int) $config['connections']['reverb']['options']['port']);
        $this->assertFalse((bool) $config['connections']['reverb']['options']['useTLS']);
    }

    #[Test]
    public function mail_has_no_loopback_smtp_default_in_production_and_failover_is_opt_in(): void
    {
        $production = $this->withEnv([
            'APP_ENV' => 'production',
            'MAIL_MAILER' => null,
            'MAIL_HOST' => null,
            'MAIL_PORT' => null,
            'MAIL_SECONDARY_HOST' => null,
            'MAIL_SECONDARY_URL' => null,
        ], 'mail');

        $this->assertNotSame('127.0.0.1', $production['mailers']['smtp']['host'], 'production must not silently try a loopback relay');
        $this->assertNotSame(2525, (int) $production['mailers']['smtp']['port']);
        $this->assertNotSame('failover', $production['default'], 'failover is opt-in');
        $this->assertArrayHasKey('failover', $production['mailers']);
        $this->assertSame('failover', $production['mailers']['failover']['transport']);
        $this->assertNotContains('smtp_secondary', $production['mailers']['failover']['mailers'], 'no secondary host configured, so no secondary hop');

        $withSecondary = $this->withEnv([
            'APP_ENV' => 'production',
            'MAIL_MAILER' => 'failover',
            'MAIL_SECONDARY_HOST' => 'smtp2.example.com',
        ], 'mail');

        $this->assertSame('failover', $withSecondary['default']);
        $this->assertSame(['smtp', 'smtp_secondary', 'log'], $withSecondary['mailers']['failover']['mailers']);

        $local = $this->withEnv(['APP_ENV' => 'local', 'MAIL_MAILER' => null, 'MAIL_HOST' => null, 'MAIL_PORT' => null], 'mail');
        $this->assertSame('log', $local['default']);
    }
}
