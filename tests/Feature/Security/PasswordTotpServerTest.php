<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\TotpCredential;
use App\Models\TotpRecoveryCode;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Process\Process;
use Tests\Support\InnoDbLockWitness;
use Tests\Support\RunsServerFinancialRaces;
use Tests\TestCase;

/** Independent processes must serialize password revocation and proof issuance. */
final class PasswordTotpServerTest extends TestCase
{
    use DatabaseTruncation;
    use RunsServerFinancialRaces;

    public function test_password_change_and_recovery_proof_contend_on_the_same_real_user_lock(): void
    {
        $this->racePasswordMutationAgainstProof('change');
    }

    public function test_password_reset_and_recovery_proof_contend_on_the_same_real_user_lock(): void
    {
        $this->racePasswordMutationAgainstProof('reset');
    }

    private function racePasswordMutationAgainstProof(string $kind): void
    {
        $this->requireServerRace();
        $user = User::factory()->create(['password' => 'CurrentPass123!'])->fresh();
        $credential = new TotpCredential;
        $credential->forceFill([
            'user_id' => $user->id, 'enrollment_reference' => str_repeat('a', 32),
            'secret' => str_repeat('A', 32), 'confirmed_at_epoch' => now()->getTimestamp(),
            'last_accepted_step' => 0,
        ])->save();
        $recovery = new TotpRecoveryCode;
        $recovery->forceFill([
            'totp_credential_id' => $credential->id, 'batch_reference' => str_repeat('b', 32),
            'code_hash' => hash('sha256', 'AABBCCDD-EEFF0011'),
            'issued_at_epoch' => now()->getTimestamp(), 'consumed_at_epoch' => null,
        ])->save();
        DB::table('sessions')->insert(['id' => 'synthetic-racing-session', 'user_id' => $user->id,
            'payload' => base64_encode('synthetic'), 'last_activity' => now()->getTimestamp()]);
        $user->createToken('synthetic-racing-token');
        $resetToken = $kind === 'reset' ? Password::broker()->createToken($user) : null;

        $directory = storage_path('framework/testing/password-totp-races/'.bin2hex(random_bytes(12)));
        mkdir($directory, 0700, true);
        $processes = [];
        $db = DB::connection();
        $code = <<<'PHP'
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = $app->make('db')->connection();
    if (! app()->environment('testing') || $db->getDatabaseName() !== 'thai_lottery_test'
        || $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('Unsafe password/TOTP race connection.');
    }
    $file = $argv[1];
    $input = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    // Both snapshots predate the race, exactly as in-flight requests would.
    $snapshot = \App\Models\User::query()->findOrFail($input['user_id']);
    file_put_contents($file.'.ready', (string) $db->selectOne('SELECT CONNECTION_ID() AS id')->id);
    $deadline = microtime(true) + 20;
    while (! is_file(dirname($file).'/go') && microtime(true) < $deadline) { usleep(10000); }
    if (! is_file(dirname($file).'/go')) { throw new RuntimeException('Race barrier timeout.'); }
    if ($input['kind'] === 'change') {
        app(\App\Services\Auth\PasswordChangeService::class)->change($snapshot, 'CurrentPass123!', 'DifferentPass456!');
        echo json_encode(['outcome' => 'changed'], JSON_THROW_ON_ERROR);
    } elseif ($input['kind'] === 'reset') {
        app(\App\Services\Auth\PasswordResetService::class)->resetPassword(
            \Illuminate\Http\Request::create('/reset', 'POST'),
            ['email' => $snapshot->email, 'token' => $input['reset_token'],
                'password' => 'DifferentPass456!', 'password_confirmation' => 'DifferentPass456!'],
        );
        echo json_encode(['outcome' => 'reset'], JSON_THROW_ON_ERROR);
    } else {
        try {
            app(\App\Services\Security\TotpMfaEnrollmentService::class)
                ->verifyRecoveryCode($snapshot, 'AABBCCDD-EEFF0011', 'synthetic-racing-session', 'withdrawal');
            echo json_encode(['outcome' => 'issued'], JSON_THROW_ON_ERROR);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            if (! array_key_exists('mfa', $exception->errors())) { throw $exception; }
            echo json_encode(['outcome' => 'stale'], JSON_THROW_ON_ERROR);
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(1);
}
PHP;
        try {
            foreach ([$kind, 'proof'] as $i => $childKind) {
                $file = $directory.'/child-'.$i.'.json';
                file_put_contents($file, json_encode(['kind' => $childKind, 'user_id' => $user->id, 'reset_token' => $childKind === 'reset' ? $resetToken : null], JSON_THROW_ON_ERROR));
                chmod($file, 0600);
                $process = new Process([PHP_BINARY, '-r', $code, $file], base_path(), [
                    'APP_BASE_PATH' => base_path(), 'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
                    'DB_CONNECTION' => $db->getName(), 'DB_DATABASE' => 'thai_lottery_test',
                    'DB_SOCKET' => (string) $db->getConfig('unix_socket'),
                    'DB_USERNAME' => (string) $db->getConfig('username'), 'DB_PASSWORD' => (string) $db->getConfig('password'),
                    'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'database', 'LOG_CHANNEL' => 'null',
                ]);
                $process->setTimeout(60);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($directory.'/child-0.json.ready') || ! is_file($directory.'/child-1.json.ready')) && microtime(true) < $deadline) {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        $this->fail('Race child failed to start: '.$process->getErrorOutput());
                    }
                }
                usleep(10000);
            }
            $ids = [];
            foreach ([0, 1] as $i) {
                $marker = $directory.'/child-'.$i.'.json.ready';
                $this->assertFileExists($marker);
                $ids[] = (int) file_get_contents($marker);
            }
            $this->assertNotSame($ids[0], $ids[1]);
            $parent = (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
            $db->beginTransaction();
            $this->assertNotNull($db->table('users')->where('id', $user->id)->lockForUpdate()->first());
            file_put_contents($directory.'/go', 'go');
            $deadline = microtime(true) + 12;
            $waiting = 0;
            while ($waiting !== 2 && microtime(true) < $deadline) {
                $waiting = InnoDbLockWitness::waiting($db, $ids, $parent, 'users');
                if ($waiting !== 2) {
                    usleep(200000);
                }
            }
            $db->rollBack();
            $this->assertSame(2, $waiting, 'Both independent requests must directly wait on the same user lock.');
            $outcomes = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $this->assertSame('', $process->getErrorOutput());
                $outcomes[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['outcome'];
            }
            $this->assertSame($kind === 'reset' ? 'reset' : 'changed', $outcomes[0]);
            $this->assertContains($outcomes[1], ['issued', 'stale']);
            $fresh = $user->fresh();
            $this->assertTrue(Hash::check('DifferentPass456!', (string) $fresh->password));
            $this->assertFalse(Hash::check('CurrentPass123!', (string) $fresh->password));
            $this->assertSame(1, $fresh->credential_epoch);
            $this->assertSame(1, $fresh->totp_proof_epoch);
            $this->assertDatabaseCount('totp_action_proofs', 0);
            $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
            $this->assertSame(0, $fresh->tokens()->count());
            if ($kind === 'reset') {
                $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
            }
            if ($outcomes[1] === 'issued') {
                $this->assertNotNull($recovery->fresh()->consumed_at_epoch);
            } else {
                $this->assertNull($recovery->fresh()->consumed_at_epoch);
            }
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
