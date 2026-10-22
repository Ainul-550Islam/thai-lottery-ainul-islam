<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\Exceptions\ChatException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\StoreCannedResponseRequest;
use App\Models\Chat\ChatCannedResponse;
use App\Models\User;
use App\Services\Chat\ChatCannedResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administration of canned responses.
 *
 * ============================================================================
 * SEPARATED FROM THE OPERATOR CONSOLE ON PURPOSE
 * ============================================================================
 * The console USES canned responses (read-only picker); a smaller group
 * ADMINISTRATES them (create / edit / disable). Different permission,
 * different surface, different blast radius — so a controller of its own,
 * behind the manage_canned permission, rather than a flag inside the
 * console controller.
 *
 * ============================================================================
 * OUTPUT CHOICE
 * ============================================================================
 * These endpoints return JSON. The admin UI that consumes them can be a
 * blade page with fetch, or Filament later; the contract does not care.
 * Field-level validation failures come back in Laravel's standard envelope
 * (see StoreCannedResponseRequest), because that is what error bags render;
 * domain refusals (unknown key) come back through ChatException.
 */
final class ChatAdminController extends Controller
{
    public function __construct(
        private readonly ChatCannedResponseService $canned,
    ) {}

    /**
     * GET /admin/chat/canned — full list including disabled rows.
     */
    public function index(Request $request): JsonResponse
    {
        $this->assertCanManage($request);

        $perPage = (int) $request->query('per_page', 25);

        return response()->json([
            'status' => 'OK',
            'canned_responses' => $this->canned->paginateForAdmin($perPage),
        ]);
    }

    /**
     * POST /admin/chat/canned — create.
     */
    public function store(StoreCannedResponseRequest $request): JsonResponse
    {
        $canned = $this->canned->create($request->cannedAttributes(), $this->actor($request));

        return response()->json([
            'status' => 'OK',
            'canned_response' => $canned,
        ], 201);
    }

    /**
     * PUT /admin/chat/canned/{cannedResponse} — update.
     *
     * The key is immutable (see the service): the request itself prohibits
     * sending one, so there is nothing to strip here.
     */
    public function update(StoreCannedResponseRequest $request, ChatCannedResponse $cannedResponse): JsonResponse
    {
        $updated = $this->canned->update($cannedResponse, $request->cannedAttributes(), $this->actor($request));

        return response()->json([
            'status' => 'OK',
            'canned_response' => $updated,
        ]);
    }

    /**
     * POST /admin/chat/canned/{cannedResponse}/disable — disable, never delete.
     */
    public function disable(Request $request, ChatCannedResponse $cannedResponse): JsonResponse
    {
        $this->assertCanManage($request);

        $disabled = $this->canned->disable($cannedResponse, $this->actor($request));

        return response()->json([
            'status' => 'OK',
            'canned_response' => $disabled,
        ]);
    }

    // ========================================================================

    private function assertCanManage(Request $request): void
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw ChatException::notParticipant(0, null);
        }

        $permission = (string) config('chat.operator.permissions.manage_canned');

        try {
            $allowed = $user->hasPermissionTo($permission);
        } catch (\Throwable) {
            $allowed = false;
        }

        if (! $allowed) {
            throw ChatException::notParticipant(0, (int) $user->getAuthIdentifier());
        }
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw ChatException::notParticipant(0, null);
        }

        return $user;
    }
}
