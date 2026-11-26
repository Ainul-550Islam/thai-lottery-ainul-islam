<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Services\Payment\ManualDisbursementQueueService;
use App\Services\Withdrawal\ManualDisbursementQueueService as LegacyManualDisbursementQueueService;
use Tests\TestCase;

final class PromptOperationsAndPayoutCoverageTest extends TestCase
{
    public function test_legacy_manual_disbursement_name_resolves_to_the_canonical_service(): void
    {
        self::assertTrue(class_exists(ManualDisbursementQueueService::class));
        self::assertTrue(class_exists(LegacyManualDisbursementQueueService::class));
        self::assertSame(
            ManualDisbursementQueueService::class,
            (new \ReflectionClass(LegacyManualDisbursementQueueService::class))->getName(),
        );
    }

    public function test_payment_operations_routes_are_permissioned_and_throttled(): void
    {
        $source = file_get_contents(base_path('routes/payment_ops.php'));

        self::assertIsString($source);
        self::assertStringContainsString('auth', $source);
        self::assertStringContainsString('manage-payment-disbursements', $source);
        self::assertStringContainsString('throttle', $source);
    }
}
