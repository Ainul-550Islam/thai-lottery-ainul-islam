<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Claim venue/channel vocabulary for GLO prize claims (GLO-12/14).
 *
 * Physical-ticket requirements (original signed ticket) apply to in-person
 * channels; digital/partner channels follow their own flow and must not be
 * forced through the physical-original rule when config marks them digital.
 */
enum GloClaimChannel: string
{
    case GloOffice = 'glo_office';

    case ProvincialOffice = 'provincial_office';

    case Bank = 'bank';

    case PartnerPlatform = 'partner_platform';

    public function label(): string
    {
        return match ($this) {
            self::GloOffice => 'GLO Headquarters',
            self::ProvincialOffice => 'Provincial Government Office',
            self::Bank => 'Bank / partnered payout',
            self::PartnerPlatform => 'Partner platform',
        };
    }

    public function isPhysical(): bool
    {
        return $this === self::GloOffice || $this === self::ProvincialOffice;
    }

    public function isDigital(): bool
    {
        return $this === self::Bank || $this === self::PartnerPlatform;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
