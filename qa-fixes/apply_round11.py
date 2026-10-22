#!/usr/bin/env python3
"""Round 11: SQLite lock contention is transient, the same class as a deadlock.

SQLite reports SQLITE_BUSY (5) and SQLITE_LOCKED (6) when a deferred transaction cannot
upgrade to a write lock. The purchase transaction already retries deadlocks and lock
timeouts (see BetPurchaseTransactionService). Those codes were not recognised on SQLite,
so a concurrent identical purchase failed instead of retrying and replaying.

Idempotent. Adds the two SQLite codes to BetPurchaseConcurrencyException::isLockFailure().
No retry budget, money check or idempotency rule is changed.
"""
from pathlib import Path

ROOT = Path('/home/user/thai-lottery-ainul-islam')
REL = 'app/Exceptions/BetPurchaseConcurrencyException.php'
CONST_ANCHOR = "    /** MySQL / MariaDB: duplicate entry for a unique key. */\n    public const SQLSTATE_DUPLICATE_ENTRY = 1062;\n"
CONST_NEW = CONST_ANCHOR + (
    "\n"
    "    /** SQLite: the database file is locked by another connection (SQLITE_BUSY). Transient. */\n"
    "    public const SQLITE_BUSY = 5;\n\n"
    "    /** SQLite: a table in the database is locked (SQLITE_LOCKED). Transient. */\n"
    "    public const SQLITE_LOCKED = 6;\n"
)
LOCK_OLD = "        return $code === self::SQLSTATE_DEADLOCK || $code === self::SQLSTATE_LOCK_TIMEOUT;"
LOCK_NEW = (
    "        return $code === self::SQLSTATE_DEADLOCK\n"
    "            || $code === self::SQLSTATE_LOCK_TIMEOUT\n"
    "            || $code === self::SQLITE_BUSY\n"
    "            || $code === self::SQLITE_LOCKED;"
)


TX_REL = 'app/Services/Betting/BetPurchaseTransactionService.php'
TX_OLD = "                fn (): BetPurchaseResult => $this->purchase($context),\n"
TX_NEW = "                fn (): BetPurchaseResult => $this->purchaseHoldingWriteLock($context),\n"
METHOD_ANCHOR = "    /**\n     * Re-check, under a shared draw lock, that the draw still accepts bets."
METHOD_NEW = (
    "    /**\n"
    "     * Run the purchase. On SQLite, take the write lock before the first read.\n"
    "     *\n"
    "     * SQLite ignores SELECT ... FOR UPDATE. A deferred transaction that reads first and\n"
    "     * then writes has to upgrade its lock, and SQLite refuses that upgrade immediately\n"
    "     * when a concurrent writer exists, so the busy timeout never applies. A write as the\n"
    "     * first statement takes the lock up front, so the busy timeout can wait for it. A\n"
    "     * zero-row UPDATE changes no data. MariaDB keeps its row locks and is not affected.\n"
    "     */\n"
    "    private function purchaseHoldingWriteLock(BetPurchaseContext $context): BetPurchaseResult\n"
    "    {\n"
    "        if (DB::connection()->getDriverName() === 'sqlite') {\n"
    "            DB::statement('UPDATE wallets SET id = id WHERE 1 = 0');\n"
    "        }\n\n"
    "        return $this->purchase($context);\n"
    "    }\n\n"
)

def apply_transaction_service():
    path = ROOT / TX_REL
    s = path.read_text()
    if 'purchaseHoldingWriteLock' in s:
        print(f'{TX_REL}: already applied')
        return
    assert s.count(TX_OLD) == 1, 'transaction closure anchor'
    assert s.count(METHOD_ANCHOR) == 1, 'method anchor'
    s = s.replace(TX_OLD, TX_NEW).replace(METHOD_ANCHOR, METHOD_NEW + METHOD_ANCHOR)
    path.write_text(s)
    print(f'{TX_REL}: applied')

def main():
    path = ROOT / REL
    s = path.read_text()
    if 'SQLITE_BUSY = 5' in s and 'self::SQLITE_BUSY' in s:
        print(f'{REL}: already applied')
    else:
        assert s.count(CONST_ANCHOR) == 1, 'constant anchor'
        assert s.count(LOCK_OLD) == 1, 'lock failure anchor'
        s = s.replace(CONST_ANCHOR, CONST_NEW).replace(LOCK_OLD, LOCK_NEW)
        path.write_text(s)
        print(f'{REL}: applied')
    apply_transaction_service()

if __name__ == '__main__':
    main()
