<?php

namespace Tests\Unit;

use App\Enums\LifecycleCampaign;
use App\Enums\NotificationEventType;
use PHPUnit\Framework\TestCase;

/**
 * Every lifecycle campaign sends as a notification event type of its own.
 *
 * This mapping used to name five cases that did not exist on NotificationEventType, so
 * `LifecycleCampaign::eventType()` threw `Error: Undefined constant` the first time a campaign
 * tried to compose a message — the feature could never have sent one. PHPStan had that failure
 * recorded in the baseline as known debt, which is exactly why it survived: a frozen entry is a
 * note, not a gate. These assertions are the gate.
 */
class LifecycleCampaignEventTypeTest extends TestCase
{
    public function test_every_campaign_resolves_to_a_case_that_exists(): void
    {
        foreach (LifecycleCampaign::cases() as $campaign) {
            $eventType = $campaign->eventType();

            $this->assertContains(
                $eventType,
                NotificationEventType::cases(),
                $campaign->name.' must map to a real NotificationEventType case.',
            );
        }
    }

    public function test_campaigns_do_not_share_an_event_type(): void
    {
        $values = array_map(
            static fn (LifecycleCampaign $campaign): string => $campaign->eventType()->value,
            LifecycleCampaign::cases(),
        );

        $this->assertSame(
            $values,
            array_values(array_unique($values)),
            'Each campaign gets its own event type so a player can opt out of one without silencing the rest.',
        );
    }

    public function test_no_campaign_can_be_silenced_by_a_mandatory_floor(): void
    {
        // Marketing that survives an opt-out is a responsible-gaming failure, not a spam complaint.
        foreach (LifecycleCampaign::cases() as $campaign) {
            $this->assertFalse(
                $campaign->eventType()->isMandatory(),
                $campaign->name.' is marketing and must remain opt-outable.',
            );
            $this->assertTrue($campaign->isPromotional(), $campaign->name.' is a promotional campaign.');
        }
    }

    public function test_the_new_values_fit_the_column(): void
    {
        // notifications.event_type and notification_preferences.event_type are string(32).
        foreach (NotificationEventType::cases() as $case) {
            $this->assertLessThanOrEqual(32, strlen($case->value), $case->name.' value overflows the column.');
        }
    }
}
