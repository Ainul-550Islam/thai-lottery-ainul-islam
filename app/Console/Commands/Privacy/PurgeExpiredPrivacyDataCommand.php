<?php

declare(strict_types=1);

namespace App\Console\Commands\Privacy;

use App\Models\DataSubjectRequest;
use App\Models\PersonalDataExport;
use App\Models\PrivacyVerificationToken;
use App\Services\Privacy\PrivacyVerificationService;
use App\Services\Privacy\PersonalDataExporter;
use App\Services\Privacy\PrivacyRequestService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * `privacy:purge-expired` — the retention clock, actually ticking.
 *
 * THREE SWEEPS, IN THIS ORDER
 * 1. Archives whose expiry has passed: the FILE is deleted first, then the row
 *    is marked expired. The other order would leave a personal-data file on disk
 *    with a row that says it is gone.
 * 2. Requests that never verified: past the verification window they move to
 *    Expired, with a timeline entry. An unverified request is a claim about an
 *    address nobody proved, so keeping it alive forever is a growing record of
 *    claims.
 * 3. Tokens that are either consumed or past their expiry: their hash is a
 *    credential-shaped string with no remaining use.
 *
 * --dry-run reports the same counts and touches nothing. The command prints
 * COUNTS ONLY: a scheduler log line naming a reference or an address would move
 * personal data into a log file with a different retention policy.
 */
final class PurgeExpiredPrivacyDataCommand extends Command
{
    protected $signature = 'privacy:purge-expired {--dry-run : Report what would be removed without changing anything}';

    protected $description = 'Delete expired personal-data archives, expire unverified requests and retire spent verification tokens.';

    public function handle(
        PersonalDataExporter $exporter,
        PrivacyRequestService $requests,
    ): int {
        if (! (bool) config('privacy_requests.enabled', false)) {
            // OFF IS OFF. The command is scheduled unconditionally so a
            // deployment that turns the lane on does not also have to remember
            // to deploy a schedule change; but with the lane off there is
            // nothing this lane owns to purge, and sweeping another lane's
            // storage would be a bug with a scary name.
            $this->info('privacy requests are not configured; nothing to purge');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $archives = PersonalDataExport::query()
            ->where(function ($query): void {
                $query->where('status', '!=', PersonalDataExport::STATUS_EXPIRED)
                    ->where('expires_at', '<', Carbon::now());
            })
            ->orderBy('id')
            ->get();

        $filesDeleted = 0;
        $rowsExpired = 0;

        foreach ($archives as $archive) {
            $fileExisted = $exporter->purge($archive, $dryRun);

            if ($fileExisted) {
                $filesDeleted++;
            }

            if (! $dryRun) {
                $rowsExpired++;
            }
        }

        $staleRequests = DataSubjectRequest::query()
            ->where('status', 'pending_verification')
            ->where('created_at', '<', Carbon::now()->subMinutes($this->verificationTtlMinutes()))
            ->orderBy('id')
            ->get();

        $requestsExpired = 0;

        foreach ($staleRequests as $request) {
            if ($dryRun) {
                $requestsExpired++;

                continue;
            }

            $requests->expireUnverified($request, 'verification_window_closed');
            $requestsExpired++;
        }

        $tokensRetired = $dryRun
            ? PrivacyVerificationToken::query()
                ->where(function ($query): void {
                    $query->whereNotNull('consumed_at')
                        ->orWhere('expires_at', '<', Carbon::now());
                })
                ->count()
            : app(PrivacyVerificationService::class)->retireSpent();

        $this->info(sprintf(
            'archives: %d expired row(s), %d file(s) %s',
            $rowsExpired,
            $filesDeleted,
            $dryRun ? 'would be removed' : 'removed',
        ));
        $this->info(sprintf('unverified requests: %d expired', $requestsExpired));
        $this->info(sprintf('verification tokens: %d %s', (int) $tokensRetired, $dryRun ? 'would be retired' : 'retired'));

        return self::SUCCESS;
    }

    private function verificationTtlMinutes(): int
    {
        return max(5, (int) config('privacy_requests.verification_ttl_minutes', 1440));
    }
}
