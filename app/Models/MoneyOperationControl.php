<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The current state of one runtime money control.
 *
 * This model is deliberately thin. Every rule about what a state means, who may
 * change it, how a change is audited and what happens when the row cannot be
 * read lives in `MoneyShutdownControlService`, because those rules have to hold
 * even when this row does not exist - and a model that is never instantiated
 * cannot enforce anything.
 *
 * The state constants are duplicated here on purpose: callers that only have a
 * row (a view, an audit query, a test assertion) must be able to interpret it
 * without importing the service, and a string literal compared in three places
 * is how a control acquires a fourth spelling nobody checked.
 *
 * @property int $id
 * @property string $control_key
 * @property string $state
 * @property string|null $previous_state
 * @property string|null $reason
 * @property int|null $changed_by_user_id
 * @property Carbon|null $changed_at
 * @property int $revision
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MoneyOperationControl extends Model
{
    /** Money movement is halted. The default, and the answer whenever state cannot be established. */
    public const STATE_CLOSED = 'closed';

    /** This control permits money movement. Every other gate still applies. */
    public const STATE_OPEN = 'open';

    /** The only two states a row may hold. Anything else is read as CLOSED. */
    public const STATES = [self::STATE_CLOSED, self::STATE_OPEN];

    protected $table = 'money_operation_controls';

    protected $fillable = [
        'control_key',
        'state',
        'previous_state',
        'reason',
        'changed_by_user_id',
        'changed_at',
        'revision',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
            'revision' => 'integer',
        ];
    }

    /**
     * Is this row in a state that permits money movement?
     *
     * Strict on both the value and its type: an unrecognised or mistyped state
     * answers false, so a corrupted row halts money rather than being
     * interpreted generously.
     */
    public function permitsMoney(): bool
    {
        return $this->state === self::STATE_OPEN;
    }
}
