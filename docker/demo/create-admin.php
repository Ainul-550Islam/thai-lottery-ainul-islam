<?php

// Demo-only: create (or update) the local demo admin. Called by run.sh.
// Idempotent: the user is looked up by email, so a second run updates it.

require '/var/www/html/vendor/autoload.php';

$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$email = getenv('DEMO_ADMIN_EMAIL') ?: 'demo@example.test';
$password = getenv('DEMO_ADMIN_PASSWORD');

if ($password === false || $password === '') {
    fwrite(STDERR, "create-admin: DEMO_ADMIN_PASSWORD is empty\n");
    exit(1);
}

$user = App\Models\User::query()->firstOrNew(['email' => $email]);
$user->forceFill([
    'name' => 'Demo Admin',
    'username' => 'demoadmin',
    'password' => Illuminate\Support\Facades\Hash::make($password),
    'status' => 'active',
    'email_verified_at' => now(),
])->save();
$user->assignRole('super-admin');

$canOpenPanel = App\Support\Admin\AdminAccess::canAccessPanel($user->fresh());
echo 'create-admin: ', $email, ' panel access: ', $canOpenPanel ? 'yes' : 'NO', "\n";

exit($canOpenPanel ? 0 : 1);
