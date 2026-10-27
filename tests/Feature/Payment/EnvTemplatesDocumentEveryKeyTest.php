<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Tests\TestCase;

/**
 * The operator surface must describe the configuration surface.
 *
 * WHY THIS SUITE EXISTS
 * The Laravel templates shipped with this repository were written before most
 * of the configuration existed: 562 env keys that config/*.php actually reads
 * were simply absent, so an operator copying `.env.example` had no way to know
 * which knobs exist, and no way to tell a deliberately-empty credential from a
 * key nobody had written down. apply-batch-05.sh appends the missing keys with
 * their own config defaults, so copying a template cannot change behaviour.
 *
 * These assertions are about the contract that makes that safe:
 *
 *   1. every key config reads is documented in all three templates,
 *   2. no key is defined twice as an ACTIVE line (the second would win and
 *      silently override the first),
 *   3. the example template ships no secret value — an empty credential is a
 *      decision, a filled one is a leaked credential waiting for a commit,
 *   4. every new lane key this batch introduced is present and switched OFF,
 *      so a fresh deployment cannot enable a payment rail by accident.
 */
final class EnvTemplatesDocumentEveryKeyTest extends TestCase
{
    /**
     * Suffixes that mark a credential. Deliberately an explicit list: a broad
     * `_KEY$` rule would flag PERMISSION_CACHE_KEY, DB_FOREIGN_KEYS,
     * IDEMPOTENCY_KEY_HEADER and SANCTUM_TOKEN_EXPIRATION, none of which are
     * secrets.
     *
     * @var list<string>
     */
    private const SECRET_SUFFIXES = [
        '_SECRET',
        '_TOKEN',
        '_PASSWORD',
        '_PASSPHRASE',
        '_API_KEY',
        '_PRIVATE_KEY',
        '_ENCRYPTION_KEY',
        '_SIGNING_KEY',
    ];

    /**
     * Values that mean "no credential here" as far as a template is concerned.
     *
     * @var list<string>
     */
    private const EMPTY_VALUES = ['', 'null', '""', "''"];

    /**
     * @var list<string>
     */
    private const TEMPLATES = ['.env.example', '.env.production.example', '.env.ci'];

    /**
     * @var list<string>
     */
    private const LANE_KEYS = [
        'THAI_PAYOUT_ENABLED',
        'TRUEMONEY_ENABLED',
        'SLIP_VERIFICATION_ENABLED',
        'PROMPTPAY_ENABLED',
        'BANK_TRANSFER_ENABLED',
        'REALTIME_ENABLED',
        'LEGAL_PDF_ENABLED',
        'REAL_MONEY_ENABLED',
    ];

    /**
     * Keys this batch added to config/payment.php.
     *
     * @var list<string>
     */
    private const BATCH_KEYS = [
        'PAYMENT_WITHDRAWAL_ROUTING_PREFER_AUTOMATED',
        'PAYMENT_WITHDRAWAL_ROUTING_MIN_AMOUNT',
        'PAYMENT_WITHDRAWAL_ROUTING_MAX_AMOUNT',
        'THAI_PAYOUT_WEBHOOK_SECRET',
        'THAI_PAYOUT_SIGNATURE_HEADER',
    ];

    public function test_every_key_config_reads_is_documented_in_all_three_templates(): void
    {
        $keys = $this->configEnvKeys();

        $this->assertNotSame([], $keys, 'The configuration surface must reference at least one env key.');

        foreach (self::TEMPLATES as $template) {
            $documented = $this->templateKeys(base_path($template));

            foreach ($keys as $key => $_documented) {
                $this->assertArrayHasKey(
                    $key,
                    $documented,
                    sprintf('%s is read by config/*.php but is missing from %s.', $key, $template),
                );
            }
        }
    }

    public function test_no_template_defines_the_same_key_twice_as_an_active_line(): void
    {
        foreach (self::TEMPLATES as $template) {
            $active = $this->activeTemplateKeys(base_path($template));

            $counts = array_count_values($active);

            foreach ($counts as $key => $count) {
                $this->assertSame(
                    1,
                    $count,
                    sprintf('%s is defined %d times as an active line in %s; the last one silently wins.', $key, $count, $template),
                );
            }
        }
    }

