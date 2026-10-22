<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Enums\Currency;
use App\Enums\KycStatus;
use App\Models\KycDocument;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Compliance\WithdrawalKycGateService as RequestGate;
use App\Services\Withdrawal\WithdrawalKycGateService as ApprovalGate;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two withdrawal KYC gates must answer the same question the same way.
 *
 * WHY A SEPARATE SUITE FOR "AGREEMENT"
 * There are two implementations of one policy in this codebase.
 * Services\Compliance\WithdrawalKycGateService is asked at REQUEST time, by
 * WithdrawalService; Services\Withdrawal\WithdrawalKycGateService is asked at the moment
 * money leaves, by WithdrawalApprovalService::approve(). Each has its own suite, and each
 * was green. Both suites only ever tested their own gate, so nothing in the repository
 * compared the two answers - which is how they drifted apart while both stayed green.
 *
 * Neither gate reads a summary column. Both read kyc_documents, but through two different
 * sentences: the request side asked User::kycStatus(), meaning "VERIFIED if ANY document
 * carries the Verified status", while the money gate asks for "the latest REVIEWED document,
 * unless it is Expired or Rejected". Different rows satisfy those two sentences - a
 * verification nobody timestamped, and a revocation that "ever verified" keeps honouring - and
 * every case below is one of those rows. The consequence was never a wrong payment: the money
 * gate is the stricter of the two, so it refused. It was that the app told a player one thing
 * and did another: the withdrawal was accepted, their funds were reserved, and a human lane
 * that had been disagreeing with the screen the whole time refused it afterwards.
 *
 * This suite is the boundary test neither suite contained: it drives one state through both
 * gates and asserts they agree. Where they DELIBERATELY do not, the disagreement is asserted
 * on purpose, with the reason next to it, so it stays a decision rather than becoming an
 * accident.
 *
 * WHY DatabaseTruncation AND NOT RefreshDatabase
 * Not a style choice. WithdrawalKycGateService::gate() opens with
 * `if (DB::transactionLevel() > 0) throw ...`, because the money gate owns its own
 * transaction boundary and refuses to be nested inside somebody else's. RefreshDatabase
 * wraps every test in a transaction, so calling gate() - which this suite must do, since
 * "both gates agree" is the claim under test - would throw on the boundary guard and the
 * suite would pass while never having asked the money gate anything at all. Truncation
 * commits normally, so the guard is satisfied and the comparison is real.
 *
 * A first draft of this file did use RefreshDatabase, and the very first test failed with
 * "Withdrawal KYC gating owns its transaction boundary" rather than silently going green.
 */
final class KycGateAgreementTest extends TestCase
{
    use DatabaseTruncation;

    private const ABOVE = '9000.00';

