<?php

declare(strict_types=1);

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A saved operator quick reply.
 *
 * ============================================================================
 * THIS MODEL DOES NOT SEND ANYTHING
 * ============================================================================
 * There is no path from a canned response to a visitor. It is content an
 * agent inserts into their composer and then chooses to send. The absence of
 * any "auto-reply on new conversation" wiring is deliberate: an automatic
 * greeting that claims a human will be with you shortly, sent by a machine
 * that cannot know whether one is on shift, is exactly the kind of confident
 * lie this codebase refuses elsewhere. When no agent is online the widget
 * says so plainly (see ChatAvailabilityService).
 *
 * ============================================================================
 * @property int             $id
 * @property string          $key
 * @property string          $title
 * @property string          $body
 * @property string|null     $category
 * @property array<int, string>|null $placeholders
 * @property string          $locale
 * @property int             $sort_order
 * @property bool            $enabled
 * @property int|null        $updated_by_user_id
 */
class ChatCannedResponse extends Model
{
    /** @var string */
    protected $table = 'chat_canned_responses';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'title',
        'body',
        'category',
        'placeholders',
        'locale',
        'sort_order',
        'enabled',
        'updated_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'placeholders' => 'array',
            'enabled' => 'boolean',
            'sort_order' => 'integer',
            'updated_by_user_id' => 'integer',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by_user_id');
    }

    // ========================================================================
    // Scopes
    // ========================================================================

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * The picker read: enabled, for a locale, in display order.
     *
     * Falls back to the platform's primary locale through the service, not
     * here — a scope that silently widened its own filter would make the
     * "no canned responses for this language" state impossible to observe.
     */
    public function scopeForLocale(Builder $query, string $locale): Builder
    {
        return $query->where('locale', $locale);
    }

    public function scopeInDisplayOrder(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('title');
    }

    // ========================================================================
    // Derived facts
    // ========================================================================

    /**
     * Whether a substitution was configured for this token.
     *
     * The console uses this to decide which fields to prompt for, so a
     * placeholder declared in the row but never listed is visibly a data
     * problem rather than a silent blank left in a sent message.
     */
    public function hasPlaceholder(string $token): bool
    {
        $placeholders = $this->placeholders ?? [];

        return in_array($token, $placeholders, true);
    }

    /**
     * The response with its declared placeholders filled from an explicit
     * map.
     *
     * Only tokens DECLARED on this row are substituted, and any that the
     * caller did not supply are left visible as `{token}` rather than
     * blanked. A missing value therefore shows up in the composer where the
     * agent can see it and fix it — instead of producing a sentence with a
     * hole in it that gets sent to a customer.
     *
     * @param  array<string, string>  $values
     */
    public function render(array $values = []): string
    {
        $body = (string) $this->body;
        $declared = $this->placeholders ?? [];

        foreach ($declared as $token) {
            if (! is_string($token) || $token === '') {
                continue;
            }

            if (! array_key_exists($token, $values)) {
                continue;
            }

            $body = str_replace('{'.$token.'}', (string) $values[$token], $body);
        }

        return $body;
    }
}
