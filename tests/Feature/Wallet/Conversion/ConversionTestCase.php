<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Conversion;

use App\Contracts\Wallet\WalletConversionServiceContract;
use App\DTOs\Wallet\ConversionAuditContextData;
use App\DTOs\Wallet\ConversionRequestData;
use App\Enums\Currency;
use App\Enums\KycStatus;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletStatus;
use App\Enums\WalletType;
use App\Models\KycDocument;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletConversion;
use App\Services\Wallet\Conversion\ConversionFeeCalculator;
use App\Services\Wallet\Conversion\ConversionQuoteService;
use App\Services\Wallet\Conversion\WalletConversionService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use App\Support\Admin\AdminAccess;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Shared fixtures for the Cash <-> Win conversion suite.
 *
 * WHY THE REAL SERVICE IS EXERCISED AND NOT A DOUBLE
 * The claims this suite makes are financial: a fee of exactly 1%, a balance that
 * moves by exactly the right amount, a ledger that balances, an idempotent retry
 * that does not move money twice. A mocked wallet would let every one of those
 * pass while the real engine did something else. So the tests boot the real
 * application, talk to a real (truncated) test database, and call the real
 * service through the container binding the application itself uses.
 *
 * WHY THE FIXTURES ARE BUILT THROUGH THE DOMAIN, NOT INSERTED BY HAND
 * Wallets are opened by WalletService — the same call the deposit lane makes —
 * so the rows carry whatever the platform really writes (status, currency, the
 * version column). Rows fabricated with raw inserts could carry a shape the
 * engine never produces, and a test that passes against an impossible row proves
 * nothing.
 */
abstract class ConversionTestCase extends TestCase
{
    use DatabaseTruncation;

    /**
     * A member who is allowed to convert: active, and identity-verified the way
     * the platform really proves it — a verified document, which is what
     * User::kycStatus() reads. Setting a column would not survive the first
     * refactor of that accessor; creating the document is the behaviour the
     * member actually experiences.
     */
    protected function makeVerifiedMember(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        KycDocument::query()->create([
            'user_id' => $user->getKey(),
            'document_type' => 'national_id',
            'document_number' => 'TEST-'.strtoupper(bin2hex(random_bytes(4))),
            'file_path' => 'kyc/test-'.$user->getKey().'.png',
            'mime_type' => 'image/png',
            'file_size' => 1024,
            'status' => KycStatus::Verified,
            'verified_at' => now(),
        ]);

        $user->unsetRelation('kycDocuments');

        return $user;
    }

    /**
     * A member with money to convert: 1000.00 in Cash and an empty Win wallet.
     *
     * @return array{user: User, cash: Wallet, win: Wallet}
     */
    protected function makeFundedMember(string $cashBalance = '1000.00'): array
    {
        $user = $this->makeVerifiedMember();

        // `status` and `balance` are deliberately NOT mass-assignable on Wallet:
        // a wallet's status and its money are moved by the finance services, never
        // by whatever array happens to be passed to create(). The fixture honours
        // that boundary — it creates the row through fillable attributes and then
        // sets the funded state explicitly, the same way the finance layer does.
        $cash = Wallet::query()->create([
            'user_id' => $user->getKey(),
            'type' => WalletType::Primary,
            'currency' => Currency::THB,
        ]);

        $cash->forceFill([
            'status' => WalletStatus::Active,
            'balance' => $cashBalance,
        ])->save();

        $win = Wallet::query()->create([
            'user_id' => $user->getKey(),
            'type' => WalletType::Win,
            'currency' => Currency::THB,
        ]);

        $win->forceFill([
            'status' => WalletStatus::Active,
            'balance' => '0.00',
        ])->save();

        return ['user' => $user, 'cash' => $cash, 'win' => $win];
    }

    /**
     * A panel operator: identity-verified AND holding a role that carries the
     * wallet permissions. The role is created with Spatie's findOrCreate rather
     * than assumed to be seeded, because truncation between tests can leave the
     * role tables empty and a fixture that depends on someone else's seed order
     * is a test that fails for the wrong reason.
     */
    protected function makeOperator(): User
    {
        $user = $this->makeVerifiedMember();

        $role = Role::findOrCreate('super-admin', 'web');

        // The catalogue is created too: a test that depends on permissions the
        // seeder happens to have left behind is a test that fails on a clean run.
        Permission::findOrCreate(AdminAccess::MANAGE_WALLET, 'web');

        $user->assignRole($role);

        return $user;
    }

    protected function service(): WalletConversionServiceContract
    {
        return app(WalletConversionServiceContract::class);
    }

