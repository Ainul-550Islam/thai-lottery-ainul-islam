<?php

declare(strict_types=1);

// Read-only validation BEFORE migration/seed commands. Never boot Laravel,
// query a DB, read customer data, generate a key or change schema here.
$root = dirname(__DIR__);
$beforeKey = ($argv[1] ?? null) === '--before-key';
if (count($argv) > ($beforeKey ? 2 : 1)) {
    fwrite(STDERR, "CI runtime guard refused: unsupported arguments.\n");
    exit(1);
}
if (getenv('CI') !== 'true') {
    fwrite(STDERR, "CI runtime guard refused: CI=true is required.\n");
    exit(1);
}
require $root.'/vendor/autoload.php';

use App\Support\CiEnvironmentGuard;
use Dotenv\Dotenv;

try {
    $template = Dotenv::createArrayBacked($root, '.env')->safeLoad();
    $facts = $template;
    // Actual runner environment wins over the committed dotenv defaults.
    foreach (array_keys($template) as $name) {
        $value = getenv($name);
        if ($value !== false) {
            $facts[$name] = $value;
        }
    }
    foreach (['APP_ENV', 'APP_DEBUG', 'APP_KEY', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME'] as $name) {
        $value = getenv($name);
        if ($value !== false) {
            $facts[$name] = $value;
        }
    }
    $errors = CiEnvironmentGuard::violations($facts, $root, true, $beforeKey);
    $cache = $root.'/bootstrap/cache/config.php';
    if ($beforeKey && is_file($cache)) {
        $errors[] = 'Before key generation the checkout must have no cached configuration.';
    } elseif (is_file($cache)) {
        if (is_link($cache)) {
            $errors[] = 'Cached config must not be a symlink.';
        } elseif ($errors === []) {
            // Standard Laravel-generated PHP config, evaluated without an
            // application/kernel/bootstrap: no service provider can access a
            // stale DB before its cached target is validated here.
            $config = require $cache;
            if (! is_array($config)) {
                $errors[] = 'Cached config is not a configuration array.';
            } else {
                $cached = $facts;
                $cached['APP_ENV'] = $config['app']['env'] ?? null;
                $cached['APP_DEBUG'] = $config['app']['debug'] ?? null;
                $cached['APP_KEY'] = $config['app']['key'] ?? null;
                $connection = $config['database']['default'] ?? null;
                $db = is_string($connection) ? ($config['database']['connections'][$connection] ?? []) : [];
                $cached['DB_CONNECTION'] = $connection;
                foreach (['database' => 'DB_DATABASE', 'host' => 'DB_HOST', 'port' => 'DB_PORT', 'username' => 'DB_USERNAME'] as $name => $env) {
                    $cached[$env] = $db[$name] ?? null;
                }
                foreach (['national' => 'NATIONAL', 'weekly' => 'WEEKLY', 'bingo' => 'BINGO', 'pcso' => 'PCSO'] as $lane => $prefix) {
                    $cached[$prefix.'_LOTTERY_FIXTURE_ENABLED'] = $config[$lane.'_lottery']['sources']['fixture']['enabled'] ?? null;
                }
                $cached['GLO_OFFICIAL_SOURCE_MODE'] = $config['glo']['official_source']['mode'] ?? null;
                $priority = $config['glo']['sources']['priority'] ?? null;
                $cached['GLO_SOURCE_PRIORITY'] = is_array($priority) && array_is_list($priority) && count(array_filter($priority, 'is_string')) === count($priority) ? implode(',', $priority) : null;
                $cached['GLO_FALL_THROUGH_TO_FIXTURE'] = $config['glo']['sources']['fall_through_to_fixture'] ?? null;
                $cached['PRIZE_PAYOUT_SAFETY_MODE'] = $config['finance']['prize_payout']['safety_mode'] ?? null;
                $cached['LOTTERY_AUTO_PAYOUT'] = $config['lottery']['payouts']['auto_process'] ?? null;
                foreach (['stripe', 'bkash', 'nagad', 'crypto', 'promptpay', 'bank_transfer'] as $gateway) {
                    $cached[strtoupper($gateway).'_ENABLED'] = $config['payment']['gateways'][$gateway]['enabled'] ?? null;
                }
                $errors = array_merge($errors, CiEnvironmentGuard::violations($cached, $root, true));
            }
        }
    }
    if ($errors !== []) {
        foreach (array_unique($errors) as $error) {
            // Fixed diagnostic text only. Never print env/config/credentials.
            fwrite(STDERR, 'CI runtime guard refused: '.$error."\n");
        }
        exit(1);
    }
    echo "CI runtime guard passed: disposable testing target, fixtures/providers/live payout disabled. No schema or funds changed.\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "CI runtime guard refused: environment/config could not be validated. No schema command was run.\n");
    exit(1);
}
