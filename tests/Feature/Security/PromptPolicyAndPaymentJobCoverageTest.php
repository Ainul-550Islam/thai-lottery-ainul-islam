<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Jobs\PollThaiBankPayoutStatusJob;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Wallet;
use App\Policies\LedgerPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PayoutPolicy;
use App\Policies\WalletPolicy;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class PromptPolicyAndPaymentJobCoverageTest extends TestCase
{
    public function test_financial_models_resolve_to_explicit_policies(): void
    {
        self::assertInstanceOf(PaymentPolicy::class, Gate::getPolicyFor(Payment::class));
        self::assertInstanceOf(PayoutPolicy::class, Gate::getPolicyFor(Payout::class));
        self::assertInstanceOf(WalletPolicy::class, Gate::getPolicyFor(Wallet::class));
        self::assertInstanceOf(LedgerPolicy::class, Gate::getPolicyFor(LedgerEntry::class));
    }

    public function test_thai_bank_poll_job_keeps_provider_specific_reconciliation_dependencies(): void
    {
        $source = file_get_contents((new \ReflectionClass(PollThaiBankPayoutStatusJob::class))->getFileName());

        self::assertIsString($source);
        self::assertStringContainsString('ThaiBankPayout', $source);
        self::assertStringContainsString('reconcil', strtolower($source));
        self::assertStringNotContainsString('markCompleted(', $source);
    }
}
