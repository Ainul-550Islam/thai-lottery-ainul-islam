<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\User;
use App\Services\Agent\AgentReportingService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Authenticated agent portal projection controller.
 *
 * The authenticated session resolves the agent owner. No route parameter,
 * hidden field, or client-supplied user ID is used to select an agent. This
 * controller is read-only: commission accrual, settlement, referral
 * attribution, and wallet credit remain in their canonical services.
 */
final class AgentPortalController extends Controller
{
    public function __construct(
        private readonly AgentReportingService $reporting,
    ) {
    }

    public function dashboard(Request $request): View
    {
        $agent = $this->agentFor($request);
        $report = $this->reporting->getAgentReport($agent);

        return view('agent.portal', [
            'surface' => 'dashboard',
            'agent' => $agent,
            'report' => $report,
            'records' => [],
            'state' => 'AVAILABLE',
        ]);
    }

    public function commissions(Request $request): View
    {
        $agent = $this->agentFor($request);
        $records = AgentCommission::query()
            ->where('agent_id', $agent->getKey())
            ->latest('id')
            ->limit(100)
            ->get([
                'reference_number', 'status', 'currency', 'base_amount',
                'commission_rate', 'commission_amount', 'accrued_at', 'paid_at',
            ])
            ->map(static fn (AgentCommission $commission): array => [
                'reference' => (string) $commission->reference_number,
                'status' => $commission->status->value,
                'currency' => $commission->currency?->value,
                'basis' => (string) $commission->base_amount,
                'rate' => (string) $commission->commission_rate,
                'amount' => (string) $commission->commission_amount,
                'created_at' => $commission->accrued_at?->toIso8601String(),
                'paid_at' => $commission->paid_at?->toIso8601String(),
            ])
            ->all();

        return view('agent.portal', [
            'surface' => 'commissions',
            'agent' => $agent,
            'report' => null,
            'records' => $records,
            'state' => $records === [] ? 'NO_DATA' : 'AVAILABLE',
        ]);
    }

    public function settlements(Request $request): View
    {
        $agent = $this->agentFor($request);
        $records = AgentCommission::query()
            ->where('agent_id', $agent->getKey())
            ->whereNotNull('paid_at')
            ->latest('paid_at')
            ->limit(100)
            ->get([
                'reference_number', 'base_amount', 'commission_amount',
                'currency', 'status', 'paid_at',
            ])
            ->map(static fn (AgentCommission $commission): array => [
                'reference' => (string) $commission->reference_number,
                'gross' => (string) $commission->base_amount,
                'net' => (string) $commission->commission_amount,
                'currency' => $commission->currency?->value,
                'status' => $commission->status->value,
                'paid_at' => $commission->paid_at?->toIso8601String(),
            ])
            ->all();

        return view('agent.portal', [
            'surface' => 'settlements',
            'agent' => $agent,
            'report' => null,
            'records' => $records,
            'state' => $records === [] ? 'NO_DATA' : 'AVAILABLE',
        ]);
    }

    public function statement(Request $request): View
    {
        $agent = $this->agentFor($request);
        $records = AgentCommission::query()
            ->where('agent_id', $agent->getKey())
            ->latest('id')
            ->limit(100)
            ->get([
                'reference_number', 'status', 'commission_amount', 'currency',
                'created_at', 'paid_at',
            ])
            ->map(static fn (AgentCommission $commission): array => [
                'reference' => (string) $commission->reference_number,
                'type' => 'COMMISSION',
                'amount' => (string) $commission->commission_amount,
                'currency' => $commission->currency?->value,
                'status' => $commission->status->value,
                'created_at' => $commission->created_at?->toIso8601String(),
                'paid_at' => $commission->paid_at?->toIso8601String(),
            ])
            ->all();

        return view('agent.portal', [
            'surface' => 'statement',
            'agent' => $agent,
            'report' => null,
            'records' => $records,
            'state' => $records === [] ? 'NO_DATA' : 'AVAILABLE',
        ]);
    }

    public function referrals(Request $request): View
    {
        $agent = $this->agentFor($request);
        $users = User::query()
            ->whereJsonContains('preferences->referred_by_agent_id', $agent->getKey())
            ->latest('id')
            ->limit(100)
            ->get(['id', 'status', 'created_at'])
            ->map(static fn (User $user): array => [
                'reference' => hash('sha256', 'agent-referral|'.$agent->getKey().'|'.$user->getKey()),
                'status' => $user->status->value,
                'created_at' => $user->created_at?->toIso8601String(),
            ])
            ->all();

        return view('agent.portal', [
            'surface' => 'referrals',
            'agent' => $agent,
            'report' => null,
            'records' => $users,
            'state' => $users === [] ? 'NO_DATA' : 'AVAILABLE',
        ]);
    }

    public function referralDetail(Request $request, string $reference): View
    {
        $agent = $this->agentFor($request);
        $user = User::query()
            ->whereJsonContains('preferences->referred_by_agent_id', $agent->getKey())
            ->get(['id', 'status', 'created_at'])
            ->first(static fn (User $candidate): bool => hash_equals(
                hash('sha256', 'agent-referral|'.$agent->getKey().'|'.$candidate->getKey()),
                $reference,
            ));

        abort_unless($user instanceof User, 404);

        return view('agent.portal', [
            'surface' => 'referral-detail',
            'agent' => $agent,
            'report' => null,
            'records' => [[
                'reference' => $reference,
                'status' => $user->status->value,
                'created_at' => $user->created_at?->toIso8601String(),
            ]],
            'state' => 'AVAILABLE',
        ]);
    }

    private function agentFor(Request $request): Agent
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $agent = Agent::query()
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->first();

        abort_unless($agent instanceof Agent && $agent->canEarnCommission(), 403);

        return $agent;
    }
}
