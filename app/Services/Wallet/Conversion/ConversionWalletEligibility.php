<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\DTOs\Wallet\ConversionRequestData;
use App\Enums\WalletStatus;
use App\Exceptions\Wallet\ConversionException;
use App\Exceptions\Wallet\ConversionInsufficientBalanceException;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/** Wallet facts only: never a replacement for member, pricing or financial policy. */
final class ConversionWalletEligibility
{
    public function assertAllowed(ConversionRequestData $request): void
    {
        if (DB::transactionLevel() > 0) {
            $this->pairForExecution($request);

            return;
        }
        $this->pair($request, false); // Nonlocking advisory; no reservation or later authority.
    }

    /** A verdict, even inside a caller transaction, never reserves wallet authority. */
    public function assertAdvisoryAllowed(ConversionRequestData $request): void
    {
        $this->pair($request, false);
    }

    /**
     * Current identities and facts retained under the caller's actual transaction.
     * Missing destination is allowed; deleted destination is not silently recreated.
     *
     * @return array{source: Wallet, destination: Wallet|null}
     */
    public function pairForExecution(ConversionRequestData $request): array
    {
        if (DB::transactionLevel() < 1 || ! DB::connection()->getPdo()->inTransaction()) {
            throw ConversionException::failure('transaction_required', 'Wallet eligibility requires the caller money transaction.');
        }
        // Always acquire the existing owner mutex before any wallet fact locks.
        if (User::query()->whereKey($request->userId)->lockForUpdate()->first() === null) {
            $this->restricted();
        }

        return $this->pair($request, true);
    }

    private function pair(ConversionRequestData $request, bool $current): array
    {
        if (preg_match('/^\d{1,18}(\.\d{1,2})?$/D', $request->amount) !== 1 || bccomp($request->amount, '0', 2) <= 0) {
            throw ConversionException::failure('amount_invalid', 'A positive exact decimal amount is required.');
        }
        $types = [$request->fromWalletType()->value, $request->toWalletType()->value];
        sort($types, SORT_STRING);
        $query = Wallet::withTrashed()->where('user_id', $request->userId)
            ->where('currency', $request->currency->value)->whereIn('type', $types)->orderBy('id');
        $rows = ($current ? $query->lockForUpdate() : $query->useWritePdo())->get();
        $byType = [];
        foreach ($rows as $wallet) {
            $type = (string) $wallet->getRawOriginal('type');
            if (isset($byType[$type]) || ! in_array($type, $types, true)
                || (int) $wallet->user_id !== $request->userId
                || $wallet->getRawOriginal('currency') !== $request->currency->value) {
                $this->restricted();
            }
            $byType[$type] = $wallet;
        }
        $source = $byType[$request->fromWalletType()->value] ?? null;
        $destination = $byType[$request->toWalletType()->value] ?? null;
        if ($source === null) {
            $this->restricted();
        }
        $this->assertState($source, false);
        if ($destination !== null) {
            $this->assertState($destination, true);
        }
        $balance = $this->decimal($source, 'balance');
        $held = $this->decimal($source, 'locked_balance');
        if (bccomp($held, $balance, 2) > 0) {
            $this->restricted();
        }
        $available = bcsub($balance, $held, 2);
        if (bccomp($available, $request->amount, 2) < 0) {
            throw ConversionInsufficientBalanceException::forAmount((int) $source->id,
                $request->fromWalletType(), $request->direction, $request->amount, $available, $request->currency);
        }

        return ['source' => $source, 'destination' => $destination];
    }

    private function assertState(Wallet $wallet, bool $credit): void
    {
        $status = WalletStatus::tryFrom((string) $wallet->getRawOriginal('status'));
        if ($wallet->getRawOriginal('deleted_at') !== null || $wallet->getRawOriginal('locked_at') !== null
            || $status === null || ! ($credit ? $status->canCredit() : $status->canDebit())) {
            $this->restricted();
        }
    }

    private function decimal(Wallet $wallet, string $field): string
    {
        $raw = $wallet->getRawOriginal($field);
        // SQLite may hydrate a numeric affinity value as a PHP number. Native
        // decimal precision remains the SQL DECIMAL contract; never sum in floats.
        $value = is_string($raw) || is_int($raw) || is_float($raw) ? (string) $raw : '';
        if (preg_match('/^\d{1,18}(\.\d{1,2})?$/D', $value) !== 1) {
            $this->restricted();
        }

        return bcadd($value, '0', 2);
    }

    private function restricted(): never
    {
        throw ConversionException::failure('wallet_locked', 'A conversion wallet is missing, inactive, deleted, locked or inconsistent.');
    }
}
