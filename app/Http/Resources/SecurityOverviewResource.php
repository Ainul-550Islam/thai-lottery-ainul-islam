<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The security score and the checks behind it.
 *
 * The score, the maximum and the percentage all come from the service, computed from
 * the SAME booleans reported in `checks`. This class does no arithmetic at all — a
 * resource that recomputed a percentage would be able to disagree with the list of
 * checks printed next to it.
 *
 * The wording in the payload describes the score ("strong", "fair"), never the
 * platform. ForbiddenClaimsTest fails the build for a claim like "100% secure".
 *
 * @property array<string, mixed> $resource
 */
final class SecurityOverviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $security */
        $security = (array) $this->resource;

        return [
            'score' => (int) ($security['score'] ?? 0),
            'max_score' => (int) ($security['max_score'] ?? 0),
            'score_percent' => (int) ($security['score_percent'] ?? 0),
            'label' => (string) ($security['label'] ?? 'Weak'),
            'all_checks_passed' => (bool) ($security['all_checks_passed'] ?? false),
            'checks' => $security['checks'] ?? [],
            'trusted_devices' => (int) ($security['trusted_devices'] ?? 0),
            'active_sessions' => (int) ($security['active_sessions'] ?? 0),
            'recent_logins' => $security['recent_logins'] ?? [],
            'generated_at' => $security['generated_at'] ?? null,
        ];
    }
}
