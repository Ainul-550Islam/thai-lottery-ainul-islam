<?php

declare(strict_types=1);

namespace Tests\Unit\Ledger;

use App\Exceptions\FinancialException;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Services\Ledger\DoubleEntryLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * The ledger refuses to mix currencies or replay a posting AT WRITE TIME.
 *
 * WHY THIS IS NOT A DUPLICATE OF CurrencySeparatedIntegrityTest
 * -------------------------------------------------------------
 * That suite covers DETECTION: it corrupts persisted rows and proves the
 * reconciliation report goes red, so mixed-currency damage is visible after the
 * fact. This suite covers PREVENTION: it proves the damage cannot be written in
 * the first place. Detection without prevention means a corrupted ledger is
 * merely reported; prevention without detection means a bug that slips past the
 * write guard is never found. The two suites are the two halves of the same
 * control and neither replaces the other.
 *
 * WHY EVERY REFUSAL IS PAIRED WITH A "WROTE NOTHING" ASSERTION
 * ------------------------------------------------------------
 * An exception thrown halfway through a posting is worse than no exception at
 * all if a header row, a balance change or one side of an entry pair survives
 * it. "It refused" is only half the claim; the other half is that the ledger is
 * byte-for-byte unchanged, so every refusal test counts rows and re-reads
 * balances.
 *
 * The currency guard here is what makes the THB/BDT separation requirement
 * structural rather than procedural: a balance cannot hold two currencies
 * because an account cannot be posted to in a currency it was not opened in.
 */
final class CurrencyWriteGuardTest extends TestCase
{
    use RefreshDatabase;

