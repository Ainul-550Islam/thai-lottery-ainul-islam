<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\FinancialException;
use App\Http\Requests\Api\V1\StoreBankAccountRequest;
use App\Http\Resources\MemberBankAccountResource;
use App\Http\Responses\ApiResponse;
use App\Models\MemberBankAccount;
use App\Models\User;
use App\Services\Account\MemberBankAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class BankAccountController
{
    public function __construct(
        private readonly MemberBankAccountService $bankAccounts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $member */
        $member = $request->user();
        Gate::forUser($member)->authorize('viewAny', MemberBankAccount::class);

        $items = $this->bankAccounts->listForMember($member)
            ->map(static fn (MemberBankAccount $account): array => (new MemberBankAccountResource($account))->resolve($request))
            ->values()
            ->all();

        return ApiResponse::success(['items' => $items], 'Bank accounts retrieved.');
    }

    public function store(StoreBankAccountRequest $request): JsonResponse
    {
        /** @var User $member */
        $member = $request->user();
        Gate::forUser($member)->authorize('create', MemberBankAccount::class);
        $validated = $request->validated();

        try {
            $account = $this->bankAccounts->add(
                member: $member,
                bankCode: (string) $validated['bank_code'],
                accountName: (string) $validated['account_name'],
                accountNumber: (string) $validated['account_number'],
                makeDefault: (bool) ($validated['is_default'] ?? false),
            );
        } catch (FinancialException $exception) {
            return ApiResponse::error(
                code: $exception->errorCode() ?? 'bank_account_error',
                message: $exception->getMessage(),
                status: 422,
            );
        }

        return ApiResponse::success(
            data: ['bank_account' => (new MemberBankAccountResource($account))->resolve($request)],
            message: 'Bank account registered pending verification.',
            status: 201,
        );
    }

    public function setDefault(Request $request, int $id): JsonResponse
    {
        /** @var User $member */
        $member = $request->user();

        try {
            $account = $this->bankAccounts->setDefault($member, $id);
        } catch (FinancialException $exception) {
            $status = $exception->errorCode() === 'bank_account_not_found' ? 404 : 422;

            return ApiResponse::error(
                code: $exception->errorCode() ?? 'bank_account_error',
                message: $exception->getMessage(),
                status: $status,
            );
        }

        return ApiResponse::success(
            data: ['bank_account' => (new MemberBankAccountResource($account))->resolve($request)],
            message: 'Default bank account updated.',
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $member */
        $member = $request->user();

        try {
            $this->bankAccounts->remove($member, $id);
        } catch (FinancialException $exception) {
            $status = $exception->errorCode() === 'bank_account_not_found' ? 404 : 422;

            return ApiResponse::error(
                code: $exception->errorCode() ?? 'bank_account_error',
                message: $exception->getMessage(),
                status: $status,
            );
        }

        return ApiResponse::success(['removed' => true], 'Bank account removed.');
    }
}
