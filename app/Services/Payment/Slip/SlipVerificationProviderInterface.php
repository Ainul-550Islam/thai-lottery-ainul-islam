<?php

declare(strict_types=1);

namespace App\Services\Payment\Slip;

use App\DTOs\Payment\SlipVerificationResult;
use App\Exceptions\FinancialException;
use Illuminate\Http\UploadedFile;

interface SlipVerificationProviderInterface
{
    /** @throws FinancialException */
    public function verifyQr(string $payload): SlipVerificationResult;

    /** @throws FinancialException */
    public function verifyImage(UploadedFile|string $image): SlipVerificationResult;

    public function name(): string;
}
