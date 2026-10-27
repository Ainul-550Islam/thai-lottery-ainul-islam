<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\AuditAction;
use App\Enums\ComplianceActionType;
use App\Enums\ComplianceCaseStatus;
use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Models\ComplianceAction;
use App\Models\ComplianceCase;
use App\Models\User;
use App\Services\Compliance\FinancialComplianceGateService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class FinancialComplianceRefusalAuditTest extends PaymentTestCase
{
    /** @return iterable<string, array{ComplianceCaseStatus, ?ComplianceActionType, string}> */
    public static function refusals(): iterable
    {
        foreach ([ComplianceCaseStatus::Open, ComplianceCaseStatus::Investigating, ComplianceCaseStatus::Escalated] as $status) {
            yield $status->value.' case' => [$status, null, 'compliance_case_open'];
            yield $status->value.' hold' => [$status, ComplianceActionType::Hold, 'compliance_hold_active'];
            yield $status->value.' restriction' => [$status, ComplianceActionType::Restrict, 'compliance_hold_active'];
        }
    }

    #[DataProvider('refusals')]
    public function test_named_refusal_is_not_caught_or_audited_twice(
        ComplianceCaseStatus $status,
        ?ComplianceActionType $actionType,
        string $reason,
    ): void {
        $user = $this->createPlayer()['user'];
        $case = $this->openCase($user, $status);
        if ($actionType !== null) {
            $action = new ComplianceAction;
            $action->fill([
                'action_key' => 'act-'.Str::uuid(),
                'case_id' => $case->getKey(),
                'reason_code' => 'manual_hold',
                'actor_user_id' => $user->getKey(),
                'evidence_reference' => 'refusal-audit-test',
                'is_released' => false,
                'applied_at' => now(),
            ]);
            $action->action_type = $actionType;
            $action->save();
        }

        $exception = $this->refusal($user);

        self::assertSame($reason, $exception->errorCode());
        self::assertNull($exception->getPrevious());
        $this->assertSingleAudit($user, $reason);
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableTables(): iterable
    {
        yield 'case read fails' => ['compliance_cases'];
        yield 'hold read fails despite known open case' => ['compliance_actions'];
    }

    #[DataProvider('unreadableTables')]
    public function test_actual_read_failure_still_refuses_and_audits_once_without_private_diagnostics(string $table): void
    {
        $user = $this->createPlayer()['user'];
        $this->openCase($user, ComplianceCaseStatus::Open);
        $failure = new QueryException('sqlite', 'SELECT private_database_diagnostic', [], new RuntimeException('private connection diagnostic'));
        DB::connection()->beforeExecuting(static function (string $query) use ($table, $failure): void {
            if (preg_match('/\bfrom\s+["`]?'.preg_quote($table, '/').'["`]?\b/i', $query) === 1) {
                throw $failure;
            }
        });

        $exception = $this->refusal($user);

        self::assertSame('compliance_state_unavailable', $exception->errorCode());
        self::assertSame($failure, $exception->getPrevious());
        $this->assertSingleAudit($user, 'compliance_state_unavailable');
        $audit = AuditLog::query()->sole();
        self::assertStringNotContainsString('private', $audit->description);
        self::assertStringNotContainsString('private', json_encode($audit->metadata, JSON_THROW_ON_ERROR));
    }

    private function openCase(User $user, ComplianceCaseStatus $status): ComplianceCase
    {
        $case = new ComplianceCase;
        $case->fill([
            'case_key' => 'case-'.Str::uuid(),
            'subject_user_id' => $user->getKey(),
            'case_type' => 'aml_review',
            'risk_level' => 'high',
            'trigger_reference' => 'refusal-audit-test',
            'evidence_fingerprint' => hash('sha256', 'refusal-audit-test'),
        ]);
        $case->status = $status;
        $case->save();

        return $case;
    }

    private function refusal(User $user): FinancialException
    {
        try {
            app(FinancialComplianceGateService::class)->assertWithdrawalAllowed($user, '100.00', Currency::THB->value);
            self::fail('A live case, hold or unreadable compliance state must refuse the withdrawal.');
        } catch (FinancialException $exception) {
            return $exception;
        }
    }

    private function assertSingleAudit(User $user, string $reason): void
    {
        self::assertSame(1, AuditLog::query()->count());
        $audit = AuditLog::query()->sole();
        self::assertSame(AuditAction::Withdraw, $audit->action);
        self::assertSame($user->getKey(), $audit->user_id);
        self::assertSame('financial_compliance', $audit->metadata['gate']);
        self::assertSame($reason, $audit->metadata['reason_code']);
        self::assertSame('100.00', $audit->metadata['amount']);
        self::assertSame('THB', $audit->metadata['currency']);
    }
}
