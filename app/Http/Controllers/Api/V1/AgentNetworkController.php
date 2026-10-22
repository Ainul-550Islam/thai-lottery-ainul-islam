<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Services\Agent\AgentNetworkManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AgentNetworkController extends Controller
{
    public function __construct(
        private readonly AgentNetworkManagementService $agentNetworkService
    ) {}

    /**
     * Fetch downline tree genealogy.
     */
    public function tree(Request $request, string $agentId): JsonResponse
    {
        $maxDepth = (int) $request->query('max_depth', 5);
        $tree = $this->agentNetworkService->getDownlineTree($agentId, $maxDepth);

        return response()->json([
            'success' => true,
            'agent_id' => $agentId,
            'downline_count' => count($tree),
            'tree' => $tree,
        ], Response::HTTP_OK);
    }

    /**
     * Fetch turnover and performance summary for agent and downlines.
     */
    public function summary(Request $request, string $agentId): JsonResponse
    {
        $drawId = $request->query('draw_id') ? (string) $request->query('draw_id') : null;
        $summary = $this->agentNetworkService->getAgentPerformanceSummary($agentId, $drawId);

        return response()->json([
            'success' => true,
            'data' => $summary,
        ], Response::HTTP_OK);
    }
}
