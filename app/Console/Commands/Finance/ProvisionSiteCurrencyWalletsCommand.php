<?php

declare(strict_types=1);

namespace App\Console\Commands\Finance;

use App\Enums\Currency;
use App\Enums\WalletStatus;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Console\Command;

/**
 * Gives every existing user a wallet in the deployment's site currency.
 *
 * New registrations already receive one. Run this once after switching the
 * deployment currency (for example THB to BDT) so existing players can deposit.
 * Existing wallets are never touched, converted or deleted.
 */
final class ProvisionSiteCurrencyWalletsCommand extends Command
{
    protected $signature = 'wallets:provision-site-currency {--dry-run : Report what would be created without writing}';

    protected $description = 'Create a missing wallet in the site currency for every existing user';

    public function handle(): int
    {
        $currency = Currency::site();
        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $existing = 0;
        $failed = 0;

        User::query()->select('id')->chunkById(500, function ($users) use ($currency, $dryRun, &$created, &$existing, &$failed): void {
            foreach ($users as $user) {
                $has = Wallet::query()
                    ->where('user_id', $user->id)
                    ->where('currency', $currency->value)
                    ->exists();

                if ($has) {
                    $existing++;

                    continue;
                }

                if ($dryRun) {
                    $created++;

                    continue;
                }

                try {
                    $wallet = new Wallet;
                    $wallet->user_id = $user->id;
                    $wallet->currency = $currency;
                    $wallet->status = WalletStatus::Active;
                    $wallet->save();
                    $created++;
                } catch (\Throwable $exception) {
                    $failed++;
                    $this->error(sprintf('user %d: %s', $user->id, $exception->getMessage()));
                }
            }
        });

        $this->info(sprintf(
            '%s %s wallets: %d %s, %d already present, %d failed.',
            $dryRun ? 'DRY RUN' : 'Done',
            $currency->value,
            $created,
            $dryRun ? 'would be created' : 'created',
            $existing,
            $failed,
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
