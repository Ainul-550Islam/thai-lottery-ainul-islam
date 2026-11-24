<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One operator override of a whitelisted configuration key.
 *
 * The row is deliberately dumb: a key, a string value and who last wrote it. All the judgement —
 * which keys may be overridden, what type they are, what bounds apply, whether a money pair is
 * coherent — lives in `App\Services\Admin\SystemSettingService` and the catalogue in
 * `config/admin.php`. A model that validated would be a second place to look for the rule.
 *
 * @property int $id
 * @property string $key
 * @property string $value
 * @property string $value_type
 * @property int|null $updated_by
 */
class SystemSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'value_type',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'updated_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
