<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\LoginEventResource;
use App\Http\Responses\ApiResponse;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Security\LoginHistory\LoginHistoryQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LoginHistoryController
{
    public function __construct(
        private readonly LoginHistoryQueryService $queryService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $member */
        $member = $request->user();
        $perPage = (int) $request->integer('per_page', (int) config('rewards.login_history.per_page', 20));
        $paginator = $this->queryService->forMember($member, $perPage);

        /** @var list<LoginEvent> $rawItems */
        $rawItems = $paginator->items();
        $items = array_map(
            static fn (LoginEvent $event): array => (new LoginEventResource($event))->resolve($request),
            $rawItems,
        );

        return ApiResponse::success([
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'Login history retrieved.');
    }

    public function summary(Request $request): JsonResponse
    {
        /** @var User $member */
        $member = $request->user();

        return ApiResponse::success(
            $this->queryService->summaryForMember($member),
            'Login security summary retrieved.',
        );
    }
}