    public function test_the_example_template_ships_no_secret_value(): void
    {
        $active = $this->activeTemplateMap(base_path('.env.example'));

        foreach ($active as $key => $value) {
            if (! $this->isSecretName($key)) {
                continue;
            }

            $this->assertContains(
                $value,
                self::EMPTY_VALUES,
                sprintf('%s must ship empty in .env.example; a filled example credential is a credential waiting to be committed.', $key),
            );
        }
    }

    public function test_every_lane_key_ships_switched_off(): void
    {
        foreach (self::TEMPLATES as $template) {
            $active = $this->activeTemplateMap(base_path($template));

            foreach (self::LANE_KEYS as $key) {
                $this->assertArrayHasKey($key, $active, sprintf('%s is missing from %s.', $key, $template));
                $this->assertSame(
                    'false',
                    $active[$key],
                    sprintf('%s must default to off in %s; a template may never enable a money lane.', $key, $template),
                );
            }
        }
    }

    public function test_every_key_this_batch_added_is_documented(): void
    {
        foreach (self::TEMPLATES as $template) {
            $documented = $this->templateKeys(base_path($template));

            foreach (self::BATCH_KEYS as $key) {
                $this->assertArrayHasKey($key, $documented, sprintf('%s is missing from %s.', $key, $template));
            }
        }

        // The one credential among them must be empty, and the poll preference
        // is a routing choice rather than a lane switch, so it stays ON.
        $active = $this->activeTemplateMap(base_path('.env.example'));
        $this->assertSame('', $active['THAI_PAYOUT_WEBHOOK_SECRET']);
        $this->assertSame('true', $active['PAYMENT_WITHDRAWAL_ROUTING_PREFER_AUTOMATED']);
    }

    public function test_a_structured_default_is_documented_as_a_hint_not_a_broken_value(): void
    {
        // A template cannot express an array, so the generator writes a comment
        // that names the config file. Whatever else changes, that hint must
        // survive: an operator needs to know the knob exists.
        foreach (self::TEMPLATES as $template) {
            $contents = (string) file_get_contents(base_path($template));

            $this->assertStringContainsString('# structured default - read config/', $contents);
        }
    }

    /**
     * @return array<string, true> every key referenced by an env() call in config/*.php
     */
    private function configEnvKeys(): array
    {
        $keys = [];

        foreach (glob(base_path('config/*.php')) ?: [] as $file) {
            $contents = (string) file_get_contents($file);

            if (preg_match_all("/env\\(\\s*['\\\"]([A-Z0-9_]+)['\\\"]/", $contents, $matches) > 0) {
                foreach ($matches[1] as $key) {
                    $keys[$key] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * @return array<string, string> key => raw value, for documented (active or commented) keys
     */
    private function templateKeys(string $path): array
    {
        $keys = [];

        foreach ($this->templateLines($path) as [$key, $value, $_active]) {
            $keys[$key] = $value;
        }

        return $keys;
    }

    /**
     * @return list<string> the documented key, once per ACTIVE line
     */
    private function activeTemplateKeys(string $path): array
    {
        $keys = [];

        foreach ($this->templateLines($path) as [$key, $value, $active]) {
            if ($active) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @return array<string, string> key => value for ACTIVE lines only
     */
    private function activeTemplateMap(string $path): array
    {
        $map = [];

        foreach ($this->templateLines($path) as [$key, $value, $active]) {
            if ($active) {
                $map[$key] = $value;
            }
        }

        return $map;
    }

    /**
     * @return list<array{0: string, 1: string, 2: bool}> [key, value, isActive]
     */
    private function templateLines(string $path): array
    {
        $lines = [];

        foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
            if (preg_match('/^\s*(#?)\s*([A-Z0-9_]+)\s*=(.*)$/', $line, $matches) !== 1) {
                continue;
            }

            $lines[] = [$matches[2], trim($matches[3]), $matches[1] !== '#'];
        }

        return $lines;
    }

    private function isSecretName(string $key): bool
    {
        foreach (self::SECRET_SUFFIXES as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
