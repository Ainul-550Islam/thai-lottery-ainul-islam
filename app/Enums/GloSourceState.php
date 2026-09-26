<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Explicit source-state labels for every public GLO integration object.
 * Never report OFFICIAL when data is internal admin entry or fixture.
 */
enum GloSourceState: string
{
    case OfficialSourceVerified = 'OFFICIAL_SOURCE_VERIFIED';
    case OfficialSourceConfigured = 'OFFICIAL_SOURCE_CONFIGURED';
    case InternalReconciled = 'INTERNAL_RECONCILED';
    case FixtureOnly = 'FIXTURE_ONLY';
    case NotConfigured = 'NOT_CONFIGURED';
    case Unavailable = 'UNAVAILABLE';

    public function label(): string
    {
        return $this->value;
    }
}
