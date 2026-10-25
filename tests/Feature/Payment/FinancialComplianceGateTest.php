<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\ComplianceActionType;
use App\Enums\ComplianceCaseStatus;
use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Models\ComplianceAction;
use App\Models\ComplianceCase;
use App\Models\SelfExclusion;
use App\Models\User;
use App\Services\Compliance\FinancialComplianceGateService;
use Illuminate\Support\Str;

/**
 * The single fail-closed gate every money-OUT decision passes through.
 *
 * WHY THIS SUITE EXISTS
 * PayoutTransferService already called
 * `app(FinancialComplianceGateService::class)->assertPayoutAllowed(...)`, but
 * the class itself was never written: every prize-payout completion died with
 * a container resolution error AFTER the payout had been reviewed, approved,
 * batched and evidenced. The class now exists and composes the lanes that
 * already existed (real-money switch, KYC, compliance case/hold, protection
 * windows, AML, configured bounds) in one fixed order.
 *
 * The assertions below are about the two properties that make the gate worth
 * having: it refuses BY NAME (so a caller can never mistake "not checked" for
 * "allowed"), and every refusal is audited with the rule that fired — with no
 * personal data, ever.
 *
 * Every test uses the WITHDRAWAL lane at an amount below the KYC threshold, so
 * the only variable in each case is the rule under test.
 */
final class FinancialComplianceGateTest extends PaymentTestCase
{
    private const AMOUNT = '100.00';

    protected function setUp(): void
    {
        parent::setUp();

        // The configured outbound bounds are pinned here so the suite reads the
        // same everywhere: a CI environment that ships a different
        // FINANCE_MIN_WITHDRAWAL must not change which rule a test exercises.
        config([
            'payment.withdrawal.min' => '10.00',
            'payment.withdrawal.max' => '10000.00',
            'finance.withdrawal.min' => '10.00',
            'finance.withdrawal.max' => '10000.00',
        ]);
    }

    public function test_a_closed_real_money_gate_refuses_before_any_other_question(): void
    {
        config(['real_money.enabled' => false]);
        $player = $this->createPlayer('500.00');

        $this->assertRefused($player['user'], 'real_money_disabled');

        // The refusal names the deployment-wide switch, not a subject-specific
        // rule: nothing about this player was even examined.
        $this->assertSame(
            'Financial compliance gate refused a withdrawal (withdrawal) of '.self::AMOUNT.' THB: real_money_disabled.',
            AuditLog::query()->latest('id')->value('description'),
        );
    }

    public function test_an_open_compliance_case_refuses_the_payout(): void
    {
        $player = $this->createPlayer('500.00');
        $this->openCase($player['user']);

        $this->assertRefused($player['user'], 'compliance_case_open');
    }

    public function test_an_unreleased_hold_refuses_the_payout(): void
    {
        $player = $this->createPlayer('500.00');
        $case = $this->openCase($player['user']);

        $this->holdAction($case, $player['user'], released: false);

        $this->assertRefused($player['user'], 'compliance_hold_active');
    }

    public function test_a_closed_case_with_a_released_hold_no_longer_refuses_the_payout(): void
    {
        $player = $this->createPlayer('500.00');
        $case = $this->openCase($player['user']);

        $this->holdAction($case, $player['user'], released: true);

        // The desk closed the file and released its seal, so money may leave.
        $case->forceFill(['status' => ComplianceCaseStatus::Closed->value])->save();

        $this->gateway()->assertWithdrawalAllowed($player['user'], self::AMOUNT, Currency::THB->value);
        $this->addToAssertionCount(1);
    }

    public function test_an_active_self_exclusion_refuses_the_payout(): void
    {
        $player = $this->createPlayer('500.00');

        $exclusion = new SelfExclusion;
        $exclusion->fill([
            'user_id' => $player['user']->id,
            'status' => 'active',
            'scope' => 'all',
            'reason_code' => 'player_request',
            'request_fingerprint' => Str::lower(Str::random(24)),
            'effective_at' => now()->subDay(),
            'ends_at' => now()->addYear(),
            'activated_at' => now()->subDay(),
        ]);
        $exclusion->save();

        $this->assertRefused($player['user'], 'self_exclusion_active');
    }

    public function test_a_risk_band_at_or_above_the_configured_threshold_refuses_the_payout(): void
    {
        // The threshold is deliberately lowered to the softest band: whatever
        // the engine measures for this account, it is now at or above it.
        config(['aml.block_level' => 'low']);
        $player = $this->createPlayer('500.00');

        $this->assertRefused($player['user'], 'aml_risk_blocked');
    }

    public function test_an_amount_above_the_configured_maximum_refuses_the_payout(): void
    {
        config(['payment.withdrawal.max' => '50.00']);
        $player = $this->createPlayer('500.00');

        $this->assertRefused($player['user'], 'payout_above_configured_maximum');
    }

