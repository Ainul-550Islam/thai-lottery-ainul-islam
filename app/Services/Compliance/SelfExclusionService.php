<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Models\SelfExclusion;
use App\Services\ResponsibleGaming\SelfExclusionService as ResponsibleGamingSelfExclusionService;
use Illuminate\Support\Collection;

/**
 * Compliance Self-Exclusion Service Bridge.
 *
 * Bridges compliance workflows to the canonical responsible gaming self-exclusion engine.
 */
class SelfExclusionService
{
    public function __construct(
        private readonly ResponsibleGamingSelfExclusionService $engine,
    ) {
    }

    public function request(SelfExclusionData $data): SelfExclusion
    {
        return $this->engine->request($data);
    }

    public function activate(int|SelfExclusion $exclusion): SelfExclusion
    {
        return $this->engine->activate($exclusion);
    }

    public function expire(SelfExclusion $exclusion): SelfExclusion
    {
        return $this->engine->expire($exclusion);
    }

    public function cancel(SelfExclusion $exclusion, string $cancelledBy): SelfExclusion
    {
        return $this->engine->cancel($exclusion, $cancelledBy);
    }

    public function hasActiveExclusion(int $userId): bool
    {
        return $this->engine->hasActiveExclusion($userId);
    }

    public function currentActiveFor(int $userId): ?SelfExclusion
    {
        return $this->engine->currentActiveFor($userId);
    }

    /**
     * @return Collection<int, SelfExclusion>
     */
    public function dueForActivation(int $limit = 100): Collection
    {
        return $this->engine->dueForActivation($limit);
    }

    /**
     * @return Collection<int, SelfExclusion>
     */
    public function dueForExpiry(int $limit = 100): Collection
    {
        return $this->engine->dueForExpiry($limit);
    }
}