    private DoubleEntryLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(DoubleEntryLedgerService::class);
    }

    /**
     * Balanced entries moving $amount from $debit into $credit.
     *
     * @return array<int, array{account_id: int, direction: string, amount: string}>
     */
    private function entries(LedgerAccount $debit, LedgerAccount $credit, string $amount): array
    {
        return [
            ['account_id' => $debit->id, 'direction' => 'DEBIT', 'amount' => $amount],
            ['account_id' => $credit->id, 'direction' => 'CREDIT', 'amount' => $amount],
        ];
    }

    private function ledgerRowCounts(): array
    {
        return [
            'transactions' => LedgerTransaction::query()->count(),
            'entries' => LedgerEntry::query()->count(),
        ];
    }

    private function assertWroteNothing(array $before): void
    {
        self::assertSame(
            $before,
            $this->ledgerRowCounts(),
            'A refused posting still wrote ledger rows. A half-applied financial mutation is unreconcilable.',
        );
    }

    // =====================================================================
    // §1 Unsupported currency.
    // =====================================================================

    public function test_an_unsupported_currency_is_refused_before_anything_is_written(): void
    {
        $asset = $this->ledger->getSystemAccount('GUARD-ASSET-XYZ', 'Asset', 'asset', 'THB');
        $liability = $this->ledger->getSystemAccount('GUARD-LIAB-XYZ', 'Liability', 'liability', 'THB');

        $before = $this->ledgerRowCounts();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported ledger transaction currency.');

        $this->ledger->postTransaction(
            referenceType: 'DEPOSIT_SETTLEMENT',
            referenceId: 'GUARD-UNSUPPORTED-1',
            currency: 'XYZ',
            entries: $this->entries($asset, $liability, '500.0000'),
        );
    }

    public function test_an_unsupported_currency_leaves_the_ledger_untouched(): void
    {
        // Separate test because expectException() ends the test at the throw,
        // so the "wrote nothing" half cannot be asserted in the same method.
        $asset = $this->ledger->getSystemAccount('GUARD-ASSET-XYZ2', 'Asset', 'asset', 'THB');
        $liability = $this->ledger->getSystemAccount('GUARD-LIAB-XYZ2', 'Liability', 'liability', 'THB');

        $before = $this->ledgerRowCounts();

        try {
            $this->ledger->postTransaction(
                referenceType: 'DEPOSIT_SETTLEMENT',
                referenceId: 'GUARD-UNSUPPORTED-2',
                currency: 'XYZ',
                entries: $this->entries($asset, $liability, '500.0000'),
            );

            self::fail('An unsupported currency was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertWroteNothing($before);
            self::assertSame(0, bccomp('0', (string) $asset->fresh()->current_balance, 4));
        }
    }

    // =====================================================================
    // §2 Two currencies can never share one balance.
    // =====================================================================

    public function test_an_account_opened_in_another_currency_refuses_the_posting(): void
    {
        $thbAsset = $this->ledger->getSystemAccount('GUARD-ASSET-THB', 'Asset THB', 'asset', 'THB');
        $bdtLiability = $this->ledger->getSystemAccount('GUARD-LIAB-BDT', 'Liability BDT', 'liability', 'BDT');

        $before = $this->ledgerRowCounts();

        try {
            $this->ledger->postTransaction(
                referenceType: 'PAYOUT',
                referenceId: 'GUARD-MISMATCH-1',
                currency: 'THB',
                entries: $this->entries($thbAsset, $bdtLiability, '500.0000'),
            );

            self::fail('A THB posting was accepted against a BDT account.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('does not match transaction currency', $exception->getMessage());
            self::assertStringContainsString($bdtLiability->code, $exception->getMessage(), 'The refusal does not name the offending account, so an operator cannot tell which side of the pair was wrong.');

            $this->assertWroteNothing($before);

            // The THB side was validated first, so the danger is a one-sided
            // posting: the asset debited and the liability never credited.
            self::assertSame(0, bccomp('0', (string) $thbAsset->fresh()->current_balance, 4), 'The matching-currency account was debited by a posting that was refused on its other side.');
            self::assertSame(0, bccomp('0', (string) $bdtLiability->fresh()->current_balance, 4));
        }
    }

    public function test_the_guard_refuses_the_wrong_currency_and_not_everything(): void
    {
        // A guard that refuses everything passes every negative test while the
        // ledger is simply broken. The positive control is what makes §2 above
        // mean something.
        $bdtAsset = $this->ledger->getSystemAccount('GUARD-ASSET-BDT-OK', 'Asset BDT', 'asset', 'BDT');
        $bdtLiability = $this->ledger->getSystemAccount('GUARD-LIAB-BDT-OK', 'Liability BDT', 'liability', 'BDT');

        $transaction = $this->ledger->postTransaction(
            referenceType: 'DEPOSIT_SETTLEMENT',
            referenceId: 'GUARD-MATCHED-1',
            currency: 'BDT',
            entries: $this->entries($bdtAsset, $bdtLiability, '500.0000'),
        );

        self::assertSame('POSTED', $transaction->status);
        self::assertCount(2, $transaction->entries);
        self::assertSame(0, bccomp('500.0000', (string) $bdtAsset->fresh()->current_balance, 4));
        self::assertSame(0, bccomp('500.0000', (string) $bdtLiability->fresh()->current_balance, 4));
    }

    public function test_a_user_balance_is_currency_scoped_so_one_account_holds_one_currency(): void
    {
        $thb = $this->ledger->getOrCreateUserAccount('GUARD-USER-1', 'THB');
        $bdt = $this->ledger->getOrCreateUserAccount('GUARD-USER-1', 'BDT');

        self::assertNotSame($thb->id, $bdt->id, 'One user account is shared across two currencies, so a THB and a BDT balance would be summed into one number.');
        self::assertNotSame($thb->code, $bdt->code);
        self::assertSame('THB', $thb->currency->value);
        self::assertSame('BDT', $bdt->currency->value);
        self::assertStringContainsString('THB', $thb->code, 'The account code does not carry the currency, so two same-user accounts are indistinguishable outside this table.');
        self::assertStringContainsString('BDT', $bdt->code);
    }

    // =====================================================================
    // §3 Duplicate delivery cannot double-credit.
    // =====================================================================

    public function test_a_replayed_idempotency_key_cannot_double_credit(): void
    {
        $asset = $this->ledger->getSystemAccount('GUARD-ASSET-REPLAY', 'Asset', 'asset', 'THB');
        $liability = $this->ledger->getSystemAccount('GUARD-LIAB-REPLAY', 'Liability', 'liability', 'THB');
        $entries = $this->entries($asset, $liability, '500.0000');

        $this->ledger->postTransaction(
            referenceType: 'DEPOSIT_SETTLEMENT',
            referenceId: 'GUARD-REPLAY-1',
            currency: 'THB',
            entries: $entries,
            idempotencyKey: 'DEP-PROVIDER-REF-1',
        );

        $afterFirst = $this->ledgerRowCounts();
        self::assertSame(1, $afterFirst['transactions']);

        // Providers retry: the second delivery is the normal case, not an
        // incident. The service does not pre-read the key, so the refusal comes
        // from the database's UNIQUE index - which is stronger, because it holds
        // under concurrency where a read-then-write check would race.
        try {
            $this->ledger->postTransaction(
                referenceType: 'DEPOSIT_SETTLEMENT',
                referenceId: 'GUARD-REPLAY-1',
                currency: 'THB',
                entries: $entries,
                idempotencyKey: 'DEP-PROVIDER-REF-1',
            );

            self::fail('A duplicate idempotency key posted a second time, so one provider delivery was credited twice.');
        } catch (QueryException) {
            self::assertSame($afterFirst, $this->ledgerRowCounts(), 'The refused replay still wrote rows.');
            self::assertSame(0, bccomp('500.0000', (string) $asset->fresh()->current_balance, 4), 'The replay changed the balance, so the player was credited twice for one payment.');
        }
    }

    public function test_two_different_payments_do_not_collide_on_the_idempotency_key(): void
    {
        // The mirror image of the replay test. A key that is too broad silently
        // swallows legitimate second payments, which is a fund-loss bug with the
        // opposite sign and no exception to catch.
        $asset = $this->ledger->getSystemAccount('GUARD-ASSET-DISTINCT', 'Asset', 'asset', 'THB');
        $liability = $this->ledger->getSystemAccount('GUARD-LIAB-DISTINCT', 'Liability', 'liability', 'THB');
        $entries = $this->entries($asset, $liability, '500.0000');

        $this->ledger->postTransaction('DEPOSIT_SETTLEMENT', 'GUARD-DISTINCT-1', 'THB', $entries, 'DEP-PROVIDER-REF-1');
        $this->ledger->postTransaction('DEPOSIT_SETTLEMENT', 'GUARD-DISTINCT-2', 'THB', $entries, 'DEP-PROVIDER-REF-2');

        self::assertSame(2, LedgerTransaction::query()->count());
        self::assertSame(0, bccomp('1000.0000', (string) $asset->fresh()->current_balance, 4), 'Two distinct provider references were treated as one payment.');
    }

    // =====================================================================
    // §4 Precision is never rounded away.
    // =====================================================================

    public function test_an_amount_carrying_unusable_precision_is_refused_rather_than_rounded(): void
    {
        $asset = $this->ledger->getSystemAccount('GUARD-ASSET-PRECISION', 'Asset', 'asset', 'THB');
        $liability = $this->ledger->getSystemAccount('GUARD-LIAB-PRECISION', 'Liability', 'liability', 'THB');

        $before = $this->ledgerRowCounts();

        try {
            $this->ledger->postTransaction(
                referenceType: 'DEPOSIT_SETTLEMENT',
                referenceId: 'GUARD-PRECISION-1',
                currency: 'THB',
                entries: $this->entries($asset, $liability, '500.0010'),
            );

            self::fail('A third-decimal amount was accepted against a two-decimal currency.');
        } catch (FinancialException $exception) {
            self::assertSame('money_precision_loss', $exception->errorCode());
            $this->assertWroteNothing($before);
        }
    }
}