    public function test_an_amount_below_the_configured_minimum_refuses_the_payout(): void
    {
        config(['payment.withdrawal.min' => '500.00']);
        $player = $this->createPlayer('500.00');

        $this->assertRefused($player['user'], 'payout_below_configured_minimum');
    }

    public function test_a_malformed_amount_refuses_before_any_lane_is_consulted(): void
    {
        $player = $this->createPlayer('500.00');

        $this->assertRefused($player['user'], 'payout_amount_malformed', '12.345');
    }

    public function test_a_zero_amount_refuses(): void
    {
        $player = $this->createPlayer('500.00');

        $this->assertRefused($player['user'], 'payout_amount_not_positive', '0.00');
    }

    public function test_an_unsupported_currency_refuses(): void
    {
        $player = $this->createPlayer('500.00');

        try {
            $this->gateway()->assertWithdrawalAllowed($player['user'], self::AMOUNT, 'XYZ');
            $this->fail('The compliance gate released money in an unsupported currency.');
        } catch (FinancialException $exception) {
            $this->assertSame('payout_currency_unsupported', $exception->errorCode());
        }
    }

    public function test_a_clean_record_releases_the_withdrawal(): void
    {
        $player = $this->createPlayer('500.00');

        $this->gateway()->assertWithdrawalAllowed($player['user'], self::AMOUNT, Currency::THB->value);

        $this->assertSame(0, AuditLog::query()->count(), 'A release writes no refusal row.');
    }

    public function test_a_refusal_is_audited_without_personal_data(): void
    {
        config(['real_money.enabled' => false]);
        $player = $this->createPlayer('500.00');

        $this->assertRefused($player['user'], 'real_money_disabled');

        $log = AuditLog::query()->latest('id')->firstOrFail();
        $metadata = (array) $log->metadata;

        $this->assertSame('financial_compliance', $metadata['gate']);
        $this->assertSame('real_money_disabled', $metadata['reason_code']);
        $this->assertSame('withdrawal', $metadata['lane']);
        $this->assertSame(self::AMOUNT, $metadata['amount']);
        $this->assertSame('THB', $metadata['currency']);
        $this->assertSame($player['user']->id, $log->user_id);

        // The audit row is a fact an auditor needs, not a dossier: no name, no
        // e-mail, no document number, no destination, no token.
        $forbidden = ['destination', 'account_number', 'account_name', 'email', 'name', 'phone', 'national_id', 'token', 'secret'];
        foreach (array_keys($metadata) as $key) {
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, (string) $key);
            }
        }
        $this->assertStringNotContainsString('@', $log->description);
    }

    public function test_the_gate_exposes_one_verb_per_lane(): void
    {
        $gate = $this->gateway();

        // Both lanes must be answerable by the same class: a caller cannot
        // mistake "this class cannot answer that question" for "no".
        $this->assertTrue(method_exists($gate, 'assertPayoutAllowed'));
        $this->assertTrue(method_exists($gate, 'assertWithdrawalAllowed'));
    }

    private function gateway(): FinancialComplianceGateService
    {
        return app(FinancialComplianceGateService::class);
    }

    private function assertRefused(User $user, string $code, string $amount = self::AMOUNT): void
    {
        try {
            $this->gateway()->assertWithdrawalAllowed($user, $amount, Currency::THB->value);
            $this->fail('The compliance gate released money where it must have refused.');
        } catch (FinancialException $exception) {
            $this->assertSame($code, $exception->errorCode());
        }
    }

    /**
     * A live compliance file for this player.
     */
    private function openCase(User $user): ComplianceCase
    {
        $case = new ComplianceCase;
        $case->fill([
            'case_key' => 'case-'.Str::lower(Str::random(12)),
            'subject_user_id' => $user->id,
            'case_type' => 'aml_review',
            'risk_level' => 'high',
            'trigger_reference' => 'test-trigger',
            'evidence_fingerprint' => Str::lower(Str::random(32)),
        ]);
        // `status` is deliberately not mass assignable on the model.
        $case->status = ComplianceCaseStatus::Open;
        $case->save();

        return $case;
    }

    /**
     * A hold the desk has (or has not) lifted.
     */
    private function holdAction(ComplianceCase $case, User $actor, bool $released): ComplianceAction
    {
        $action = new ComplianceAction;
        $action->fill([
            'action_key' => 'act-'.Str::lower(Str::random(12)),
            'case_id' => $case->getKey(),
            'reason_code' => 'manual_hold',
            'actor_user_id' => $actor->id,
            'evidence_reference' => 'test-evidence',
            'is_released' => $released,
            'applied_at' => $released ? now()->subHour() : now(),
        ]);
        // `action_type` is deliberately not mass assignable on the model.
        $action->action_type = ComplianceActionType::Hold;

        if ($released) {
            $action->released_at = now();
            $action->release_note = 'Lifted by the desk.';
        }

        $action->save();

        return $action;
    }
}
