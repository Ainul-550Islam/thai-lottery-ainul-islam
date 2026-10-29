<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\SecurityEventType;
use App\Enums\SecurityRiskLevel;
use App\Models\SecurityEvent;
use App\Services\Bot\BotSecurityEventRecorder;
use App\Services\Bot\DTOs\BotDecisionData;
use App\Services\Bot\Enums\BotDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use LogicException;
use Tests\TestCase;

final class BotSecurityEventRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_observation_persists_once_with_a_review_floor_and_without_request_secrets(): void
    {
        config(['bot.audit.enabled' => true, 'bot.audit.min_score' => 20]);
        $request = Request::create('/login', 'POST', ['password' => 'not-to-be-logged'], [], [], [
            'REMOTE_ADDR' => '198.51.100.1',
        ]);
        $assessment = new BotDecisionData(25, BotDecision::Allow, ['TEST_SIGNAL'], BotDecision::Challenge, 25, 'log');
        $recorder = app(BotSecurityEventRecorder::class);

        $first = $recorder->record($request, $assessment);
        $retry = $recorder->record($request, $assessment);

        self::assertNotNull($first);
        self::assertNotNull($retry);
        self::assertSame($first->id, $retry->id);
        self::assertSame(1, SecurityEvent::query()->count());
        self::assertSame(SecurityEventType::BotSuspected, $first->event_type);
        self::assertSame(SecurityRiskLevel::Medium, $first->risk_level);
        self::assertNull($first->ip_address);
        self::assertStringNotContainsString('not-to-be-logged', json_encode($first->payload, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('198.51.100.1', json_encode($first->payload, JSON_THROW_ON_ERROR));

        $secondRequest = Request::create('/login', 'POST');
        $second = $recorder->record($secondRequest, $assessment);
        self::assertNotNull($second);
        self::assertNotSame($first->id, $second->id);
        self::assertSame(2, SecurityEvent::query()->count());
    }

    public function test_below_threshold_and_enforced_decisions_are_not_falsely_recorded(): void
    {
        config(['bot.audit.enabled' => true, 'bot.audit.min_score' => 40]);
        $recorder = app(BotSecurityEventRecorder::class);
        $request = Request::create('/login', 'POST');
        $belowThreshold = new BotDecisionData(10, BotDecision::Allow, ['TEST_SIGNAL'], BotDecision::Allow, 10, 'log');
        self::assertNull($recorder->record($request, $belowThreshold));
        self::assertSame(0, SecurityEvent::query()->count());

        $enforced = new BotDecisionData(90, BotDecision::Block, ['TEST_SIGNAL'], BotDecision::Block, 90, 'enforce');
        try {
            $recorder->record(Request::create('/login', 'POST'), $enforced);
            self::fail('The observation-only recorder must not claim an enforced action.');
        } catch (LogicException) {
            self::assertSame(0, SecurityEvent::query()->count());
        }
    }
}
