<?php

declare(strict_types=1);

namespace Tests\Unit\Chat;

use App\Enums\Chat\ChatEventName;
use App\Models\Chat\ChatEvent;
use PHPUnit\Framework\TestCase;

/**
 * The ChatEventName enum and the ChatEvent model's string constants are two
 * spellings of the same vocabulary on purpose: the enum is what services
 * pass around (type-safe, typo-proof), the constants are what console
 * commands and migrations can reference without importing an enum.
 *
 * The danger of keeping two spellings is drift: someone adds an event to one
 * and forgets the other, and the omission is invisible until a report misses
 * a row. This test makes drift a build failure.
 */
final class ChatEventNameParityTest extends TestCase
{
    public function test_every_enum_case_has_a_matching_model_constant(): void
    {
        // Parity is by VALUE, not by name derivation: the enum case name and
        // the SCREAMING_SNAKE constant name are allowed to differ in shape
        // (ClaimedByVisitor vs CONVERSATION_CLAIMED_BY_VISITOR) as long as
        // they name the same wire value. Comparing values is therefore the
        // invariant that matters; comparing spellings would be brittle.
        $modelValues = $this->modelEventConstants();

        foreach (ChatEventName::cases() as $case) {
            $this->assertContains(
                $case->value,
                $modelValues,
                'ChatEvent has no constant whose value matches ChatEventName::'
                .$case->name." ('{$case->value}')",
            );
        }
    }

    public function test_every_model_constant_has_a_matching_enum_case(): void
    {
        $enumValues = array_column(ChatEventName::cases(), 'value');

        foreach ($this->modelEventConstants() as $constantName => $value) {
            $this->assertContains(
                $value,
                $enumValues,
                "ChatEvent::{$constantName} ('{$value}') has no ChatEventName case",
            );
        }
    }

    /**
     * The model's event vocabulary constants, excluding non-vocabulary ones.
     *
     * @return array<string, string>  constant name => value
     */
    private function modelEventConstants(): array
    {
        $reflection = new \ReflectionClass(ChatEvent::class);
        $constants = $reflection->getConstants();

        $skip = ['CREATED_AT', 'UPDATED_AT'];

        $out = [];

        foreach ($constants as $name => $value) {
            if (in_array($name, $skip, true) || ! is_string($value)) {
                continue;
            }

            $out[$name] = $value;
        }

        return $out;
    }

    public function test_enum_values_are_unique(): void
    {
        $values = array_column(ChatEventName::cases(), 'value');

        $this->assertSame($values, array_values(array_unique($values)));
    }

    public function test_values_helper_returns_every_case(): void
    {
        $this->assertSame(
            array_column(ChatEventName::cases(), 'value'),
            ChatEventName::values(),
        );
    }

    public function test_failure_signal_classification_is_stable(): void
    {
        $this->assertTrue(ChatEventName::AttachmentRejected->isFailureSignal());
        $this->assertTrue(ChatEventName::TranscriptFailed->isFailureSignal());
        $this->assertTrue(ChatEventName::RateLimited->isFailureSignal());
        $this->assertTrue(ChatEventName::AccessDenied->isFailureSignal());
        $this->assertTrue(ChatEventName::SlaBreached->isFailureSignal());

        // Operational facts that are NOT failures must stay out of the set:
        // an alert keyed on isFailureSignal() must not page on a normal close.
        $this->assertFalse(ChatEventName::ConversationClosed->isFailureSignal());
        $this->assertFalse(ChatEventName::MessageSent->isFailureSignal());
        $this->assertFalse(ChatEventName::ConversationClaimed->isFailureSignal());
    }

    public function test_human_initiated_classification_is_stable(): void
    {
        $this->assertTrue(ChatEventName::ConversationClaimed->isHumanInitiated());
        $this->assertTrue(ChatEventName::MessageSent->isHumanInitiated());

        $this->assertFalse(ChatEventName::IdleClosed->isHumanInitiated());
        $this->assertFalse(ChatEventName::RetentionRedacted->isHumanInitiated());
    }
}
