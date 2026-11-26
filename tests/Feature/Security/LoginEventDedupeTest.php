<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\LoginEventOutcome;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Security\LoginHistory\LoginEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The same successful login can arrive twice (framework Login event + API token
 * issuance). Success is collapsed inside a short window; failures never are.
 */
final class LoginEventDedupeTest extends TestCase
{
    use RefreshDatabase;

    private function request(string $ip = '203.0.113.5'): Request
    {
        return Request::create('/api/v1/auth/login', 'POST', [], [], [], [
            'REMOTE_ADDR' => $ip,
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/125.0.0.0 Safari/537.36',
        ]);
    }

    public function test_duplicate_success_inside_the_window_is_recorded_once(): void
    {
        config(['rewards.login_history.dedupe_window_seconds' => 5]);
        $member = User::factory()->create();
        $recorder = app(LoginEventRecorder::class);

        $first = $recorder->recordFromRequest($this->request(), $member, (string) $member->email, LoginEventOutcome::Success);
        $second = $recorder->recordFromRequest($this->request(), $member, (string) $member->email, LoginEventOutcome::Success);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, LoginEvent::query()->where('member_id', $member->getKey())->count());
    }

    public function test_failed_attempts_are_never_collapsed(): void
    {
        config(['rewards.login_history.dedupe_window_seconds' => 5]);
        $recorder = app(LoginEventRecorder::class);

        $recorder->recordFromRequest($this->request(), null, 'someone@example.com', LoginEventOutcome::Failed);
        $recorder->recordFromRequest($this->request(), null, 'someone@example.com', LoginEventOutcome::Failed);

        $this->assertSame(2, LoginEvent::query()->where('outcome', LoginEventOutcome::Failed->value)->count());
    }

    public function test_success_from_a_different_ip_is_a_separate_event(): void
    {
        config(['rewards.login_history.dedupe_window_seconds' => 5]);
        $member = User::factory()->create();
        $recorder = app(LoginEventRecorder::class);

        $recorder->recordFromRequest($this->request('203.0.113.5'), $member, (string) $member->email, LoginEventOutcome::Success);
        $recorder->recordFromRequest($this->request('203.0.113.6'), $member, (string) $member->email, LoginEventOutcome::Success);

        $this->assertSame(2, LoginEvent::query()->where('member_id', $member->getKey())->count());
    }

    public function test_window_of_zero_disables_collapsing(): void
    {
        config(['rewards.login_history.dedupe_window_seconds' => 0]);
        $member = User::factory()->create();
        $recorder = app(LoginEventRecorder::class);

        $recorder->recordFromRequest($this->request(), $member, (string) $member->email, LoginEventOutcome::Success);
        $recorder->recordFromRequest($this->request(), $member, (string) $member->email, LoginEventOutcome::Success);

        $this->assertSame(2, LoginEvent::query()->where('member_id', $member->getKey())->count());
    }
}
