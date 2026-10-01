<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\DTOs\Operations\AdminOperationData;
use App\Models\AdminOperation;
use App\Models\User;
use App\Services\Operations\AdminOperationService as CanonicalAdminOperationService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;

/**
 * Admin Operation Service Facade / Delegation Layer.
 */
class AdminOperationService
{
    private CanonicalAdminOperationService $canonical;

    public function __construct(private readonly Container $container)
    {
        $this->canonical = $this->container->make(CanonicalAdminOperationService::class);
    }

    public static function assertAuthorized(User $user, string $capability): void
    {
        CanonicalAdminOperationService::assertAuthorized($user, $capability);
    }

    public function propose(AdminOperationData $data): array
    {
        return $this->canonical->propose($data);
    }

    public function approve(int $operationId, User $approver, ?string $note = null): AdminOperation
    {
        return $this->canonical->approve($operationId, $approver, $note);
    }

    public function cancel(int $operationId, User $canceller, string $reason): AdminOperation
    {
        return $this->canonical->cancel($operationId, $canceller, $reason);
    }

    public function execute(int $operationId): AdminOperation
    {
        return $this->canonical->execute($operationId);
    }

    public function listFor(?string $status, int $limit = 50): Collection
    {
        return $this->canonical->listFor($status, $limit);
    }
}
