<?php

declare(strict_types=1);

namespace Tests\Unit\Privacy;

use App\Enums\PrivacyRequestStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The lifecycle table, tested as a table.
 *
 * WHY THIS IS A UNIT TEST AND NOT A FEATURE TEST. The transition table is the
 * whole policy: if `Received -> Completed` were allowed, a staff member could
 * close a request without ever writing the answer, and no amount of controller
 * testing would notice. So every pair is enumerated here, in both directions,
 * with no database in the way.
 *
 * The table is written out literally rather than derived from the enum: a test
 * that asked allowedTransitions() what it allows would agree with any change.
 */
final class PrivacyRequestStatusTransitionsTest extends TestCase
{
    /**
     * The intended table, written by hand.
     *
     * @return array<string, list<string>>
     */
    private static function expected(): array
    {
        return [
            'pending_verification' => ['received', 'expired', 'cancelled'],
            'received' => ['in_review', 'needs_information', 'rejected', 'expired', 'cancelled'],
            'in_review' => ['needs_information', 'in_progress', 'completed', 'rejected', 'cancelled'],
            'needs_information' => ['in_review', 'expired', 'cancelled'],
            'in_progress' => ['needs_information', 'completed', 'rejected', 'cancelled'],
            'completed' => [],
            'rejected' => [],
            'expired' => [],
            'cancelled' => [],
        ];
    }

    public function test_the_enum_still_has_exactly_the_nine_documented_states(): void
    {
        $this->assertSame(
            array_keys(self::expected()),
            PrivacyRequestStatus::values(),
            'A state was added or removed without updating the lifecycle table.',
        );
    }

    public function test_every_state_transitions_to_exactly_what_the_table_says(): void
    {
        foreach (self::expected() as $from => $targets) {
            $status = PrivacyRequestStatus::from($from);

            $this->assertSame(
                $targets,
                array_map(static fn (PrivacyRequestStatus $s): string => $s->value, $status->allowedTransitions()),
                "[{$from}] does not transition to the documented set.",
            );
        }
    }

    public function test_no_state_transitions_to_itself(): void
    {
        foreach (PrivacyRequestStatus::cases() as $status) {
            $this->assertFalse($status->canTransitionTo($status), "[{$status->value}] allows a self-transition.");
        }
    }

    public function test_every_forbidden_pair_is_refused(): void
    {
        $expected = self::expected();

        foreach (PrivacyRequestStatus::cases() as $from) {
            foreach (PrivacyRequestStatus::cases() as $to) {
                $allowed = in_array($to->value, $expected[$from->value], true);

                $this->assertSame(
                    $allowed,
                    $from->canTransitionTo($to),
                    "[{$from->value}] -> [{$to->value}] should be ".($allowed ? 'allowed' : 'refused').'.',
                );
            }
        }
    }

    public function test_terminal_states_lead_nowhere(): void
    {
        foreach (PrivacyRequestStatus::cases() as $status) {
            if (! $status->isTerminal()) {
                continue;
            }

            $this->assertSame([], $status->allowedTransitions(), "[{$status->value}] is terminal but has exits.");
            $this->assertFalse($status->isOpen());
        }

        $this->assertSame(
            ['completed', 'rejected', 'expired', 'cancelled'],
            array_values(array_map(
                static fn (PrivacyRequestStatus $s): string => $s->value,
                array_filter(PrivacyRequestStatus::cases(), static fn (PrivacyRequestStatus $s): bool => $s->isTerminal()),
            )),
        );
    }

    public function test_the_clock_can_only_start_from_a_non_pending_state(): void
    {
        $this->assertFalse(PrivacyRequestStatus::PendingVerification->countsAsReceived());
        $this->assertFalse(PrivacyRequestStatus::Expired->countsAsReceived());
        $this->assertTrue(PrivacyRequestStatus::Received->countsAsReceived());
        $this->assertTrue(PrivacyRequestStatus::InReview->countsAsReceived());
    }

    public function test_only_a_request_somebody_can_still_work_is_actionable(): void
    {
        $actionable = array_values(array_map(
            static fn (PrivacyRequestStatus $s): string => $s->value,
            array_filter(PrivacyRequestStatus::cases(), static fn (PrivacyRequestStatus $s): bool => $s->isActionable()),
        ));

        $this->assertSame(['received', 'in_review', 'needs_information', 'in_progress'], $actionable);
    }

    public function test_a_verified_request_can_never_be_completed_from_receipt(): void
    {
        // The single most important refusal in the table: closing a request
        // without acknowledging it would leave a statutory deadline unmet with a
        // green tick next to it.
        $this->assertFalse(PrivacyRequestStatus::Received->canTransitionTo(PrivacyRequestStatus::Completed));
        $this->assertFalse(PrivacyRequestStatus::PendingVerification->canTransitionTo(PrivacyRequestStatus::InReview));
        $this->assertFalse(PrivacyRequestStatus::NeedsInformation->canTransitionTo(PrivacyRequestStatus::Completed));
    }

    /**
     * @param  list<string>  $from
     */
    #[DataProvider('reachabilityProvider')]
    public function test_completion_is_reachable_only_through_work(string $terminal, array $from): void
    {
        $sources = array_values(array_map(
            static fn (PrivacyRequestStatus $s): string => $s->value,
            array_filter(PrivacyRequestStatus::cases(), static fn (PrivacyRequestStatus $s): bool => $s->canTransitionTo(PrivacyRequestStatus::from($terminal))),
        ));

        $this->assertSame($from, $sources);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function reachabilityProvider(): array
    {
        return [
            'completed' => ['completed', ['in_review', 'in_progress']],
            'rejected' => ['rejected', ['received', 'in_review', 'in_progress']],
            'expired' => ['expired', ['pending_verification', 'received', 'needs_information']],
            'cancelled' => ['cancelled', ['pending_verification', 'received', 'in_review', 'needs_information', 'in_progress']],
            'received' => ['received', ['pending_verification']],
            'in_review' => ['in_review', ['received', 'needs_information']],
        ];
    }
}