    protected function quoteFor(
        User $user,
        string $amount = '100.00',
        WalletConversionDirection $direction = WalletConversionDirection::CashToWin,
        string $idempotencyKey = 'test-quote-key-0000000001',
    ) {
        return $this->service()->quote($this->request(
            user: $user,
            amount: $amount,
            direction: $direction,
            idempotencyKey: $idempotencyKey,
        ));
    }

    protected function request(
        User $user,
        string $amount = '100.00',
        WalletConversionDirection $direction = WalletConversionDirection::CashToWin,
        string $idempotencyKey = 'test-convert-key-00000001',
        ?string $quoteReference = null,
        WalletConversionChannel $channel = WalletConversionChannel::Api,
        ?ConversionAuditContextData $auditContext = null,
    ): ConversionRequestData {
        return new ConversionRequestData(
            direction: $direction,
            amount: $amount,
            currency: Currency::THB,
            channel: $channel,
            userId: (int) $user->getKey(),
            idempotencyKey: $idempotencyKey,
            quoteReference: $quoteReference,
            auditContext: $auditContext,
        );
    }

    protected function convert(
        User $user,
        string $amount = '100.00',
        WalletConversionDirection $direction = WalletConversionDirection::CashToWin,
        string $idempotencyKey = 'test-convert-key-00000001',
        bool $withQuote = true,
    ) {
        $quoteReference = null;

        if ($withQuote) {
            $quote = $this->quoteFor(
                $user,
                $amount,
                $direction,
                'quote-'.$idempotencyKey,
            );

            $quoteReference = $quote->reference;
        }

        return $this->service()->convert($this->request(
            user: $user,
            amount: $amount,
            direction: $direction,
            idempotencyKey: $idempotencyKey,
            quoteReference: $quoteReference,
        ));
    }

    protected function balanceOf(Wallet $wallet): string
    {
        return bcadd((string) $wallet->fresh()->balance, '0', 2);
    }

    /**
     * The net ledger effect recorded against one wallet's own liability account.
     *
     * Why the wallet's OWN account and not every row that mentions it: double
     * entry writes a wallet row and its counterpart per leg, so summing all of
     * them nets to zero and would hide a missing posting rather than catch it.
     */
    protected function walletLedgerDelta(WalletConversion $conversion, int $walletId): string
    {
        $delta = '0.00';

        $entries = DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->where('ledger_entries.reference_type', '=', 'wallet_conversion')
            ->where('ledger_entries.reference_id', '=', (int) $conversion->getKey())
            ->where('ledger_entries.wallet_id', '=', $walletId)
            ->where('ledger_accounts.code', 'like', '2000%')
            ->select(['ledger_entries.type', 'ledger_entries.amount'])
            ->get();

        foreach ($entries as $entry) {
            $delta = ((string) $entry->type) === 'debit'
                ? bcsub($delta, (string) $entry->amount, 2)
                : bcadd($delta, (string) $entry->amount, 2);
        }

        return $delta;
    }

    /**
     * The amount that landed in the fee-revenue account for one conversion.
     */
    protected function feeRevenuePosted(WalletConversion $conversion): string
    {
        $total = '0.00';

        $entries = DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->where('ledger_entries.reference_type', '=', 'wallet_conversion')
            ->where('ledger_entries.reference_id', '=', (int) $conversion->getKey())
            ->where('ledger_accounts.code', 'like', '4100%')
            ->select(['ledger_entries.type', 'ledger_entries.amount'])
            ->get();

        foreach ($entries as $entry) {
            $total = ((string) $entry->type) === 'debit'
                ? bcsub($total, (string) $entry->amount, 2)
                : bcadd($total, (string) $entry->amount, 2);
        }

        return $total;
    }

    /**
     * The whole ledger after a conversion: total debits must equal total credits.
     *
     * @return array{debits: string, credits: string, balanced: bool}
     */
    protected function ledgerIsBalanced(): array
    {
        $row = DB::table('ledger_entries')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) as debits")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) as credits")
            ->first();

        $debits = bcadd((string) ($row->debits ?? '0'), '0', 2);
        $credits = bcadd((string) ($row->credits ?? '0'), '0', 2);

        return [
            'debits' => $debits,
            'credits' => $credits,
            'balanced' => bccomp($debits, $credits, 2) === 0,
        ];
    }

    protected function calculator(): ConversionFeeCalculator
    {
        return app(ConversionFeeCalculator::class);
    }

    protected function quoteService(): ConversionQuoteService
    {
        return app(ConversionQuoteService::class);
    }

    protected function orchestrator(): WalletConversionService
    {
        return app(WalletConversionService::class);
    }
}
