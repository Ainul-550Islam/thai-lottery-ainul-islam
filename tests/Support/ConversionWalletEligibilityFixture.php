<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

trait ConversionWalletEligibilityFixture
{
    use ConversionQuoteSlotFixture;

    protected function walletSnapshot(array $f): array
    {
        return Wallet::withTrashed()->where('user_id', $f['user']->id)->where('currency', 'THB')
            ->whereIn('type', ['primary', 'win'])->orderBy('type')->get()->map(fn ($w) => [
                'type' => $w->getRawOriginal('type'), 'status' => $w->getRawOriginal('status'),
                'balance' => bcadd((string) $w->balance, '0', 2), 'held' => bcadd((string) $w->locked_balance, '0', 2),
                'locked' => $w->getRawOriginal('locked_at') !== null, 'deleted' => $w->getRawOriginal('deleted_at') !== null,
            ])->all();
    }

    protected function walletOldState(array $f, string $profile): void
    {
        if ($profile === 'source-restored') {
            $f['cash']->forceFill(['status' => 'frozen'])->save();
        } elseif ($profile === 'destination-restored') {
            $f['win']->forceFill(['status' => 'frozen'])->save();
        } elseif ($profile === 'funds-restored') {
            $f['cash']->forceFill(['balance' => '10.00'])->save();
        } elseif ($profile === 'source-created') {
            $f['cash']->forceDelete();
        } elseif ($profile === 'destination-created') {
            $f['win']->forceDelete();
        }
    }

    protected function walletChange(array $f, string $profile): void
    {
        $source = (int) $f['cash']->id;
        $destination = (int) $f['win']->id;
        switch ($profile) {
            case 'source-status':
            case 'destination-status':
                DB::table('wallets')->where('id', $profile === 'source-status' ? $source : $destination)->update(['status' => 'frozen']);
                break;
            case 'source-lock':
            case 'destination-lock':
                DB::table('wallets')->where('id', $profile === 'source-lock' ? $source : $destination)
                    ->update(['locked_at' => now(), 'locked_reason' => 'Synthetic current wallet restriction']);
                break;
            case 'funds-spent':
                DB::table('wallets')->where('id', $source)->update(['balance' => '10.00']);
                break;
            case 'funds-held':
                DB::table('wallets')->where('id', $source)->update(['locked_balance' => '995.00']);
                break;
            case 'source-deleted':
            case 'destination-deleted':
                DB::table('wallets')->where('id', $profile === 'source-deleted' ? $source : $destination)->update(['deleted_at' => now()]);
                break;
            case 'source-restored':
            case 'destination-restored':
                DB::table('wallets')->where('id', $profile === 'source-restored' ? $source : $destination)->update(['status' => 'active']);
                break;
            case 'funds-restored':
                DB::table('wallets')->where('id', $source)->update(['balance' => '1000.00']);
                break;
            case 'source-created':
            case 'destination-created':
                $wallet = Wallet::query()->create(['user_id' => $f['user']->id, 'currency' => 'THB',
                    'type' => $profile === 'source-created' ? 'primary' : 'win']);
                $wallet->forceFill(['balance' => $profile === 'source-created' ? '1000.00' : '100.00'])->save();
                break;
            case 'exact-replay':
                DB::table('wallets')->whereIn('id', [$source, $destination])->update(['status' => 'frozen', 'locked_at' => now()]);
                break;
            default:
                throw new \LogicException('Unknown current wallet fixture profile.');
        }
    }
}
