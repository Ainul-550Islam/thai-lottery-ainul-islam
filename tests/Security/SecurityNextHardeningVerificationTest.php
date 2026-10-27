<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Contracts\Security\SecurityRiskEngine;
use App\DTOs\Security\FraudDecisionData;
use App\Enums\AuditAction;
use App\Enums\FraudDecision;
use App\Enums\MalwareScanStatus;
use App\Enums\RiskLevel;
use App\Http\Middleware\FraudRiskGateMiddleware;
use App\Http\Middleware\VerifyCsrfToken;
use App\Http\Requests\Admin\Security\ReviewFraudAlertRequest;
use App\Models\AuditLog;
use App\Models\FraudAlert;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Fraud\FraudAlertReviewService;
use App\Services\Security\FieldEncryptionService;
use App\Services\Uploads\UploadQuarantineService;
use DomainException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Http\Exceptions\HttpResponseException;
use Tests\TestCase;
use ReflectionClass;

final class SecurityNextHardeningVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_csrf_middleware_forbids_wildcard_api_exemption_and_keeps_web_csrf_active(): void
    {
        $this->assertNotContains('api/*', VerifyCsrfToken::WEBHOOK_EXCEPTIONS);
        foreach (VerifyCsrfToken::WEBHOOK_EXCEPTIONS as $pattern) {
            $this->assertStringContainsString('webhook', $pattern);
        }

        $router = app('router');
        $webGroup = $router->getMiddlewareGroups()['web'] ?? [];
        $this->assertContains(ValidateCsrfToken::class, $webGroup);
    }

    public function test_review_fraud_alert_request_fails_closed_when_unauthenticated(): void
    {
        $request = ReviewFraudAlertRequest::create('/api/v1/admin/security/fraud-alerts/1/review', 'POST', [
            'action' => 'RELEASE_ALLOW',
            'decision_reason' => 'Attempted unauthenticated bypass',
        ]);
        $request->setUserResolver(static fn () => null);

        $this->assertFalse($request->authorize());
    }

    public function test_fraud_alert_review_enforces_maker_checker_immutability_and_redacted_audit_trail(): void
    {
        $subject = User::factory()->create();
        $reviewer = User::factory()->create();

        $alert = FraudAlert::create([
            'id' => (string) Str::uuid(),
            'idempotency_event_key' => 'FRAUD-TEST-'.Str::uuid(),
            'event_type' => 'WITHDRAWAL',
            'user_id' => (string) $subject->id,
            'amount' => '25000.0000',
            'currency' => 'THB',
            'risk_score' => 75,
            'decision' => FraudDecision::HOLD_FOR_REVIEW->value,
            'reason_codes' => ['HIGH_VELOCITY'],
            'status' => 'OPEN',
        ]);

        $service = app(FraudAlertReviewService::class);

        // 1. Self-review by alert owner MUST fail closed (maker-checker separation of duties).
        try {
            $service->review(
                id: (string) $alert->id,
                reviewerId: (string) $subject->id,
                action: 'RELEASE_ALLOW',
                decisionReason: 'Self-approving my own alert',
            );
            $this->fail('Expected maker-checker DomainException was not thrown.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Maker-checker', $e->getMessage());
        }

        $this->assertSame('OPEN', $alert->fresh()->status);

        // 2. Distinct reviewer adjudicates cleanly and sensitive metadata is redacted in AuditLog.
        $resolved = $service->review(
            id: (string) $alert->id,
            reviewerId: (string) $reviewer->id,
            action: 'REJECT_DENY',
            decisionReason: 'Confirmed mule destination account mismatch',
            metadata: [
                'channel' => 'compliance_console',
                'bank_account_number' => '9998887776',
                'national_id' => '1100700123456',
            ],
        );

        $this->assertSame('RESOLVED_DENY', $resolved->status);

        $audit = AuditLog::query()->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame($reviewer->id, $audit->user_id);
        $this->assertSame('[REDACTED]', $audit->metadata['reviewer_metadata']['bank_account_number'] ?? null);
        $this->assertSame('[REDACTED]', $audit->metadata['reviewer_metadata']['national_id'] ?? null);

        // 3. Re-adjudicating a terminal RESOLVED_* alert MUST fail closed.
        $this->expectException(DomainException::class);
        $service->review(
            id: (string) $alert->id,
            reviewerId: (string) $reviewer->id,
            action: 'RELEASE_ALLOW',
            decisionReason: 'Attempt to flip terminal fraud denial to allow',
        );
    }

    public function test_fraud_risk_gate_enforces_step_up_in_enforce_mode_and_records_in_observe_mode(): void
    {
        $user = User::factory()->create();

        $stepUpEngine = new class implements SecurityRiskEngine
        {
            public function evaluateTransactionRisk(
                string|array $eventType,
                string $userId = '',
                string $amount = '0.0000',
                string $currency = 'THB',
                array $context = [],
            ): FraudDecisionData {
                return new FraudDecisionData(
                    riskScore: 48,
                    decision: FraudDecision::STEP_UP,
                    reasonCodes: ['NEW_DEVICE_HIGH_VALUE'],
                    fraudAlertId: 'alert-step-up-1',
                );
            }

            public function reassessAlert(string $fraudAlertId): FraudDecisionData
            {
                return $this->evaluateTransactionRisk('REASSESS');
            }
        };

        $middleware = new FraudRiskGateMiddleware($stepUpEngine);

        // Enforce mode without step-up proof -> 403 TRANSACTION_STEP_UP_REQUIRED
        config(['fraud.mode' => 'enforce']);
        $reqBlocked = Request::create('/api/v1/withdrawals', 'POST', ['amount' => '5000.0000']);
        $reqBlocked->setUserResolver(static fn () => $user);
        $resBlocked = $middleware->handle($reqBlocked, static fn () => response()->json(['ok' => true]));
        $this->assertSame(403, $resBlocked->getStatusCode());
        $this->assertStringContainsString('TRANSACTION_STEP_UP_REQUIRED', (string) $resBlocked->getContent());

        // Enforce mode WITH verified step-up attribute -> 200 OK
        $reqVerified = Request::create('/api/v1/withdrawals', 'POST', ['amount' => '5000.0000']);
        $reqVerified->setUserResolver(static fn () => $user);
        $reqVerified->attributes->set('step_up_verified', true);
        $resVerified = $middleware->handle($reqVerified, static fn () => response()->json(['ok' => true]));
        $this->assertSame(200, $resVerified->getStatusCode());

        // Observe mode -> non-blocking telemetry attached to request attributes
        config(['fraud.mode' => 'observe']);
        $reqObserve = Request::create('/api/v1/withdrawals', 'POST', ['amount' => '5000.0000']);
        $reqObserve->setUserResolver(static fn () => $user);
        $resObserve = $middleware->handle($reqObserve, static fn () => response()->json(['ok' => true]));
        $this->assertSame(200, $resObserve->getStatusCode());
        $this->assertInstanceOf(FraudDecisionData::class, $reqObserve->attributes->get('fraud_decision_observed'));
    }

    public function test_field_encryption_has_no_hardcoded_fallback_secret_and_fails_closed_when_key_missing(): void
    {
        $source = (string) file_get_contents(app_path('Services/Security/FieldEncryptionService.php'));
        $this->assertStringNotContainsString('32ByteSecretKeyForEnterpriseFintech2026', $source);

        $originalAppKey = config('app.key');
        try {
            config(['app.field_encryption_key' => null, 'app.key' => '']);

            $thrown = false;
            try {
                new FieldEncryptionService(null);
            } catch (RuntimeException) {
                $thrown = true;
            }
            $this->assertTrue($thrown, 'FieldEncryptionService must fail closed when no key material is configured.');
        } finally {
            config(['app.key' => $originalAppKey]);
        }

        $service = new FieldEncryptionService(null);
        $cipher = $service->encrypt('1100700987654');
        $this->assertNotSame('1100700987654', $cipher);
        $this->assertSame('1100700987654', $service->decrypt($cipher));
    }

    public function test_quarantine_strips_executable_extensions_and_audit_service_redacts_pii_and_secrets(): void
    {
        Storage::fake('quarantine');
        $user = User::factory()->create();

        $maliciousUpload = UploadedFile::fake()->create('webshell.php', 4, 'text/plain');
        $record = app(UploadQuarantineService::class)->quarantineUploadedFile(
            file: $maliciousUpload,
            owningUserId: (string) $user->id,
            purpose: 'KYC_DOCUMENT',
        );

        $this->assertSame(MalwareScanStatus::PENDING, $record->scan_status);
        $this->assertStringEndsWith('.bin', $record->private_storage_key);
        $this->assertStringNotContainsString('.php', $record->private_storage_key);

        $redacted = app(AuditLogService::class)->redact([
            'totp_secret' => 'JBSWY3DPEHPK3PXP',
            'otp' => '123456',
            'recovery_code' => 'ABCD-EFGH',
            'webhook_signature' => 't=123,v1=deadbeef',
            'document_number' => 'AA998877',
            'safe_reference' => 'DEP-2026-0001',
        ]);

        $this->assertSame('[REDACTED]', $redacted['totp_secret']);
        $this->assertSame('[REDACTED]', $redacted['otp']);
        $this->assertSame('[REDACTED]', $redacted['recovery_code']);
        $this->assertSame('[REDACTED]', $redacted['webhook_signature']);
        $this->assertSame('[REDACTED]', $redacted['document_number']);
        $this->assertSame('DEP-2026-0001', $redacted['safe_reference']);
    }

    public function test_trusted_proxy_policy_keeps_one_knob_and_a_narrow_header_mask(): void
    {
        // CORRECTED. This method used to construct App\Http\Middleware\TrustProxies and
        // demand a 503 on a '*' configuration. That class no longer exists - by design:
        // config/trustedproxy.php records the policy, the framework's own TrustProxies
        // middleware (already in the default global stack) reads it, and
        // tests/Feature/Http/TrustedProxyResolutionTest.php asserts both the single-knob
        // rule and the real request state. The old assertion also contradicted the
        // documented CDN deployment mode, where '*' is a legitimate, deliberate setting.
        //
        // What still has to be true for security is checked here: the proxy list is not
        // duplicated anywhere, and the believed header mask cannot rewrite the host a
        // signed URL is generated for.
        $config = require config_path('trustedproxy.php');

        self::assertSame(['proxies'], array_keys($config), 'a second knob would drift from the one the middleware reads');

        $mask = (new ReflectionClass(\Illuminate\Http\Middleware\TrustProxies::class))
            ->getStaticPropertyValue('alwaysTrustHeaders');

        self::assertIsInt($mask, 'bootstrap/app.php must pin the header mask');
        self::assertSame(
            0,
            $mask & Request::HEADER_X_FORWARDED_HOST,
            'X-Forwarded-Host must never be believed'
        );
        self::assertSame(
            0,
            $mask & Request::HEADER_X_FORWARDED_PREFIX,
            'X-Forwarded-Prefix must never be believed'
        );
        self::assertNotSame(0, $mask & Request::HEADER_X_FORWARDED_FOR, 'the audit lanes need the client address');
        self::assertNotSame(0, $mask & Request::HEADER_X_FORWARDED_PROTO, 'secure URL generation needs the scheme');
    }
}
