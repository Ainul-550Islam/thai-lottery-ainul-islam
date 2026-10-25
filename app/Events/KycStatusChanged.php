<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\KycStatus;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A player's identity-verification standing moved to a state the player must be told about.
 *
 * NOT A BROADCAST. KYC outcomes reach the player as notifications (in-app, mail), never on a
 * socket channel; this event is the in-process envelope the notification listener consumes.
 *
 * WHERE IT COMES FROM
 * The KYC lane writes its verdicts in four places (users.kyc_status, kyc_verifications,
 * account_verifications and kyc_documents) through services this batch does not edit, and none
 * of them raised an event a notification could hang on. EventServiceProvider bridges the row
 * facts those services already write - the Eloquent `updated`/`created` model events - into this
 * one envelope, normalised to the public KycStatus vocabulary, so the listener has a single
 * source whichever service made the decision. Dispatched after the writing transaction commits.
 *
 * WHAT IT CARRIES
 * The user (by identifier when queued), the previous and current public status words, an
 * optional review note already written for the player's eyes, and the source table and row so
 * a listener can build an idempotency key. Never a document path, a national id or file content.
 */
final class KycStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly ?string $previousStatus,
        public readonly string $currentStatus,
        public readonly ?string $reason,
        public readonly string $sourceType,
        public readonly int $sourceId,
    ) {}

    /**
     * Normalise any of the lane's status vocabularies to the public KycStatus words.
     * Unknown words come back null so a caller can refuse to notify rather than guess.
     */
    public static function normalise(mixed $status): ?string
    {
        if ($status instanceof \BackedEnum) {
            $status = $status->value;
        }

        if (! is_string($status) || trim($status) === '') {
            return null;
        }

        $word = strtolower(trim($status));

        return match ($word) {
            'approved', 'verified' => KycStatus::Verified->value,
            'rejected', 'declined' => KycStatus::Rejected->value,
            'under_review', 'in_review', 'processing', 'reviewing' => KycStatus::UnderReview->value,
            'pending', 'submitted', 'uploaded' => KycStatus::Pending->value,
            'expired' => KycStatus::Expired->value,
            'unverified', 'not_submitted' => KycStatus::Unverified->value,
            default => null,
        };
    }
}
