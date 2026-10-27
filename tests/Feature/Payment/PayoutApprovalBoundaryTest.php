<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Finance\PayoutApprovalData;
use App\DTOs\Finance\PayoutRequestData;
use App\Enums\Currency;
use App\Enums\PayoutApprovalStatus;
use App\Enums\PrizePayoutMethod;
use App\Exceptions\PayoutApprovalException;
use App\Services\Finance\PayoutApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesExternalPayout;

final class PayoutApprovalBoundaryTest extends PaymentTestCase
{
    use CreatesExternalPayout;

    public function test_request_persists_original_facts_and_replay_keeps_one_maker(): void
    {
        $f = $this->externalFixture(false);
        $a = $f['row']->metadata['approval'];
        $this->assertSame($a, app(PayoutApprovalService::class)->request($f['row'], $f['request']));
        $this->assertSame(64, strlen($a['facts_hash']));
        $this->assertSame('NOT-PAYABLE', $f['row']->metadata['claim']['beneficiary']['account_number']);
    }

    public function test_stale_model_cannot_overwrite_an_already_decided_lane(): void
    {
        $f = $this->externalFixture(false);
        $stale = $f['row']->replicate();
        $stale->id = $f['row']->id;
        app(PayoutApprovalService::class)->decide($f['row'], new PayoutApprovalData($f['checker']->id, PayoutApprovalStatus::Approved, 'Independent review completed.'));
        $this->expectException(PayoutApprovalException::class);
        app(PayoutApprovalService::class)->decide($stale, new PayoutApprovalData($f['reviewer']->id, PayoutApprovalStatus::Rejected, 'Conflicting stale second verdict.'));
    }

    public function test_maker_cannot_approve_own_request(): void
    {
        $f = $this->externalFixture(false);
        $this->expectException(PayoutApprovalException::class);
        app(PayoutApprovalService::class)->decide($f['row'], new PayoutApprovalData($f['maker']->id, PayoutApprovalStatus::Approved, 'Maker attempts own approval.'));
    }

    public function test_destination_mutation_blocks_checker(): void
    {
        $f = $this->externalFixture(false);
        $metadata = $f['row']->metadata;
        $metadata['claim']['beneficiary']['account_number'] = 'DIFFERENT-SYNTHETIC-TARGET';
        $f['row']->metadata = $metadata;
        $f['row']->save();
        $this->expectException(PayoutApprovalException::class);
        app(PayoutApprovalService::class)->decide($f['row'], new PayoutApprovalData($f['checker']->id, PayoutApprovalStatus::Approved, 'Refuse changed original beneficiary.'));
    }

    public function test_current_revoked_staff_is_not_trusted_from_caller_model(): void
    {
        $f = $this->externalFixture(false);
        $f['checker']->syncRoles([]);
        $this->expectException(AuthorizationException::class);
        app(PayoutApprovalService::class)->decide($f['row'], new PayoutApprovalData($f['checker']->id, PayoutApprovalStatus::Approved, 'No longer authorized checker.'));
    }

    public function test_approval_cannot_be_marked_complete_without_canonical_money(): void
    {
        $f = $this->externalFixture();
        $this->expectException(PayoutApprovalException::class);
        app(PayoutApprovalService::class)->complete($f['row']);
    }

    public static function requestMutations(): array
    {
        return array_map(fn ($key) => [$key], ['prizeReference', 'beneficiaryUserId', 'currency', 'amount', 'requestKey', 'method', 'beneficiary']);
    }

    #[DataProvider('requestMutations')]
    public function test_request_identity_is_checked_even_on_same_key_replay(string $field): void
    {
        $f = $this->externalFixture(false);
        $d = $f['request'];
        $args = ['prizeReference' => $d->prizeReference, 'amount' => $d->amount, 'currency' => $d->currency,
            'method' => $d->method, 'requesterUserId' => $d->requesterUserId, 'beneficiaryUserId' => $d->beneficiaryUserId,
            'beneficiary' => $d->beneficiary, 'reason' => $d->reason, 'requestKey' => $d->requestKey];
        $args[$field] = match ($field) {
            'beneficiaryUserId' => $f['reviewer']->id, 'currency' => Currency::USD,
            'amount' => '401.00', 'method' => PrizePayoutMethod::Cheque,
            'beneficiary' => ['account_number' => 'FOREIGN'], default => 'CHANGED',
        };
        $this->expectException(PayoutApprovalException::class);
        app(PayoutApprovalService::class)->request($f['row'], new PayoutRequestData(...$args));
    }

    public function test_account_context_change_after_maker_request_blocks_checker(): void
    {
        $f = $this->externalFixture(false);
        config(['payment.withdrawal_reference_accounts.bank_transfer' => 'ROTATED-ACCOUNT']);
        $this->expectException(PayoutApprovalException::class);
        app(PayoutApprovalService::class)->decide($f['row'], new PayoutApprovalData($f['checker']->id, PayoutApprovalStatus::Approved, 'Do not reinterpret original merchant account.'));
    }
}
