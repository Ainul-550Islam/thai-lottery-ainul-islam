<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\IssuePosTicketRequest;
use App\Services\Agent\AgentPosTicketIssuanceService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AgentPosController extends Controller
{
    public function __construct(
        private readonly AgentPosTicketIssuanceService $posService
    ) {}

    public function issue(IssuePosTicketRequest $request): JsonResponse
    {
        $agentId = (string) $request->input('agent_id');
        $drawId = (string) $request->input('draw_id');
        $entries = (array) $request->input('entries');
        $clientIp = (string) $request->ip();

        $result = $this->posService->issuePosTickets($agentId, $drawId, $entries, $clientIp);

        return response()->json([
            'success' => true,
            'message' => 'Lottery tickets issued successfully via POS.',
            'data' => $result,
        ], Response::HTTP_CREATED);
    }
}
