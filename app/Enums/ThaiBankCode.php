<?php

declare(strict_types=1);

namespace App\Enums;

enum ThaiBankCode: string
{
    case Bbl = '002';
    case Kbank = '004';
    case Ktb = '006';
    case Ttb = '011';
    case Scb = '014';
    case Bay = '025';
    case Gsb = '030';
    case Baac = '034';
    case Uob = '024';
    case Cimb = '022';
    case Lh = '073';
    case Kkp = '069';

    public function label(): string
    {
        return match ($this) {
            self::Bbl => 'Bangkok Bank',
            self::Kbank => 'Kasikornbank',
            self::Ktb => 'Krung Thai Bank',
            self::Ttb => 'TMBThanachart Bank',
            self::Scb => 'Siam Commercial Bank',
            self::Bay => 'Bank of Ayudhya',
            self::Gsb => 'Government Savings Bank',
            self::Baac => 'Bank for Agriculture and Agricultural Cooperatives',
            self::Uob => 'United Overseas Bank (Thailand)',
            self::Cimb => 'CIMB Thai Bank',
            self::Lh => 'Land and Houses Bank',
            self::Kkp => 'Kiatnakin Phatra Bank',
        };
    }

    /** @return list<int> */
    public function accountNumberLengths(): array
    {
        return match ($this) {
            self::Gsb, self::Baac => [12],
            self::Bay => [10, 12],
            default => [10],
        };
    }

    public static function resolve(string $value): ?self
    {
        $normalised = strtoupper(trim($value));
        $aliases = [
            'BBL' => self::Bbl,
            'BANGKOK BANK' => self::Bbl,
            'KBANK' => self::Kbank,
            'KASIKORNBANK' => self::Kbank,
            'KTB' => self::Ktb,
            'KRUNG THAI BANK' => self::Ktb,
            'TTB' => self::Ttb,
            'SCB' => self::Scb,
            'BAY' => self::Bay,
            'KRUNGSRI' => self::Bay,
            'GSB' => self::Gsb,
            'BAAC' => self::Baac,
            'UOB' => self::Uob,
            'CIMB' => self::Cimb,
            'LH' => self::Lh,
            'KKP' => self::Kkp,
        ];

        return self::tryFrom($normalised) ?? ($aliases[$normalised] ?? null);
    }
}
