<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\StripeRefundRecoveryIntent;
use App\Models\User;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\LedgerPostingService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\ResponsibleGaming\ResponsibleGamingPlayerLock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One source-owned local compensation; original capture/history is never rewritten. */
final class StripeRefundRecoveryService
{
    public const INTENTS = 'stripe_refund_recovery_intents';

    public const RECEIPTS = 'stripe_refund_recovery_receipts';

    public const RECOVERIES = 'stripe_refund_recoveries';

    public const PREFIX = 'stripe-full-refund:';

    public const RECEIPT_LIMIT = 100;

    public const WAGER_SOURCE_LIMIT = 100;

    public function recover(Deposit $route, ?User $actor, int $inboxId): array
    {
        app(DepositCheckoutService::class)->assertTopLevel();
        $this->authorize($actor);
        $snapshot = DB::transaction(function () use ($route, $actor, $inboxId): array {
            [$o, $source] = $this->context($route, $actor, $inboxId);
            [$intent, $receipts, $done] = $this->history($o);
            if ($done) {
                return ['done' => $this->replay($done, $intent, $receipts)];
            }

            return compact('o', 'source');
        }, 3);
        if (isset($snapshot['done'])) {
            return $snapshot['done'];
        }
        // No database transaction, PDO transaction, or wallet/player lock survives across HTTP.
        $observed = now()->timestamp;
        $proof = app(StripeRefundRecoveryLookup::class)->read($snapshot['o']);
        // Proof custody commits BEFORE the financial attempt. Insufficient spendable funds retain it for explicit retry.
        DB::transaction(function () use ($route, $actor, $inboxId, $snapshot, $proof, $observed): void {
            [$o, $source] = $this->context($route, $actor, $inboxId);
            if ($o !== $snapshot['o'] || $source !== $snapshot['source']) {
                $this->refuse();
            }
            $this->fresh($observed);
            app(StripeRefundRecoveryLookup::class)->validate($proof, $o);
            [$intent, $receipts, $done] = $this->history($o);
            if ($done) {
                $this->replay($done, $intent, $receipts);

                return;
            }
            if (count($receipts) >= self::RECEIPT_LIMIT) {
                $this->refuse('stripe_refund_receipts_saturated');
            }
            if (! $intent) {
                $id = $this->insert(self::INTENTS, ['uuid' => (string) Str::uuid(), 'deposit_id' => $o['deposit_id'],
                    'payment_id' => $o['payment_id'], 'inbox_id' => $inboxId, 'account_scope' => $o['account_scope'],
                    'configuration_hash' => $o['configuration_hash'], 'refund_reference' => $o['refund'],
                    'identity_key' => $this->identity($o), 'actor_id' => (int) $actor->id, 'created_epoch' => now()->timestamp], compact('o', 'source'));
                $intent = DB::table(self::INTENTS)->find($id);
            }
            $this->insert(self::RECEIPTS, ['uuid' => (string) Str::uuid(), 'intent_id' => (int) $intent->id,
                'inbox_id' => $inboxId, 'previous_receipt_id' => $receipts === [] ? null : (int) end($receipts)->id,
                'actor_id' => (int) $actor->id, 'observed_epoch' => $observed], compact('o', 'source', 'proof'));
        }, 3);

        return DB::transaction(function () use ($route, $actor, $inboxId): array {
            [$o, , $deposit, $wallet] = $this->context($route, $actor, $inboxId);
            [$intent, $receipts, $done] = $this->history($o);
            if ($done) {
                return $this->replay($done, $intent, $receipts);
            }
            if (! $intent || $receipts === []) {
                $this->refuse();
            }
            $receipt = end($receipts);
            $this->fresh((int) $receipt->observed_epoch);
            $key = self::PREFIX.$o['deposit_id'];
            // Never adopt/heal an unlinked transaction, even if it looks like an otherwise valid replay.
            if (FinancialTransaction::withTrashed()->where('idempotency_key', $key)->orWhere(fn ($q) => $q
                ->where('reference_type', StripeRefundRecoveryIntent::class)->where('reference_id', $intent->id))->exists()) {
                $this->refuse('stripe_refund_orphan_financial');
            }
            $tx = new FinancialTransaction;
            $tx->fill(['reference_number' => 'SRR-'.Str::upper(Str::random(24)), 'user_id' => $wallet->user_id,
                'wallet_id' => $wallet->id, 'type' => TransactionType::Adjustment, 'currency' => $deposit->currency,
                'amount' => $deposit->amount, 'fee' => '0.00', 'description' => 'Confirmed original Stripe full-refund recovery',
                'idempotency_key' => $key, 'reference_type' => StripeRefundRecoveryIntent::class, 'reference_id' => (int) $intent->id,
                'metadata' => ['engine_type' => FinancialTransactionType::Adjustment->value, 'stripe_refund_receipt' => $receipt->uuid]]);
            $tx->uuid = (string) Str::uuid();
            $tx->status = TransactionStatus::Processing;
            $tx->save();
            $amount = Money::of($deposit->amount, $deposit->currency);
            $before = (string) $wallet->balance;
            $after = app(WalletService::class)->applyDebit($wallet, $amount, FinancialTransactionType::Adjustment);
            $posting = app(LedgerPostingService::class);
            $liability = $posting->resolveAccount(LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_PLAYER_LIABILITY, $deposit->currency));
            $cash = $posting->resolveAccount(LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_SYSTEM_CASH, $deposit->currency));
            $posting->post($tx, [
                ['ledger_account_id' => (int) $liability->id, 'type' => LedgerEntryType::Debit, 'amount' => $amount, 'wallet_id' => (int) $wallet->id],
                ['ledger_account_id' => (int) $cash->id, 'type' => LedgerEntryType::Credit, 'amount' => $amount],
            ], [0 => $after->toString()]);
            $tx->status = TransactionStatus::Completed;
            $tx->processed_at = now();
            $tx->save();
            $financial = $this->financial((int) $tx->id);
            $this->insert(self::RECOVERIES, ['deposit_id' => $o['deposit_id'], 'intent_id' => (int) $intent->id,
                'receipt_id' => (int) $receipt->id, 'financial_transaction_id' => (int) $tx->id,
                'actor_id' => (int) $actor->id, 'applied_epoch' => now()->timestamp], compact('o', 'before', 'financial') + ['after' => $after->toString()]);
            $this->authorize($actor);

            return ['recovered' => true, 'replayed' => false, 'financial_transaction_id' => (int) $tx->id, 'receipt_uuid' => $receipt->uuid];
        }, 3);
    }

    /**
     * SQL-only, no staff/provider authority: the purchase root already owns its
     * wallet and must share the player mutex with original receipt publication.
     * Never acquire another wallet here: same-currency secondary wallets share
     * the player liability. Every row is a current locking read, not an RR hint.
     */
    public function hasWagerExposureInTransaction(User $player, Currency $currency, bool $proveClosure = true): bool
    {
        return $this->hasRecoveryExposureInTransaction($player, $currency, $proveClosure);
    }

    /** Shared source proof, not permission for any specific money operation. */
    public function hasRecoveryExposureInTransaction(User $player, Currency $currency, bool $proveClosure = true): bool
    {
        if (DB::transactionLevel() < 1 || ! DB::connection()->getPdo()->inTransaction()) {
            $this->refuse('stripe_refund_wager_transaction_required');
        }
        app(ResponsibleGamingPlayerLock::class)->lock((int) $player->id);
        $rows = DB::table(self::INTENTS.' as i')->join('deposits as d', 'd.id', '=', 'i.deposit_id')
            ->where('d.user_id', $player->id)->where('d.currency', $currency->value)
            ->select('i.*')->orderBy('i.id')->limit(self::WAGER_SOURCE_LIMIT + 1)->lockForUpdate()->get();
        if ($rows->count() > self::WAGER_SOURCE_LIMIT) {
            $this->refuse('stripe_refund_wager_history_saturated');
        }
        foreach ($rows as $row) {
            $body = $this->read($row);
            $o = $body['o'] ?? [];
            if (($o['user_id'] ?? null) !== (int) $player->id || ($o['currency'] ?? null) !== strtolower($currency->value)
                || ($o['deposit_id'] ?? null) !== (int) $row->deposit_id) {
                $this->refuse('stripe_refund_wager_owner_unproven');
            }
            [$intent, $receipts, $done] = $this->history($o);
            if (! $intent || (int) $intent->id !== (int) $row->id) {
                $this->refuse();
            }
            if (! $done) {
                return true; // Receipt staleness, disabled recovery and missing staff never expire this liability.
            }
            if ($proveClosure) {
                $this->replay($done, $intent, $receipts); // A completion label alone never authorizes the money write.
            }
        }

        return false;
    }

    private function context(Deposit $route, ?User $actor, int $inboxId): array
    {
        [$d, $w, $p, $a] = app(DepositCheckoutService::class)->lockedStripeSettlement($route);
        $this->authorize($actor);
        app(DepositCompletionService::class)->verifySettled($d);
        $capture = app(StripeDepositSettlementService::class)->observedCapture($p);
        if (! $capture || bccomp((string) $d->fee, '0', 2) !== 0 || bccomp((string) $d->net_amount, (string) $d->amount, 2) !== 0) {
            $this->refuse('stripe_refund_zero_fee_full_only');
        }
        // Retain the independently authenticated review history; it does not authorize the debit or clear risk.
        app(StripeDepositReviewService::class)->inspectInOriginalTransaction($d, $actor);
        $row = DB::table(StripeDepositFollowupInbox::TABLE)->lockForUpdate()->find($inboxId);
        $source = $row ? app(StripeDepositFollowupInbox::class)->read($row) : null;
        $refund = data_get($source, 'payload.data.object.id');
        $key = config('payment.gateways.stripe.secret');
        if (! $source || ! is_string($refund) || preg_match('/^re_[A-Za-z0-9]+$/D', $refund) !== 1
            || ! is_string($key) || preg_match('/^(?:sk|rk)_(test|live)_\S+$/D', $key, $mode) !== 1
            || $row->account_scope !== $a->account_scope || $row->configuration_hash !== $a->configuration_hash) {
            $this->refuse();
        }
        $o = ['deposit_id' => (int) $d->id, 'payment_id' => (int) $p->id, 'wallet_id' => (int) $w->id,
            'user_id' => (int) $w->user_id, 'credit_id' => (int) $d->financial_transaction_id,
            'capture_uuid' => data_get($p->metadata, StripeDepositSettlementService::MARKER),
            'attempt_uuid' => $a->uuid, 'terms_hash' => $a->terms_hash, 'configuration_hash' => $a->configuration_hash,
            'account_scope' => $a->account_scope, 'refund' => $refund, 'references' => $capture['references'],
            'amount' => (string) $d->amount, 'minor' => bcmul((string) $d->amount, '100', 0),
            'currency' => strtolower($d->currency->value), 'live' => $mode[1] === 'live',
            'dispatched' => Carbon::parse($a->dispatched_at, config('app.timezone'))->timestamp];
        $this->source($source, $o);

        return [$o, $source, $d, $w];
    }

    private function history(array $o): array
    {
        $intent = DB::table(self::INTENTS)->where('deposit_id', $o['deposit_id'])->lockForUpdate()->first();
        $done = DB::table(self::RECOVERIES)->where('deposit_id', $o['deposit_id'])->lockForUpdate()->first();
        if (! $intent) {
            if ($done || DB::table(self::INTENTS)->where('identity_key', $this->identity($o))->exists()) {
                $this->refuse();
            }

            return [null, [], null];
        }
        $body = $this->read($intent);
        if (($body['o'] ?? null) !== $o || (int) $intent->deposit_id !== $o['deposit_id']
            || (int) $intent->payment_id !== $o['payment_id'] || $intent->identity_key !== $this->identity($o)
            || $intent->account_scope !== $o['account_scope'] || $intent->configuration_hash !== $o['configuration_hash']
            || $intent->refund_reference !== $o['refund']) {
            $this->refuse();
        }
        $this->source($body['source'] ?? [], $o, (int) $intent->inbox_id);
        $receipts = DB::table(self::RECEIPTS)->where('intent_id', $intent->id)->orderBy('id')->limit(self::RECEIPT_LIMIT + 1)->lockForUpdate()->get()->all();
        if ($receipts === [] || count($receipts) > self::RECEIPT_LIMIT) {
            $this->refuse();
        }
        $previous = null;
        foreach ($receipts as $r) {
            $b = $this->read($r);
            if (($b['o'] ?? null) !== $o || ($r->previous_receipt_id === null ? null : (int) $r->previous_receipt_id) !== $previous) {
                $this->refuse();
            }
            $this->source($b['source'] ?? [], $o, (int) $r->inbox_id);
            app(StripeRefundRecoveryLookup::class)->validate($b['proof'] ?? [], $o);
            $previous = (int) $r->id;
        }

        return [$intent, $receipts, $done];
    }

    private function replay(object $done, object $intent, array $receipts): array
    {
        $body = $this->read($done);
        $owner = $this->read($intent);
        $receipt = collect($receipts)->first(fn ($r) => (int) $r->id === (int) $done->receipt_id);
        $tx = FinancialTransaction::withTrashed()->lockForUpdate()->find($done->financial_transaction_id);
        $o = $owner['o'];
        if (! $receipt || ! $tx || ($body['o'] ?? null) !== $o || (int) $done->intent_id !== (int) $intent->id
            || $tx->trashed() || $tx->status !== TransactionStatus::Completed || ! $tx->processed_at
            || $tx->type !== TransactionType::Adjustment || $tx->reference_type !== StripeRefundRecoveryIntent::class
            || (int) $tx->reference_id !== (int) $intent->id || $tx->idempotency_key !== self::PREFIX.$o['deposit_id']
            || ($body['financial'] ?? null) !== $this->financial((int) $tx->id)
            || bccomp(bcsub($body['before'] ?? '0', $body['after'] ?? '0', 2), $o['amount'], 2) !== 0) {
            $this->refuse('stripe_refund_replay_unproven');
        }

        $this->assertRecoveryAccounting($tx, $o, $body);

        return ['recovered' => false, 'replayed' => true, 'financial_transaction_id' => (int) $tx->id, 'receipt_uuid' => $receipt->uuid];
    }

    private function assertRecoveryAccounting(FinancialTransaction $tx, array $o, array $body): void
    {
        if ((int) $tx->user_id !== $o['user_id'] || (int) $tx->wallet_id !== $o['wallet_id']
            || strtolower($tx->currency->value) !== $o['currency'] || $tx->reversed_by !== null || $tx->reversed_at !== null
            || bccomp((string) $tx->amount, $o['amount'], 2) !== 0 || bccomp((string) $tx->fee, '0', 2) !== 0) {
            $this->refuse('stripe_refund_replay_accounting_unproven');
        }
        $entries = LedgerEntry::withTrashed()->where('financial_transaction_id', $tx->id)->orderBy('id')->lockForUpdate()->get();
        $accounts = LedgerAccount::withTrashed()->whereIn('id', $entries->pluck('ledger_account_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($entries->count() !== 2) {
            $this->refuse('stripe_refund_replay_accounting_unproven');
        }
        $sides = [];
        foreach ($entries as $e) {
            $account = $accounts->get($e->ledger_account_id);
            $liability = $e->type === LedgerEntryType::Debit;
            $code = LedgerPostingService::accountCodeFor($liability ? WalletService::ACCOUNT_PLAYER_LIABILITY : WalletService::ACCOUNT_SYSTEM_CASH, $tx->currency);
            if ($e->trashed() || ! $e->posted_at || ! $account || $account->trashed() || $account->currency !== $tx->currency
                || $account->code !== $code || $e->currency !== $tx->currency || bccomp((string) $e->amount, $o['amount'], 2) !== 0
                || (int) $e->wallet_id !== $o['wallet_id']
                || ($liability && bccomp((string) $e->balance_after, $body['after'], 2) !== 0)) {
                $this->refuse('stripe_refund_replay_accounting_unproven');
            }
            $sides[] = $e->type->value;
        }
        sort($sides);
        if ($sides !== ['credit', 'debit']) {
            $this->refuse('stripe_refund_replay_accounting_unproven');
        }
    }

    private function financial(int $id): array
    {
        return ['transaction' => (array) DB::table('financial_transactions')->where('id', $id)->lockForUpdate()->first(),
            'entries' => DB::table('ledger_entries')->where('financial_transaction_id', $id)->orderBy('id')->lockForUpdate()->get()->map(fn ($r) => (array) $r)->all()];
    }

    private function source(array $source, array $o, ?int $id = null): void
    {
        if ($id !== null) {
            $row = DB::table(StripeDepositFollowupInbox::TABLE)->lockForUpdate()->find($id);
            if (! $row || $row->account_scope !== $o['account_scope'] || $row->configuration_hash !== $o['configuration_hash']
                || app(StripeDepositFollowupInbox::class)->read($row) !== $source) {
                $this->refuse();
            }
        }
        if (! in_array($source['event_type'] ?? null, ['refund.created', 'refund.updated'], true)
            || data_get($source, 'payload.livemode') !== $o['live']) {
            $this->refuse();
        }
        app(StripeRefundRecoveryLookup::class)->refund(data_get($source, 'payload.data.object'), $o);
    }

    private function insert(string $table, array $header, array $body): int
    {
        $bytes = json_encode(compact('header', 'body'), JSON_THROW_ON_ERROR);

        return (int) DB::table($table)->insertGetId($header + ['response_cipher' => Crypt::encryptString($bytes), 'response_hash' => hash('sha256', $bytes)]);
    }

    private function read(object $row): array
    {
        try {
            $bytes = Crypt::decryptString($row->response_cipher);
            $value = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            if (! hash_equals($row->response_hash, hash('sha256', $bytes)) || ! is_array($value['header'] ?? null) || ! is_array($value['body'] ?? null)) {
                $this->refuse();
            }
            $header = (array) $row;
            unset($header['id'], $header['response_cipher'], $header['response_hash']);
            if (array_keys($header) !== array_keys($value['header'])) {
                // SQL column order need not match insert order; compare exact key sets and typed scalar values instead.
                $a = array_keys($header);
                $b = array_keys($value['header']);
                sort($a);
                sort($b);
                if ($a !== $b) {
                    $this->refuse();
                }
            }
            foreach ($header as $key => $v) {
                if (($v === null) !== ($value['header'][$key] === null) || (string) $v !== (string) $value['header'][$key]) {
                    $this->refuse();
                }
            }

            return $value['body'];
        } catch (\Throwable) {
            $this->refuse('stripe_refund_custody_unproven');
        }
    }

    private function identity(array $o): string
    {
        // Account-scoped, deliberately NOT configuration-scoped: a key rotation cannot reclaim a refund.
        return hash('sha256', json_encode([$o['account_scope'], $o['refund']], JSON_THROW_ON_ERROR));
    }

    private function fresh(int $observed): void
    {
        if ($observed > now()->timestamp || now()->timestamp - $observed > 300) {
            $this->refuse('stripe_refund_receipt_stale');
        }
    }

    private function authorize(?User $actor): void
    {
        app(StripeRefundRecoveryAccess::class)->authorize($actor);
        if (! config('payment.refund_recovery.stripe.enabled', false)) {
            $this->refuse('stripe_refund_recovery_disabled');
        }
    }

    private function refuse(string $code = 'stripe_refund_recovery_unproven'): never
    {
        throw FinancialException::withCode($code, 'Confirmed-refund recovery requires intact original capture, signed source, independent financial authority and exact compensation custody. No negative balance, partial collection or provider refund request is authorized.');
    }
}
