<?php

declare(strict_types=1);

namespace Tests\Feature\Config;

use PHPUnit\Framework\TestCase;

final class EnvTemplateSafetyTest extends TestCase
{
    public function test_production_template_keeps_real_money_off_and_contains_no_populated_secret_values(): void
    {
        $root = dirname(__DIR__, 3);
        $contents = file_get_contents($root.'/.env.production.example');
        self::assertIsString($contents);
        $values = [];
        foreach (preg_split('/\\R/', $contents) ?: [] as $line) {
            if (preg_match('/^\\s*([A-Z][A-Z0-9_]*)=(.*)$/', $line, $match) === 1) {
                $values[$match[1]] = trim(trim($match[2]), "\\\"'");
            }
        }

        self::assertSame('false', strtolower($values['APP_DEBUG'] ?? ''));
        self::assertSame('false', strtolower($values['REAL_MONEY_ENABLED'] ?? ''));
        self::assertNotSame('local', strtolower($values['AUTH_CAPTCHA_DRIVER'] ?? ''));
        foreach ($values as $key => $value) {
            $secretLooking = preg_match('/(?:^|_)(?:APP_KEY|SECRET|PASSWORD|PRIVATE_KEY|ACCESS_KEY_ID|API_KEY|WEBHOOK_SECRET|FINGERPRINT_KEY|HASH_KEY|SIGNATURE|TOKEN)$/i', $key) === 1
                || preg_match('/(?:SECRET|PASSWORD|PRIVATE_KEY|ACCESS_KEY|API_KEY|WEBHOOK_SECRET|FINGERPRINT_KEY|HASH_KEY|SIGNATURE)$/i', $key) === 1;
            if ($secretLooking) {
                self::assertSame('', $value, $key.' must be empty in the production example.');
            }
        }
    }
}
