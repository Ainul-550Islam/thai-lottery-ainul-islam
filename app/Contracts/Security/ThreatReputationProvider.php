<?php

declare(strict_types=1);

namespace App\Contracts\Security;

/**
 * Interface ThreatReputationProvider
 *
 * Contract for querying external threat intelligence and URL/IP reputation feeds safely.
 */
interface ThreatReputationProvider
{
    /**
     * Check if a normalized domain or IP address is flagged in threat feeds.
     *
     * @param string $target Domain, IP, or URL hash
     * @return array{
     *     is_malicious: bool,
     *     threat_type: string|null,
     *     confidence_score: int,
     *     source: string,
     *     cached: bool
     * }
     */
    public function queryReputation(string $target): array;
}
