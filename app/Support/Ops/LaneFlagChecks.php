<?php

declare(strict_types=1);

namespace App\Support\Ops;

/**
 * The optional-lane checks, extracted so ProductionPreflight and the tests ask
 * the same questions about the same lanes.
 *
 * A LANE WHOSE CONFIG FILE IS ABSENT IS SKIPPED, NEVER AN ERROR
 * ------------------------------------------------------------------
 * The lanes below ship in different batches: a deployment running this build
 * before the privacy-request or support-ticket lane has landed must still pass
 * preflight. `config('<lane>.enabled')` is therefore read null-safely: when the
 * configuration repository has no such key at all the lane is reported as
 * SKIP, not as FAIL and not as PASS. "Absent" and "off" are both honest
 * not-applicable answers; only an ENABLED lane is checked.
 *
 * WHEN A LANE IS ON, ITS PREREQUISITES ARE REAL
 * ---------------------------------------------
 * An enabled lane with a missing prerequisite is a FAIL, because the lane will
 * answer 501/500 to real users rather than degrading quietly:
 *   - legal_pdf: the font directory exists and the mPDF class is installed;
 *   - payout_illustration: the authoritative multiplier source is declared;
 *   - privacy_requests: the compliance mailbox is configured;
 *   - support_tickets: the reply-from address is configured.
 */
final class LaneFlagChecks
{
    /**
     * @return list<CheckResult>
     */
    public function run(): array
    {
        return [
            $this->legalPdf(),
            $this->payoutIllustration(),
            $this->privacyRequests(),
            $this->supportTickets(),
        ];
    }

    public function legalPdf(): CheckResult
    {
        $enabled = $this->enabled('legal_pdf');

        if ($enabled === null) {
            return CheckResult::skip('legal_pdf', 'configuration not present (skipped)');
        }

        if (! $enabled) {
            return CheckResult::skip('legal_pdf', 'disabled (skipped)');
        }

        $fontDir = (string) config('legal_pdf.font_dir', '');
        $missing = [];

        if ($fontDir === '' || ! is_dir($fontDir)) {
            $missing[] = 'font directory';
        }

        if (! class_exists(\Mpdf\Pdf::class)) {
            $missing[] = 'mpdf/mpdf package';
        }

        $tempPath = (string) config('legal_pdf.temp_path', '');

        if ($tempPath !== '' && is_dir($tempPath) && ! is_writable($tempPath)) {
            $missing[] = 'writable mPDF temp directory';
        }

        return $missing === []
            ? CheckResult::pass('legal_pdf', 'fonts and PDF renderer are available')
            : CheckResult::fail('legal_pdf', 'enabled but missing: '.implode(', ', $missing));
    }

    public function payoutIllustration(): CheckResult
    {
        $enabled = $this->enabled('payout_illustration');

        if ($enabled === null) {
            return CheckResult::skip('payout_illustration', 'configuration not present (skipped)');
        }

        if (! $enabled) {
            return CheckResult::skip('payout_illustration', 'disabled (skipped)');
        }

        $source = config('lottery.payouts.multiplier_source');
        $sourceKey = is_string($source) ? trim($source) : '';
        $missing = [];

        if ($sourceKey === '' || config($sourceKey) === null) {
            $missing[] = 'authoritative multiplier source';
        }

        $currency = (string) config('payout_illustration.currency', '');

        if ($currency === '') {
            $missing[] = 'display currency';
        }

        return $missing === []
            ? CheckResult::pass('payout_illustration', 'published multiplier source is configured')
            : CheckResult::fail('payout_illustration', 'enabled but missing: '.implode(', ', $missing));
    }

    public function privacyRequests(): CheckResult
    {
        $enabled = $this->enabled('privacy_requests');

        if ($enabled === null) {
            return CheckResult::skip('privacy_requests', 'configuration not present (skipped)');
        }

        if (! $enabled) {
            return CheckResult::skip('privacy_requests', 'disabled (skipped)');
        }

        $mailbox = (string) config('privacy_requests.compliance_email', '');

        return trim($mailbox) !== ''
            ? CheckResult::pass('privacy_requests', 'compliance mailbox is configured')
            : CheckResult::fail('privacy_requests', 'enabled but PRIVACY_COMPLIANCE_EMAIL is not set');
    }

    public function supportTickets(): CheckResult
    {
        $enabled = $this->enabled('support_tickets');

        if ($enabled === null) {
            return CheckResult::skip('support_tickets', 'configuration not present (skipped)');
        }

        if (! $enabled) {
            return CheckResult::skip('support_tickets', 'disabled (skipped)');
        }

        $from = (string) config('support_tickets.reply_from_address', '');

        return trim($from) !== ''
            ? CheckResult::pass('support_tickets', 'reply-from address is configured')
            : CheckResult::fail('support_tickets', 'enabled but SUPPORT_REPLY_FROM_ADDRESS is not set');
    }

    /**
     * The lane's master switch, read null-safely.
     *
     * Returns true when the lane is on, false when it is explicitly off, and
     * null when this deployment carries no configuration for the lane at all —
     * which is the "not present yet" case the caller reports as a skip.
     */
    private function enabled(string $lane): ?bool
    {
        if (! is_array(config($lane))) {
            return null;
        }

        $flag = config($lane.'.enabled');

        return is_bool($flag) ? $flag : (bool) $flag;
    }
}
