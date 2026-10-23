<?php

declare(strict_types=1);

namespace App\Models;

class SecurityIpBlock extends BlockedIp
{
    public function getIpAddressAttribute(): ?string
    {
        return $this->attributes['ip_address'] ?? $this->attributes['ip_or_cidr'] ?? null;
    }

    public function getReasonAttribute(): ?string
    {
        return $this->attributes['reason'] ?? $this->attributes['reason_code'] ?? null;
    }
}
