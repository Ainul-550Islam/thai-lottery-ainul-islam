<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Security;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Security\StoreBlockedIpRequest;
use App\Http\Resources\Api\V1\BlockedIpResource;
use App\Models\BlockedIp;
use App\Services\Network\IpBlocklistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class BlockedIpController extends Controller
{
    public function __construct(
        private readonly IpBlocklistService $blocklistService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('viewAny', BlockedIp::class);

        $query = BlockedIp::orderBy('created_at', 'desc');

        if ($request->has('active')) {
            $query->where('is_active', filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($search = $request->query('search')) {
            $query->where('ip_or_cidr', 'like', "%{$search}%");
        }

        return BlockedIpResource::collection($query->paginate(20));
    }

    public function store(StoreBlockedIpRequest $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', BlockedIp::class);

        $actorId = (string) $request->user()?->id;

        $blockedIp = $this->blocklistService->blockIp(
            ipOrCidr: (string) $request->input('ip_or_cidr'),
            reasonCode: (string) $request->input('reason_code'),
            source: (string) $request->input('source', 'MANUAL'),
            durationMinutes: $request->input('duration_minutes') ? (int) $request->input('duration_minutes') : null,
            actorId: $actorId,
            notes: (string) $request->input('notes')
        );

        return response()->json([
            'success' => true,
            'data' => new BlockedIpResource($blockedIp),
        ], Response::HTTP_CREATED);
    }

    public function unblock(Request $request, string $id): JsonResponse
    {
        /** @var BlockedIp $rule */
        $rule = BlockedIp::findOrFail($id);
        Gate::forUser($request->user())->authorize('update', $rule);

        $actorId = (string) $request->user()?->id;
        $reason = (string) $request->input('reason', 'Administrative manual unblock.');

        $unblockedIp = $this->blocklistService->unblockIp(
            id: $id,
            actorId: $actorId,
            unblockReason: $reason
        );

        return response()->json([
            'success' => true,
            'message' => 'IP block rule deactivated successfully.',
            'data' => new BlockedIpResource($unblockedIp),
        ], Response::HTTP_OK);
    }
}
