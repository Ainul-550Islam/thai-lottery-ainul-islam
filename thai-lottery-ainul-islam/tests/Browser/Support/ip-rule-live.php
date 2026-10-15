<?php

declare(strict_types=1);
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\IpAccessRule;
use App\Models\User;
use App\Services\Network\IpAccessRuleManager;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Test-only CLI/router. Never deploy this as a production front controller.
$repo = dirname(__DIR__, 3);
$root = getenv('IP_RULE_BROWSER_ROOT');
$mode = PHP_SAPI === 'cli-server' ? 'serve' : ($argv[1] ?? '');
$fail = static function (string $message): never {
    if (PHP_SAPI === 'cli-server') {
        http_response_code(503);
    }
    echo $message."\n";
    exit(2);
};
$parent = realpath($repo.'/storage/framework/testing');
if (! in_array(PHP_SAPI, ['cli', 'cli-server'], true)
    || ! is_string($root) || ! is_dir($root) || is_link($root)
    || $parent === false || ! preg_match('/^ip-rule-live-[a-f0-9]{16}$/D', basename($root))
    || realpath($root) !== $parent.'/'.basename($root)
    || getenv('IP_RULE_BROWSER_TEST_ONLY') !== '1') {
    $fail('REFUSED: an explicitly enabled private browser fixture directory is required.');
}
$root = realpath($root);
$database = $root.'/fixture.sqlite';
$manifest = $root.'/fixture.json';
if (! in_array($mode, ['prepare', 'serve', 'inspect', 'audit-offline', 'audit-online', 'suspend-operator'], true)) {
    $fail('REFUSED: unknown fixture operation.');
}
if ($mode === 'prepare') {
    if (file_exists($database) || file_exists($manifest) || is_link($database) || is_link($manifest)) {
        $fail('REFUSED: fixture preparation never overwrites an existing database or manifest.');
    }
    if (array_values(array_diff(scandir($root), ['.', '..'])) !== []) {
        $fail('REFUSED: preparation requires an empty fixture directory.');
    }
    $meta = ['fixture_version' => 1, 'key' => 'base64:'.base64_encode(random_bytes(32)),
        'password' => 'Test!'.bin2hex(random_bytes(24)), 'ready' => false];
    foreach (['cache', 'storage/logs', 'storage/framework/sessions', 'storage/framework/views', 'storage/framework/cache/data'] as $directory) {
        if (! is_dir($root.'/'.$directory)) {
            mkdir($root.'/'.$directory, 0700, true);
        }
    }
    file_put_contents($root.'/.env', '');
    touch($database);
    chmod($database, 0600);
    file_put_contents($manifest, json_encode($meta, JSON_THROW_ON_ERROR));
    chmod($manifest, 0600);
} else {
    if (! is_file($database) || is_link($database) || ! is_file($manifest) || is_link($manifest)) {
        $fail('REFUSED: fixture files are missing or unsafe.');
    }
    $meta = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
    if (($meta['fixture_version'] ?? null) !== 1 || ($meta['ready'] ?? null) !== true) {
        $fail('REFUSED: fixture is not ready.');
    }
}
// Override ambient environment/cache paths BEFORE application bootstrap.
$environment = [
    'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_KEY' => $meta['key'],
    'APP_URL' => 'http://127.0.0.1:8091', 'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $database, 'DB_URL' => '', 'DATABASE_URL' => '',
    'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'ip_rule_browser_session',
    'SESSION_DOMAIN' => 'null', 'SESSION_SECURE_COOKIE' => 'false', 'SESSION_ENCRYPT' => 'false',
    'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'log',
    'BROADCAST_CONNECTION' => 'log', 'LOG_CHANNEL' => 'single',
    'AUTH_CAPTCHA_ENABLED' => 'false', 'HONEYPOT_ENABLED' => 'false',
    'IP_ACCESS_MANAGEMENT_ENABLED' => 'true', 'IP_ACCESS_DATABASE_ENABLED' => 'false',
    'IP_ACCESS_ENABLED' => 'true', 'IP_ACCESS_MODE' => 'log', 'IP_ACCESS_BLOCKED_RANGES' => '',
    'APP_CONFIG_CACHE' => $root.'/cache/config.php', 'APP_ROUTES_CACHE' => $root.'/cache/routes.php',
    'APP_EVENTS_CACHE' => $root.'/cache/events.php', 'APP_SERVICES_CACHE' => $root.'/cache/services.php',
    'APP_PACKAGES_CACHE' => $root.'/cache/packages.php',
];
foreach ($environment as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
require $repo.'/vendor/autoload.php';
$app = require $repo.'/bootstrap/app.php';
$app->useEnvironmentPath($root);
$app->useStoragePath($root.'/storage');
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite'
    || DB::connection()->getDatabaseName() !== $database
    || config('session.driver') !== 'file' || $app->runningUnitTests()) {
    $fail('REFUSED: fixture database/session/runtime isolation check failed.');
}
if ($mode === 'prepare') {
    if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
        $fail('REFUSED: fixture migration failed.');
    }
    (new LedgerAccountSeeder)->run();
    foreach (['admin', 'player'] as $role) {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create([
            'email' => $role.'.'.bin2hex(random_bytes(6)).'@example.test',
            'password' => Hash::make($meta['password']),
            'status' => UserStatus::Active,
        ]);
        $user->assignRole($role);
        if ($role === 'admin') {
            foreach (['view dashboard', IpAccessRuleManager::MANAGE_PERMISSION] as $permission) {
                Permission::findOrCreate($permission, 'web');
                $user->givePermissionTo($permission);
            }
        }
        $meta[$role] = ['id' => $user->id, 'email' => $user->email];
    }
    $meta['ready'] = true;
    file_put_contents($manifest, json_encode($meta, JSON_THROW_ON_ERROR));
    echo "READY: disposable fixture prepared; credentials remain in its private manifest.\n";
    exit(0);
}
if ($mode === 'inspect') {
    $audits = Schema::hasTable('audit_logs')
        ? AuditLog::where('auditable_type', IpAccessRule::class)->orderBy('id')->get(['user_id', 'action', 'auditable_id'])->toArray() : null;
    echo json_encode([
        'rules' => IpAccessRule::orderBy('id')->get(['id', 'ip_range', 'created_by', 'revoked_at_epoch'])->toArray(),
        'audits' => $audits, 'mutex_version' => DB::table('ip_access_rule_mutex')->value('version'),
        'database_policy_enabled' => config('ip_access.database_enabled'),
    ], JSON_THROW_ON_ERROR)."\n";
    exit(0);
}
if ($mode === 'audit-offline') {
    Schema::rename('audit_logs', 'audit_logs_fixture_hold');
    echo "OK: fixture audit store offline.\n";
    exit(0);
}
if ($mode === 'audit-online') {
    Schema::rename('audit_logs_fixture_hold', 'audit_logs');
    echo "OK: fixture audit store restored.\n";
    exit(0);
}
if ($mode === 'suspend-operator') {
    User::whereKey($meta['admin']['id'])->update(['status' => UserStatus::Suspended->value]);
    echo "OK: fixture operator suspended.\n";
    exit(0);
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$public = realpath($repo.'/public');
$file = is_string($path) ? realpath($public.$path) : false;
$types = ['css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png',
    'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2'];
$extension = $file ? pathinfo($file, PATHINFO_EXTENSION) : '';
if ($file && str_starts_with($file, $public.'/') && is_file($file) && isset($types[$extension])) {
    header('Content-Type: '.$types[$extension]);
    readfile($file);

    return;
}
// No fixture mutation HTTP routes; unrelated application surfaces are not exposed by this router.
if (! is_string($path) || (! in_array($path, ['/admin/login', '/login', '/logout'], true)
    && ! str_starts_with($path, '/admin/ip-access/rules'))) {
    http_response_code(404);
    echo 'Disposable browser fixture: route not exposed.';

    return;
}
header('X-Test-Fixture: disposable-only');
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
