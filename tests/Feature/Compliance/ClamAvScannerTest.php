<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Exceptions\AntimalwareException;
use App\Services\Antimalware\AntimalwareManager;
use App\Services\Antimalware\ClamAvScanner;
use App\Services\Antimalware\NullScanner;
use Tests\TestCase;

/**
 * The ClamAV driver: what it refuses, and what it refuses to pretend.
 *
 * ============================================================================
 * NO SOCKET IS OPENED IN THIS SUITE
 * ============================================================================
 * Every assertion below is about the decisions the driver makes BEFORE or
 * INSTEAD of talking to clamd — file missing, file too large, no daemon
 * configured, a daemon that cannot be reached. Those are the cases where a naive
 * implementation returns `clean` because it had nothing to compare against, and
 * a `clean` verdict is what releases a KYC document or a payment slip into
 * private storage. The INSTREAM wire protocol itself is exercised by the manager
 * suite against a stub, not here.
 */
final class ClamAvScannerTest extends TestCase
{
    private function scanner(): ClamAvScanner
    {
        return $this->app->make(ClamAvScanner::class);
    }

    private function tempFile(string $contents = 'hello'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tl-clam-');

        $this->assertIsString($path);
        file_put_contents($path, $contents);

        // The file is cleaned up by the process; tempnam() outside the workspace
        // is never part of the deliverable.
        return $path;
    }

    public function test_the_driver_reports_its_own_name(): void
    {
        $this->assertSame('clamav', $this->scanner()->name());
        $this->assertSame(ClamAvScanner::DRIVER, $this->scanner()->name());
    }

    public function test_availability_follows_configuration_and_does_not_probe_the_network(): void
    {
        // No socket and no host/port anywhere: there is nowhere to send a file.
        config([
            'antimalware.clamav.socket' => '',
            'antimalware.clamav.host' => '',
            'antimalware.clamav.port' => 0,
        ]);

        $this->assertFalse($this->scanner()->isAvailable(), 'no address configured means the driver cannot scan');

        config(['antimalware.clamav.socket' => 'unix:///var/run/clamav/clamd.ctl']);

        $this->assertTrue(
            $this->scanner()->isAvailable(),
            'a configured socket is available: reachability is the scan\'s job, not a probe',
        );

        // The host/port pair is the second documented way to declare an address,
        // and it must work without a socket.
        config([
            'antimalware.clamav.socket' => '',
            'antimalware.clamav.host' => '127.0.0.1',
            'antimalware.clamav.port' => 3310,
        ]);

        $this->assertTrue($this->scanner()->isAvailable());

        // An out-of-range port is not an address, it is a typo.
        config(['antimalware.clamav.port' => 99999]);
        $this->assertFalse($this->scanner()->isAvailable());
    }

    public function test_a_missing_file_is_refused_instead_of_reported_clean(): void
    {
        config(['antimalware.clamav.socket' => 'unix:///var/run/clamav/clamd.ctl']);

        try {
            $this->scanner()->scan(storage_path('app/private/does-not-exist.bin'));
            $this->fail('scanning a missing file must not return a verdict');
        } catch (AntimalwareException $e) {
            $this->assertSame('antimalware_unavailable', $e->errorCode());
            $this->assertSame('file_not_readable', $e->context()['reason'] ?? null);
        }
    }

    public function test_a_file_over_the_configured_limit_is_refused_before_it_is_streamed(): void
    {
        config([
            'antimalware.clamav.socket' => 'unix:///var/run/clamav/clamd.ctl',
            'antimalware.max_file_size_bytes' => 1024,
        ]);

        $path = $this->tempFile(str_repeat('a', 4096));

        try {
            $this->scanner()->scan($path);
            $this->fail('an oversized file must not be scanned');
        } catch (AntimalwareException $e) {
            $this->assertSame('antimalware_file_too_large', $e->errorCode());
            $this->assertSame(4096, $e->context()['bytes'] ?? null);
            $this->assertSame(1024, $e->context()['max_bytes'] ?? null);
        } finally {
            @unlink($path);
        }
    }

    public function test_an_unreachable_daemon_is_unavailable_and_never_clean(): void
    {
        // A unix socket inside the workspace that nothing is listening on. The
        // connect fails immediately, which is exactly the state this test needs.
        config([
            'antimalware.clamav.socket' => 'unix://'.storage_path('app/private/not-a-daemon.sock'),
            'antimalware.max_file_size_bytes' => 4_194_304,
            'antimalware.timeout_seconds' => 1,
        ]);

        $path = $this->tempFile();

        try {
            $verdict = $this->scanner()->scan($path);
            $this->fail('an unreachable daemon produced a verdict: '.json_encode($verdict));
        } catch (AntimalwareException $e) {
            $this->assertSame('antimalware_unavailable', $e->errorCode());

            // The refusal must not publish the socket path: it names the internal
            // address, and this exception reaches logs.
            $encoded = json_encode($e->context());
            $this->assertIsString($encoded);
            $this->assertStringNotContainsString('not-a-daemon.sock', $encoded);
        } finally {
            @unlink($path);
        }
    }

    public function test_the_null_driver_never_reports_clean_and_is_refused_in_production(): void
    {
        $scanner = $this->app->make(NullScanner::class);

        $path = $this->tempFile();

        try {
            $verdict = $scanner->scan($path);

            $this->assertSame('not_scanned', $verdict['verdict']);
            $this->assertNotSame('clean', $verdict['verdict']);
        } finally {
            @unlink($path);
        }

        $this->app['env'] = 'production';

        $this->expectException(AntimalwareException::class);
        $scanner->scan($path);
    }

    public function test_the_manager_refuses_an_unknown_driver_rather_than_falling_back(): void
    {
        config(['antimalware.driver' => 'definitely-not-a-scanner']);

        $this->expectException(AntimalwareException::class);

        $this->app->make(AntimalwareManager::class)->driver();
    }

    public function test_the_manager_only_claims_to_enforce_when_a_real_scanner_is_selected(): void
    {
        // isEnforcing() is a statement about the DRIVER, not a config flag: a
        // page that prints "scanned" must be right because a scanner ran, not
        // because an operator set enforce=true next to the null driver.
        $manager = $this->app->make(AntimalwareManager::class);

        config(['antimalware.driver' => 'clamav']);
        $this->assertTrue($manager->isEnforcing(), 'clamav is the real scanner');

        config(['antimalware.driver' => 'null']);
        $this->assertFalse($manager->isEnforcing(), 'the null driver never enforces anything');

        config(['antimalware.driver' => 'typodriver']);
        $this->assertFalse($manager->isEnforcing(), 'an unresolvable driver cannot be enforcing');
    }
}
