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

    /**
     * Acceptance-name aliases: the enterprise callers seat an exclusion with
     * exclude() and read it back with isSelfExcluded(). Both delegate to the
     * canonical engine; exclude() activates immediately because the engine's
     * seated-request default effective window starts at the request moment,
     * so an exclusion the player asks for gates from now, not from a later
     * batch activation.
     */
    public function exclude(SelfExclusionData $data): SelfExclusion
    {
        $exclusion = $this->engine->request($data);

        if ($exclusion->status !== \App\Enums\SelfExclusionStatus::Active) {
            $exclusion = $this->engine->activate($exclusion);
        }

        return $exclusion;
    }

    public function isSelfExcluded(int $userId): bool
    {
        return $this->engine->hasActiveExclusion($userId);
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