    private const BELOW = '100.00';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'finance.withdrawal.kyc_gate_enabled' => true,
            'finance.withdrawal.kyc_gate_threshold' => '5000.00',
            'finance.withdrawal.kyc_gate_audit' => false,
        ]);
    }

    private function requestGate(): RequestGate
    {
        return app(RequestGate::class);
    }

    private function approvalGate(): ApprovalGate
    {
        return app(ApprovalGate::class);
    }

    private function player(KycStatus $aggregate): User
    {
        return User::factory()->create(['kyc_status' => $aggregate]);
    }

    /**
     * A document in the shape the real review flow leaves it.
     *
     * verified_at is written by KycVerificationService::decide() for APPROVALS AND
     * REJECTIONS alike, so "has a verified_at" is not evidence of approval on its own and
     * the status has to be carried here too - which is exactly the trap the approval gate's
     * resolver navigates, and why these fixtures set both fields instead of one.
     */
    private function document(
        User $user,
        KycStatus $status,
        bool $reviewed = true,
        ?\DateTimeInterface $expiresAt = null,
        ?\DateTimeInterface $reviewedAt = null,
    ): KycDocument {
        // verified_at defaults to a DISTINCT, strictly ordered value per call. Two documents
        // stamped with the same now() would leave the approval resolver's
        // orderByDesc('verified_at') with an arbitrary tie, and a test whose outcome depends on
        // an unspecified row order is a test that changes its mind with the next index.
        $reviewedAt ??= now()->addMinutes(random_int(1, 50_000));
        return KycDocument::query()->create([
            'user_id' => $user->getKey(),
            'document_type' => 'national_id',
            'document_number' => '1234567890'.random_int(100, 999),
            'file_path' => 'kyc/agreement/'.$user->getKey().'.bin',
            'original_filename' => 'id.png',
            'mime_type' => 'image/png',
            'file_size' => 1024,
            'status' => $status,
            'verified_at' => $reviewed ? $reviewedAt : null,
            'expires_at' => $expiresAt,
        ]);
    }

    // ------------------------------------------------------------------
    // The states both gates must answer identically
    // ------------------------------------------------------------------

    #[Test]
    public function a_cleanly_verified_player_is_answered_the_same_way_by_both_gates(): void
    {
        $player = $this->player(KycStatus::Verified);
        $this->document($player, KycStatus::Verified);

        $this->assertTrue(
            $this->requestGate()->mayWithdraw($player, self::ABOVE, Currency::THB),
            'The request gate refused a player the approval gate would have honoured.',
        );

        $withdrawal = $this->withdrawalFor($player, self::ABOVE);

        $this->assertTrue($this->approvalGate()->isMandatoryFor($withdrawal),
            'If the amount did not reach the floor, the agreement above proves nothing.');

        $outcome = $this->approvalGate()->gate($withdrawal);
        $this->assertSame('passed', $outcome['verdict'], 'Both gates must let a clean player through.');
    }

    #[Test]
    public function an_unverified_player_is_refused_by_both_gates(): void
    {
        $player = $this->player(KycStatus::Unverified);
        $this->document($player, KycStatus::Pending, reviewed: false);

        $this->assertFalse($this->requestGate()->mayWithdraw($player, self::ABOVE, Currency::THB));

        $this->assertSame(
            'unverified',
            $this->approvalGate()->kycStandingFor((int) $player->getKey())['state'],
        );
    }

    #[Test]
    public function the_threshold_is_honoured_identically_below_the_floor(): void
    {
        $player = $this->player(KycStatus::Unverified);

        // Below the floor the request gate must not ask about identity at all - and the
        // approval gate agrees it is not mandatory. A half that gates a 100.00 withdrawal
        // would be the same drift in the other direction.
        $this->assertFalse($this->requestGate()->requiresKyc(self::BELOW, Currency::THB));
        $this->assertFalse($this->approvalGate()->isMandatoryFor($this->withdrawalFor($player, self::BELOW)));

        $this->assertTrue($this->requestGate()->mayWithdraw($player, self::BELOW, Currency::THB),
            'An unverified player may still withdraw below the floor.');
    }

    // ------------------------------------------------------------------
    // The drift this suite exists to close
    // ------------------------------------------------------------------

    #[Test]
    public function a_verification_nobody_reviewed_cannot_open_the_request_gate(): void
    {
        // Status Verified with verified_at NULL: an import, a seed, a hand-corrected row.
        // KycVerificationService::decide() is the only writer that sets both together, so the
        // approval gate reads a missing timestamp as "nobody actually looked at this" and
        // refuses. The accessor asked only about the status and passed, so the player could
        // place the withdrawal, have their balance reserved, and be refused afterwards.
        $player = $this->player(KycStatus::Verified);
        $this->document($player, KycStatus::Verified, reviewed: false);

        $this->assertTrue(
            $player->kycStatus()->isVerified(),
            'The fixture must be one the OLD accessor passed, or this test pins nothing.',
        );

        $this->assertNotSame(
            'verified',
            $this->approvalGate()->kycStandingFor((int) $player->getKey())['state'],
        );

        $this->assertFalse(
            $this->requestGate()->mayWithdraw($player, self::ABOVE, Currency::THB),
            'The request gate answered more permissively than the gate that moves the money.',
        );
    }

    #[Test]
    public function a_summary_that_survives_its_document_being_refused_no_longer_opens_the_door(): void
    {
        // The mirror-image drift, and the one with money on it: the summary still reads
        // VERIFIED while the paper behind that decision has been Rejected. The request gate
        // used to wave this through; the approval gate refuses it. That combination is a
        // withdrawal that reserves a player's balance and then cannot be honoured.
        $player = $this->player(KycStatus::Verified);
        $this->document($player, KycStatus::Rejected);

        $this->assertFalse(
            $this->requestGate()->mayWithdraw($player, self::ABOVE, Currency::THB),
            'A refused document must not be outranked by a stale summary.',
        );

        $this->assertNotSame(
            'verified',
            $this->approvalGate()->kycStandingFor((int) $player->getKey())['state'],
            'If the approval gate called this verified, the assertion above would be pinning the wrong rule.',
        );
    }

    #[Test]
    public function an_expired_document_refuses_at_request_and_at_approval(): void
    {
        $player = $this->player(KycStatus::Verified);
        $this->document($player, KycStatus::Expired, expiresAt: now()->subDay());

        $this->assertFalse($this->requestGate()->mayWithdraw($player, self::ABOVE, Currency::THB));
        $this->assertSame('expired', $this->approvalGate()->kycStandingFor((int) $player->getKey())['state']);
    }

    // ------------------------------------------------------------------
    // Deliberate asymmetry, stated
    // ------------------------------------------------------------------

    #[Test]
    public function a_revocation_after_a_verification_closes_the_door_at_request_too(): void
    {
        // The realistic shape: verified in January, refused in March. The approval gate looks
        // at the latest REVIEWED document - the rejection - and refuses. The accessor asked
        // whether ANY document was ever verified, so it kept saying yes for as long as the old
        // row existed, which is a revocation that never takes effect at the one moment a player
        // can still be stopped cheaply.
        $player = $this->player(KycStatus::Verified);
        $this->document($player, KycStatus::Verified, reviewedAt: now()->subMonth());
        $this->document($player, KycStatus::Rejected, reviewedAt: now());

        $this->assertTrue($player->kycStatus()->isVerified(),
            'The accessor still waves this through - that is the defect, asserted first.');

        $this->assertNotSame(
            'verified',
            $this->approvalGate()->kycStandingFor((int) $player->getKey())['state'],
            'If approval considered this verified, the assertion below pins the wrong rule.',
        );

        $this->assertFalse(
            $this->requestGate()->mayWithdraw($player, self::ABOVE, Currency::THB),
            'A revoked player could still place an above-threshold withdrawal.',
        );
    }

    #[Test]
    public function no_paper_at_all_is_refused_by_both_and_needs_no_fallback(): void
    {
        // Asserted because there is deliberately no fallback to the accessor. An earlier draft
        // of this fix added one; it could not have changed an answer - with no documents the
        // accessor says Unverified and the resolver says missing, and both refuse - so the
        // branch was a second rule bought for none of the benefit. This test is what proves it
        // was redundant rather than merely unused.
        $player = $this->player(KycStatus::Verified);

        $this->assertSame('missing', $this->approvalGate()->kycStandingFor((int) $player->getKey())['state']);
        $this->assertFalse($this->requestGate()->mayWithdraw($player, self::ABOVE, Currency::THB));

        $blank = $this->player(KycStatus::Unverified);
        $this->assertFalse($this->requestGate()->mayWithdraw($blank, self::ABOVE, Currency::THB));
    }

    // ------------------------------------------------------------------
    // A broken config must not make one half more welcoming than the other
    // ------------------------------------------------------------------

    #[Test]
    public function an_unusable_threshold_gates_everything_on_both_sides(): void
    {
        // Neither a string nor a number: e.g. a YAML/array value, or a null pushed straight
        // into config. The approval gate's rule is "treat as zero, always mandatory". The
        // request gate used to fall back to its own preferred 5000.00, so with a broken
        // config the screen accepted what the money lane refused to even consider.
        config(['finance.withdrawal.kyc_gate_threshold' => null]);

        $this->assertSame('0.00', $this->requestGate()->threshold());
        $this->assertTrue($this->requestGate()->requiresKyc(self::BELOW, Currency::THB),
            'A broken floor must gate the smallest withdrawal too.');

        $player = $this->player(KycStatus::Unverified);
        $this->assertTrue($this->approvalGate()->isMandatoryFor($this->withdrawalFor($player, self::BELOW)));
        $this->assertFalse($this->requestGate()->mayWithdraw($player, self::BELOW, Currency::THB),
            'Both halves must refuse the same way when the floor is unreadable.');
    }

    #[Test]
    public function a_disabled_gate_disables_both_halves(): void
    {
        config(['finance.withdrawal.kyc_gate_enabled' => false]);

        $player = $this->player(KycStatus::Unverified);

        $this->assertFalse($this->requestGate()->isEnabled());
        $this->assertFalse($this->approvalGate()->isEnabled());

        // Killing the gate must not strand a player: below the floor AND above it, the
        // request goes through, because a switch that disables only one half is worse than
        // no switch at all.
        $this->assertTrue($this->requestGate()->mayWithdraw($player, self::ABOVE, Currency::THB));
        $this->assertFalse($this->approvalGate()->isMandatoryFor($this->withdrawalFor($player, self::ABOVE)));
    }

    // ------------------------------------------------------------------
    // The refusal text carries the reason, not just the field it read
    // ------------------------------------------------------------------

    #[Test]
    public function a_refusal_says_which_authority_answered_and_with_what(): void
    {
        $player = $this->player(KycStatus::Verified);
        $this->document($player, KycStatus::Verified, reviewedAt: now()->subMonth());
        $this->document($player, KycStatus::Rejected, reviewedAt: now());

        $reason = $this->requestGate()->refusalReasonFor($player, self::ABOVE, Currency::THB);

        // 'unverified' is the shared resolver's own word for a latest reviewed document that
        // was Rejected: it separates expiry from everything else and lumps refusal in with
        // never-having-been-verified. Asserted as the resolver speaks, not as this test would
        // prefer it to.
        $this->assertStringContainsString('unverified', $reason);
        $this->assertStringContainsString('(document #', $reason,
            'The refusal must name the row it rested on, or an operator cannot go and look.');
        $this->assertStringContainsString('5000.00', $reason);
        $this->assertStringNotContainsString('aggregate', $reason,
            'The old wording blamed a summary column this gate never reads.');
    }

    // ------------------------------------------------------------------
    // Fixture
    // ------------------------------------------------------------------

    /**
     * A real Withdrawal row, because the approval gate's contract takes a withdrawal rather
     * than an amount - which is itself part of why the two gates drifted: one is asked about
     * money, the other about a request. Built through the factory so every NOT NULL column is
     * satisfied by the same definition production uses, with the wallet pinned to THIS player
     * so nothing about the fixture depends on which row the factory happened to create.
     */
    private function withdrawalFor(User $user, string $amount): Withdrawal
    {
        $wallet = Wallet::factory()->create([
            'user_id' => $user->getKey(),
            'currency' => Currency::THB,
        ]);

        return Withdrawal::factory()->create([
            'user_id' => $user->getKey(),
            'wallet_id' => $wallet->getKey(),
            'amount' => $amount,
            'net_amount' => $amount,
        ]);
    }
}
