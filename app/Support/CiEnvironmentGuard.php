<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Validate CI facts before any schema command. No DB, network or shell calls.
 * This is a disposable-runner allowlist, not a production readiness gate.
 */
final class CiEnvironmentGuard
{
    /** @return list<string> */
    public static function violations(array $facts, string $repository, bool $ciRunner, bool $beforeKey = false): array
    {
        $errors = [];
        if (! $ciRunner) {
            $errors[] = 'CI=true is required; this guard is not an operator migration command.';
        }
        if (($facts['APP_ENV'] ?? null) !== 'testing') {
            $errors[] = 'Only APP_ENV=testing is allowed.';
        }
        if (! self::isFalse($facts['APP_DEBUG'] ?? null)) {
            $errors[] = 'APP_DEBUG must be explicitly false.';
        }
        $key = $facts['APP_KEY'] ?? null;
        $decoded = is_string($key) && str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : false;
        if (! ($beforeKey && ($key === '' || $key === null)) && (! is_string($decoded) || strlen($decoded) !== 32)) {
            $errors[] = 'Generate an ephemeral 32-byte APP_KEY before guarded CI commands.';
        }
        $connection = $facts['DB_CONNECTION'] ?? null;
        $database = $facts['DB_DATABASE'] ?? null;
        if ($connection === 'sqlite') {
            $expected = rtrim($repository, '/').'/database/thai_lottery_test';
            if (! in_array($database, ['database/thai_lottery_test', $expected], true)) {
                $errors[] = 'SQLite must use the repository database/thai_lottery_test file.';
            }
            if (is_link($expected) || realpath(dirname($expected)) !== realpath($repository.'/database')) {
                $errors[] = 'The CI SQLite file must not be a symlink.';
            }
            $resolvedDirectory = realpath(dirname($expected));
            $resolvedRoot = realpath($repository);
            if (! is_string($resolvedDirectory) || ! is_string($resolvedRoot) || $resolvedDirectory !== $resolvedRoot.'/database') {
                $errors[] = 'The CI SQLite directory must be inside the repository.';
            }
        } elseif ($connection === 'mysql') {
            if ($database !== 'thai_lottery_test'
                || ($facts['DB_HOST'] ?? null) !== '127.0.0.1'
                || (string) ($facts['DB_PORT'] ?? '') !== '3306'
                || ($facts['DB_USERNAME'] ?? null) !== 'thailotto_ci') {
                $errors[] = 'MySQL must use the loopback disposable CI service and restricted thai_lottery_test account.';
            }
        } else {
            $errors[] = 'Only allow-listed SQLite or disposable MySQL CI databases may be migrated.';
        }
        foreach (['NATIONAL_LOTTERY_FIXTURE_ENABLED', 'WEEKLY_LOTTERY_FIXTURE_ENABLED', 'BINGO_LOTTERY_FIXTURE_ENABLED', 'PCSO_LOTTERY_FIXTURE_ENABLED',
            'GLO_FALL_THROUGH_TO_FIXTURE', 'LOTTERY_AUTO_PAYOUT', 'STRIPE_ENABLED', 'BKASH_ENABLED', 'NAGAD_ENABLED', 'CRYPTO_ENABLED', 'PROMPTPAY_ENABLED', 'BANK_TRANSFER_ENABLED'] as $flag) {
            if (! self::isFalse($facts[$flag] ?? null)) {
                $errors[] = $flag.' must be explicitly false in the CI provisioning environment.';
            }
        }
        if (($facts['PRIZE_PAYOUT_SAFETY_MODE'] ?? null) !== 'DISABLED') {
            $errors[] = 'CI provisioning must keep prize payout disabled.';
        }
        if (($facts['GLO_OFFICIAL_SOURCE_MODE'] ?? null) !== 'official') {
            $errors[] = 'CI provisioning must not select a synthetic GLO source.';
        }
        $priority = $facts['GLO_SOURCE_PRIORITY'] ?? null;
        if (! is_string($priority) || ! in_array(trim($priority), ['', 'official'], true)) {
            $errors[] = 'CI provisioning allows only empty/official GLO source priority.';
        }

        return $errors;
    }

    private static function isFalse(mixed $value): bool
    {
        return $value === false || in_array($value, ['false', '(false)', '0'], true);
    }
}
