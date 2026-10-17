<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\IpAccessRule;
use App\Models\User;
use App\Services\Network\DatabaseIpRuleReader;
use App\Services\Network\IpAccessRuleManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/** Authorized database inventory and mutations; no permission delegation or hard delete. */
final class IpAccessRuleController extends Controller
{
    public function __construct(private readonly IpAccessRuleManager $manager) {}

    public function manage(Request $request): Response
    {
        $this->actor($request);
        $flag = static fn ($value): string => match ($value) {
            true => 'ON', false => 'OFF', default => 'INVALID_CONFIG',
        };
        $mode = config('ip_access.mode');

        return response()->view('admin.ip-access-rules', [
            'capacityLimit' => DatabaseIpRuleReader::MAX_ACTIVE_RULES,
            'globalState' => $flag(config('ip_access.enabled')),
            'databaseState' => $flag(config('ip_access.database_enabled', false)),
            'policyMode' => in_array($mode, ['log', 'enforce'], true) ? strtoupper($mode) : 'INVALID_CONFIG',
        ])->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function index(Request $request): JsonResponse
    {
        $this->actor($request);
        $input = Validator::make($request->query(), [
            'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'before_id' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
        ])->validate();
        $limit = (int) ($input['limit'] ?? 25);
        $now = Carbon::now()->getTimestamp();
        $query = IpAccessRule::query()->orderByDesc('id');
        if (isset($input['before_id'])) {
            $query->where('id', '<', (int) $input['before_id']);
        }
        $rows = $query->limit($limit + 1)->get([
            'id', 'ip_range', 'reason_code', 'starts_at_epoch', 'expires_at_epoch', 'revoked_at_epoch', 'created_by',
        ]);
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);

        return ApiResponse::success([
            'items' => $page->map(fn (IpAccessRule $rule): array => $this->inventoryItem($rule, $now))->values()->all(),
            'has_more' => $hasMore,
            'next_before_id' => $hasMore ? $page->last()->id : null,
            'observed_at_epoch' => $now,
        ], 'Database inventory only; lifecycle does not prove request blocking.')
            ->header('Cache-Control', 'no-store, private');
    }

    private function inventoryItem(IpAccessRule $rule, int $now): array
    {
        try {
            $range = $rule->validatedRange();
        } catch (InvalidArgumentException) {
            // Keep the real row reference, never echo corrupt text or silently hide the row.
            return [
                'id' => $rule->id, 'validation_status' => 'INVALID', 'lifecycle' => 'UNKNOWN',
                'ip_range' => null, 'reason_code' => null, 'starts_at_epoch' => null,
                'expires_at_epoch' => null, 'revoked_at_epoch' => null, 'created_by' => null,
            ];
        }
        $lifecycle = match (true) {
            $rule->revoked_at_epoch !== null => 'REVOKED',
            $rule->expires_at_epoch !== null && $rule->expires_at_epoch <= $now => 'EXPIRED',
            $rule->starts_at_epoch > $now => 'SCHEDULED',
            default => 'ACTIVE',
        };

        return [
            'id' => $rule->id, 'validation_status' => 'VALID', 'lifecycle' => $lifecycle,
            'ip_range' => $range, 'reason_code' => $rule->reason_code,
            'starts_at_epoch' => $rule->starts_at_epoch, 'expires_at_epoch' => $rule->expires_at_epoch,
            'revoked_at_epoch' => $rule->revoked_at_epoch, 'created_by' => $rule->created_by,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $input = $request->validate([
            'ip_range' => ['required', 'string', 'max:64'],
            'reason_code' => ['required', 'string', 'max:64', 'regex:/^[A-Z][A-Z0-9_]{0,63}$/D'],
            'starts_at_epoch' => ['nullable', 'integer', 'min:0', 'max:253402300799'],
            'expires_at_epoch' => ['nullable', 'integer', 'min:0', 'max:253402300799'],
            'actor_id' => ['prohibited'], 'created_by' => ['prohibited'], 'revoked_at_epoch' => ['prohibited'],
        ]);
        $key = $request->header('Idempotency-Key');
        $rule = $this->manager->create(
            $actor, $input['ip_range'], $input['reason_code'],
            isset($input['expires_at_epoch']) ? (int) $input['expires_at_epoch'] : null,
            isset($input['starts_at_epoch']) ? (int) $input['starts_at_epoch'] : null,
            $key,
        );

        $replayed = $key !== null && ! $rule->wasRecentlyCreated;

        return $this->response($rule, $replayed ? 200 : 201)
            ->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function revoke(Request $request, string $ruleId): JsonResponse
    {
        $actor = $this->actor($request);
        $id = filter_var($ruleId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);
        $input = $request->validate([
            'reason_code' => ['required', 'string', 'max:64', 'regex:/^[A-Z][A-Z0-9_]{0,63}$/D'],
            'actor_id' => ['prohibited'], 'created_by' => ['prohibited'], 'revoked_at_epoch' => ['prohibited'],
        ]);

        return $this->response($this->manager->revoke($actor, $id, $input['reason_code']), 200);
    }

    private function actor(Request $request): User
    {
        // Force safe shared JSON exception rendering for controller failures, even without Accept.
        $request->headers->set('Accept', 'application/json');
        $actor = $request->user('web');
        if (! $actor instanceof User) {
            throw new AuthorizationException;
        }
        $this->manager->assertAuthorized($actor);
        if (config('ip_access.management_enabled', false) !== true) {
            throw new HttpResponseException(ApiResponse::error(
                'ip_rule_management_not_configured', 'IP rule management is not enabled.', 503,
            ));
        }

        return $actor;
    }

    private function response(IpAccessRule $rule, int $status): JsonResponse
    {
        return ApiResponse::success([
            'id' => $rule->id, 'ip_range' => $rule->ip_range, 'reason_code' => $rule->reason_code,
            'starts_at_epoch' => $rule->starts_at_epoch, 'expires_at_epoch' => $rule->expires_at_epoch,
            'revoked_at_epoch' => $rule->revoked_at_epoch, 'created_by' => $rule->created_by,
        ], 'IP rule saved; enforcement configuration is unchanged.', $status)
            ->header('Cache-Control', 'no-store, private');
    }
}
