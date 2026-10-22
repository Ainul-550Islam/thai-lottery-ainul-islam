<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Canonical positive, PHP-representable keys at financial routing boundaries.
 * Do not authorize a caller here: this prevents lossy key coercion, not spoofed
 * ownership of a well-formed key. Policies and locked persisted state still apply.
 */
final class FinancialRecordIdentifier
{
    public static function parse(mixed $value, string $errorCode = 'financial_identifier_invalid'): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value)) {
            $maximum = (string) PHP_INT_MAX;
            $length = strlen($value);
            if ($length > 0 && $length <= strlen($maximum)
                && preg_match('/\A[1-9][0-9]*\z/', $value) === 1
                && ($length < strlen($maximum) || strcmp($value, $maximum) <= 0)) {
                return (int) $value;
            }
        }

        // Never stringify an object or echo an untrusted identifier in an error.
        throw FinancialException::withCode(
            $errorCode,
            'A financial record identifier must be a canonical positive integer within the supported integer range.',
        );
    }

    public static function fromModel(Model $model, string $errorCode = 'financial_entity_not_persisted'): int
    {
        // getKey()/getAttribute() applies Eloquent's implicit integer primary-key
        // cast first. Validate the CURRENT raw attribute, not an original/stale
        // snapshot and not a value already rounded, truncated or exponent-cast.
        $attributes = $model->getAttributes();

        $id = self::parse($attributes[$model->getKeyName()] ?? null, $errorCode);
        self::assertRoutingContext($model);

        return $id;
    }

    /** The integrated services route through fixed tables on the default writer. */
    private static function assertRoutingContext(Model $model): void
    {
        $table = match (true) {
            $model instanceof Wallet => 'wallets',
            $model instanceof User => 'users',
            $model instanceof Deposit => 'deposits',
            $model instanceof Withdrawal => 'withdrawals',
            $model instanceof FinancialTransaction => 'financial_transactions',
            default => null,
        };
        if ($table === null || $model->getTable() !== $table || $model->getKeyName() !== 'id') {
            throw FinancialException::withCode(
                'financial_entity_routing_invalid',
                'A financial model must retain its canonical aggregate table and primary key.',
            );
        }

        $writer = DB::connection();
        $declared = $model->getConnectionName();
        // Reject aliases, unknown connections and read-suffixed names BEFORE
        // resolving them: validation must not connect to another store.
        if (($declared !== null && $declared !== $writer->getName())
            || $model->getConnection() !== $writer) {
            throw FinancialException::withCode(
                'financial_entity_connection_invalid',
                'A financial model must belong to the current canonical financial writer.',
            );
        }
    }
}
