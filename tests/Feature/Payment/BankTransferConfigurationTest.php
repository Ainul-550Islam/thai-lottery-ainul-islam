<?php

namespace Tests\Feature\Payment;

use App\DTOs\Payment\GatewayDepositResponse;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payment\Drivers\BankTransferGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MANUAL BANK TRANSFER SETTLEMENT (FINAL AUDIT #4).
 *
 * The hardcoded Bangkok Bank account ("123-4-56789-0" / "Thai Lottery
 * Official Co.") was a fabricated operator identity: a plausible-looking
 * account number players might really transfer money to, and an "official"
 * label this platform is not entitled to claim. The gateway now renders
 * ONLY operator-configured settlement details and fails closed when they
 * are missing. The acceptance is zero occurrences of the fabricated
 * identity anywhere in source, tests or fixtures.
 */
class BankTransferConfigurationTest extends TestCase
{
    use RefreshDatabase;

    /** Historical fabricated identity that must not exist anywhere. */
    private const BANNED_STRINGS = [
        '123-4-56789-0',
        'Thai Lottery Official Co.',
    ];

    private function makeDeposit(Wallet $wallet): Deposit
    {
        $deposit = new Deposit();
        $deposit->fill([
            'reference_number' => 'DP-'.date('Ymd').'-BANKTST',
            'user_id' => $wallet->user_id,
            'wallet_id' => $wallet->id,
            'method' => PaymentMethod::BankTransfer,
            'provider' => 'bank_transfer',
            'currency' => $wallet->currency,
            'amount' => '500.00',
            'fee' => '0.00',
            'net_amount' => '500.00',
            'metadata' => [],
        ]);
        $deposit->uuid = (string) \Illuminate\Support\Str::uuid();
        $deposit->status = DepositStatus::Pending;
        $deposit->save();

        return $deposit;
    }

    private function player(): Wallet
    {
        $user = User::factory()->create();

        return Wallet::factory()
            ->for($user)
            ->withBalance('1000.00')
            ->create(['currency' => 'THB']);
    }

    public function test_fails_closed_when_settlement_details_are_not_configured(): void
    {
        config([
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.gateways.bank_transfer.settlement' => [
                'bank_name' => null,
                'account_number' => null,
                'account_name' => null,
                'instructions' => null,
            ],
        ]);

        $wallet = $this->player();
        $deposit = $this->makeDeposit($wallet);

        /** @var GatewayDepositResponse $response */
        $response = $this->app->make(BankTransferGateway::class)->initiateDeposit($deposit);

        $this->assertFalse($response->successful);
        $this->assertNull($response->redirectUrl);
        $this->assertStringContainsString('not configured', (string) $response->errorMessage);
    }

    public function test_fails_closed_when_settlement_details_are_only_partially_configured(): void
    {
        config([
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.gateways.bank_transfer.settlement' => [
                'bank_name' => 'Real Operator Bank',
                'account_number' => '555-666-777',
                'account_name' => null,
                'instructions' => null,
            ],
        ]);

        $wallet = $this->player();
        $deposit = $this->makeDeposit($wallet);

        /** @var GatewayDepositResponse $response */
        $response = $this->app->make(BankTransferGateway::class)->initiateDeposit($deposit);

        $this->assertFalse($response->successful);
        $this->assertStringContainsString('not configured', (string) $response->errorMessage);
    }

    public function test_renders_exactly_the_operator_configured_settlement_details(): void
    {
        config([
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.gateways.bank_transfer.settlement' => [
                'bank_name' => 'Real Operator Bank',
                'account_number' => '555-666-777',
                'account_name' => 'Real Operator Company Ltd',
                'instructions' => 'Include the reference in your transfer slip.',
            ],
        ]);

        $wallet = $this->player();
        $deposit = $this->makeDeposit($wallet);

        /** @var GatewayDepositResponse $response */
        $response = $this->app->make(BankTransferGateway::class)->initiateDeposit($deposit);

        $this->assertTrue($response->successful);

        $instructions = $response->metadata['instructions'] ?? null;
        $this->assertIsArray($instructions);
        $this->assertSame('Real Operator Bank', $instructions['bank_name'] ?? null);
        $this->assertSame('555-666-777', $instructions['account_number'] ?? null);
        $this->assertSame('Real Operator Company Ltd', $instructions['account_name'] ?? null);
        $this->assertSame($deposit->reference_number, $instructions['reference'] ?? null);
        $this->assertSame('Include the reference in your transfer slip.', $instructions['instructions'] ?? null);
    }

    /**
     * FINAL AUDIT #4 acceptance, self-enforcing: the fabricated bank
     * identity must appear NOWHERE in source, configuration, views,
     * fixtures or tests.
     */
    public function test_the_fabricated_bank_identity_appears_nowhere_in_the_repository(): void
    {
        // Repository root (tests/Feature/Payment -> three levels up).
        $root = dirname(__DIR__, 3);

        $scanDirs = [
            $root.'/app',
            $root.'/config',
            $root.'/database',
            $root.'/resources',
            $root.'/routes',
            $root.'/tests',
            $root.'/lang',
        ];

        $violations = [];

        foreach ($scanDirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $file) {
                if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                $name = $file->getFilename();
                $ext = $file->getExtension();

                if (! in_array($ext, ['php', 'json', 'xml', 'txt', 'md', 'csv', 'js', 'ts'], true)
                    && ! str_ends_with($name, '.blade.php')
                    && $name !== '.env.example') {
                    continue;
                }

                // Skip the vendor/build trees if they ever appear inside a
                // scanned dir, and skip the GUARD test files themselves (a
                // ban must name what it bans — that is their only allowed
                // appearance in the repository).
                $path = $file->getPathname();
                if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')
                    || str_contains($path, '/build/') || str_contains($path, '/storage/')
                    || str_ends_with($path, 'BankTransferConfigurationTest.php')
                    || str_ends_with($path, 'PublicContentSourceTest.php')) {
                    continue;
                }

                $contents = (string) file_get_contents($path);

                foreach (self::BANNED_STRINGS as $banned) {
                    if (str_contains($contents, $banned)) {
                        $violations[] = $banned.' in '.$path;
                    }
                }
            }
        }

        $this->assertSame([], $violations, sprintf(
            "Fabricated bank identity strings must not exist in the repository:\n%s",
            implode("\n", $violations),
        ));
    }

    public function test_thai_lottery_official_company_is_also_banned(): void
    {
        // Guard the second form of the fabricated holder name explicitly.
        $gatewaySource = strtolower((string) file_get_contents(dirname(__DIR__, 3).'/app/Services/Payment/Drivers/BankTransferGateway.php'));

        $this->assertStringNotContainsString('official co', $gatewaySource);
        $this->assertStringNotContainsString('bangkok bank', $gatewaySource);
    }

    public function test_gateway_supports_thb_currency_for_manual_settlement(): void
    {
        $gateway = $this->app->make(BankTransferGateway::class);

        $this->assertTrue($gateway->supportsCurrency(Currency::THB));
        $this->assertFalse($gateway->supportsWebhook());
    }
}
