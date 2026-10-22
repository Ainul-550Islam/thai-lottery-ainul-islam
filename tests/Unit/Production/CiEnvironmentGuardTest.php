<?php

declare(strict_types=1);

namespace Tests\Unit\Production;

use App\Support\CiEnvironmentGuard;
use Dotenv\Dotenv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** No Laravel bootstrap, database calls or schema commands. */
final class CiEnvironmentGuardTest extends TestCase
{
    private function facts(): array
    {
        $values = Dotenv::parse(file_get_contents(dirname(__DIR__, 3).'/.env.ci'));
        $values['APP_KEY'] = 'base64:'.base64_encode(str_repeat('t', 32));

        return $values;
    }

    public function test_secret_free_template_requires_a_generated_key_before_schema_commands(): void
    {
        $facts = $this->facts();
        $root = dirname(__DIR__, 3);
        self::assertSame([], CiEnvironmentGuard::violations($facts, $root, true));
        $facts['APP_KEY'] = '';
        self::assertNotEmpty(CiEnvironmentGuard::violations($facts, $root, true));
        self::assertSame([], CiEnvironmentGuard::violations($facts, $root, true, true));
    }

    public function test_only_the_named_disposable_loopback_mysql_account_is_accepted(): void
    {
        $facts = $this->facts();
        $facts = array_replace($facts, ['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'thai_lottery_test', 'DB_USERNAME' => 'thailotto_ci']);
        self::assertSame([], CiEnvironmentGuard::violations($facts, dirname(__DIR__, 3), true));
        $facts['DB_USERNAME'] = 'root';
        self::assertNotEmpty(CiEnvironmentGuard::violations($facts, dirname(__DIR__, 3), true));
    }

    public static function forbiddenFacts(): array
    {
        return [
            'production env' => ['APP_ENV', 'production'],
            'development env' => ['APP_ENV', 'local'],
            'debug' => ['APP_DEBUG', 'true'],
            'wrong connection' => ['DB_CONNECTION', 'pgsql'],
            'wrong database' => ['DB_DATABASE', 'customer_database'],
            'lookalike basename outside repo' => ['DB_DATABASE', '/customer/database/thai_lottery_test'],
            'in memory not runner store' => ['DB_DATABASE', ':memory:'],
            'fixture source' => ['GLO_OFFICIAL_SOURCE_MODE', 'fixture'],
            'fixture priority' => ['GLO_SOURCE_PRIORITY', 'official,fixture'],
            'fixture lane' => ['WEEKLY_LOTTERY_FIXTURE_ENABLED', 'true'],
            'missing explicit flag' => ['BKASH_ENABLED', null],
            'enabled merchant' => ['STRIPE_ENABLED', 'true'],
            'live payout' => ['PRIZE_PAYOUT_SAFETY_MODE', 'LIVE'],
            'automatic payout' => ['LOTTERY_AUTO_PAYOUT', 'true'],
            'invalid key' => ['APP_KEY', 'do-not-leak-this-value'],
        ];
    }

    #[DataProvider('forbiddenFacts')]
    public function test_unsafe_provisioning_is_refused_without_echoing_values(string $name, mixed $value): void
    {
        $facts = $this->facts();
        $facts[$name] = $value;
        $errors = CiEnvironmentGuard::violations($facts, dirname(__DIR__, 3), true);
        self::assertNotEmpty($errors);
        self::assertStringNotContainsString('do-not-leak-this-value', implode('\n', $errors));
        self::assertStringNotContainsString('customer_database', implode('\n', $errors));
    }

    public function test_ci_marker_is_required_even_with_safe_facts(): void
    {
        self::assertNotEmpty(CiEnvironmentGuard::violations($this->facts(), dirname(__DIR__, 3), false));
    }

    public function test_a_symlinked_database_file_cannot_retarget_the_allowlist(): void
    {
        $directory = sys_get_temp_dir().'/lottery-ci-guard-'.bin2hex(random_bytes(8));
        mkdir($directory.'/database', 0700, true);
        file_put_contents($directory.'/outside', 'not a database');
        symlink($directory.'/outside', $directory.'/database/thai_lottery_test');
        try {
            self::assertNotEmpty(CiEnvironmentGuard::violations($this->facts(), $directory, true));
            self::assertSame('not a database', file_get_contents($directory.'/outside'));
        } finally {
            unlink($directory.'/database/thai_lottery_test');
            unlink($directory.'/outside');
            rmdir($directory.'/database');
            rmdir($directory);
        }
    }
}
