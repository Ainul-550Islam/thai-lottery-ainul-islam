<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Exceptions\RealMoneyNotLicensedException;
use App\Services\License\LicenseService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Configuration mechanics only; no test claims that a licence is authentic. */
final class LicenseConfigurationTest extends TestCase
{
    public function test_country_configuration_is_a_cacheable_normalized_array(): void
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
$config = require 'config/real_money.php';
echo json_encode($config['authorized_countries'], JSON_THROW_ON_ERROR);
PHP;
        $process = new Process([PHP_BINARY, '-r', $code], base_path(), [
            'REAL_MONEY_LICENSE_COUNTRIES' => ' th, BD,us,th, ,bd ',
        ]);
        $process->mustRun();
        $this->assertSame(['TH', 'BD', 'US'], json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR));

        // The entire configuration must round-trip through Laravel's var_export
        // based cache rather than contain a Closure or non-restorable object.
        $value = config('real_money');
        $restored = eval('return '.var_export($value, true).';');
        $this->assertSame($value, $restored);
    }

    public function test_the_container_can_resolve_the_existing_license_service_and_uses_country_data(): void
    {
        config(['real_money.license' => [
            'issuer' => 'synthetic issuer', 'scope' => 'synthetic scope',
            'expiry' => '2099-12-31', 'countries' => 'TH,BD,US', 'signature' => 'synthetic-audit-only',
        ], 'real_money.authorized_countries' => ['TH', 'BD', 'US']]);
        $service = app(LicenseService::class);
        $this->assertInstanceOf(LicenseService::class, $service);
        $service->assertLicensed('BD'); // Only a formatting/allow-list check, not cryptographic verification.

        try {
            $service->assertLicensed('JP');
            $this->fail('An unlisted country must be refused by this configuration check.');
        } catch (RealMoneyNotLicensedException $exception) {
            $this->assertSame('real_money.license.country_not_authorized', $exception->errorCode());
        }
    }
}
