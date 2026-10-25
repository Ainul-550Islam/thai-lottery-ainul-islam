<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Exceptions\Privacy\PrivacyRequestException;
use App\Models\DataSubjectRequest;
use App\Models\DataSubjectRequestEvent;
use App\Models\PersonalDataExport;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Builds the archive a data-subject request is answered with.
 *
 * =========================================================================
 * THE HARD PARTS, IN ORDER OF HOW BADLY THEY GO WRONG
 * =========================================================================
 * 1. WHOSE DATA. The archive is assembled for exactly ONE account. A linked
 *    user_id wins; otherwise the request's verified address must match exactly
 *    one account. Zero matches and two matches are BOTH resolved the same way:
 *    the request moves to NeedsInformation with a note, nothing is exported, and
 *    the staff member is told to ask a human which account is meant. Guessing -
 *    "the newest account", "the one with the most activity" - would mail one
 *    person another person's financial history.
 *
 * 2. WHAT IS IN IT. Every dataset below is filtered by the resolved user's own
 *    id or address. Nothing is read from a table that describes other people.
 *    Identity DOCUMENTS are never opened (no file_path, no document_number),
 *    because an emailed ZIP is the weakest possible place for a passport scan.
 *    Fraud, AML and compliance rows are never read either: they are the
 *    platform's risk record, not the person's copy of their own data, and the
 *    README says so in plain language rather than leaving a silent gap.
 *
 * 3. HOW BIG IT IS. Rows stream: each dataset is written one chunk at a time
 *    from a database cursor into an open file handle, so a person with years of
 *    bets does not need to fit in memory. The ZIP is then assembled from the
 *    finished files.
 *
 * 4. WHETHER THE FILE THAT COMES BACK IS THE FILE THAT WAS MADE. sha256 of the
 *    archive is recorded on the export row at build time and re-computed before
 *    every download. A truncated or replaced file is refused, not served with a
 *    shrug.
 *
 * =========================================================================
 * WHAT THIS CLASS DOES NOT DO
 * =========================================================================
 * It does not mail anything, it does not release the archive and it does not
 * delete a request. Building is a machine task; releasing is a human decision
 * with a signed, expiring link (PrivacyRequestService::release(); the panel
 * action). The temp directory is always removed, including on failure.
 */
final class PersonalDataExporter
{
    private const CHUNK = 500;

    public function __construct(
        private readonly PrivacyRequestService $requests,
        private readonly PrivacyRetentionGuard $retention,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Build (and record) the archive for one verified request.
     *
     * @throws PrivacyRequestException when the request is unverified, or no
     *                                 single account can be resolved for it.
     */
    public function buildFor(DataSubjectRequest $request): PersonalDataExport
    {
        if (! $this->requests->enabled()) {
            throw PrivacyRequestException::disabled();
        }

        if (! $request->isVerified()) {
            throw PrivacyRequestException::notVerified();
        }

        $user = $this->resolveAccount($request);

        $request->refresh();

        $export = PersonalDataExport::query()->create([
            'request_id' => (int) $request->getKey(),
            'user_id' => (int) $user->getKey(),
            'disk' => (string) config('privacy_requests.export.disk', 'local'),
            'path' => 'pending',
            'sha256' => hash('sha256', ''),
            'size_bytes' => 0,
            'status' => PersonalDataExport::STATUS_BUILDING,
            'expires_at' => Carbon::now()->addDays($this->ttlDays()),
            'max_downloads' => max(1, (int) config('privacy_requests.export.max_downloads', 3)),
        ]);

        $workdir = null;
        $zipPath = null;

        try {
            $workdir = $this->workDirectory();
            $locale = in_array($request->locale, ['en', 'th', 'bn'], true) ? $request->locale : 'en';

            $this->writeProfile($workdir, $user);
            $this->writeWallets($workdir, $user);
            $this->writeTransactions($workdir, $user);
            $this->writeDeposits($workdir, $user);
            $this->writeWithdrawals($workdir, $user);
            $this->writeBets($workdir, $user);
            $this->writeConsents($workdir, $user);
            $this->writeKycStatus($workdir, $user);
            $this->writeNotificationPreferences($workdir, $user);
            $this->writeSecurityEvents($workdir, $user);
            $this->writeSupportMessages($workdir, $user);
            $this->writePrivacyRequests($workdir, $user);
            $this->writeReadme($workdir, $request, $user, $locale);

            $zipPath = $workdir.'/archive.zip';
            $this->zip($workdir, $zipPath);

            $digest = hash_file('sha256', $zipPath);
            $size = filesize($zipPath);

            if (! is_string($digest) || $size === false) {
                throw new RuntimeException('The archive could not be hashed.');
            }

            $path = $this->storagePath();

            $stream = fopen($zipPath, 'rb');

            if ($stream === false) {
                throw new RuntimeException('The archive could not be read back.');
            }

            try {
                Storage::disk($export->disk)->put($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $export->path = $path;
            $export->sha256 = $digest;
            $export->size_bytes = (int) $size;
            $export->status = PersonalDataExport::STATUS_READY;
            $export->save();

            $this->requests->recordEvent(
                $request,
                DataSubjectRequestEvent::EVENT_EXPORT_BUILT,
                DataSubjectRequestEvent::ACTOR_SYSTEM,
                null,
                'archive_sha256:'.substr($digest, 0, 16),
            );

            $this->audit->log(
                userId: null,
                action: AuditAction::DataExport,
                riskLevel: RiskLevel::High,
                auditable: $request,
                description: 'A personal-data export archive was built.',
                metadata: [
                    'reference' => $request->reference,
                    'export_id' => (int) $export->getKey(),
                    'size_bytes' => (int) $size,
                ],
            );

            return $export->refresh();
        } catch (Throwable $exception) {
            $export->status = PersonalDataExport::STATUS_FAILED;
            $export->save();

            $this->requests->recordEvent(
                $request,
                DataSubjectRequestEvent::EVENT_EXPORT_FAILED,
                DataSubjectRequestEvent::ACTOR_SYSTEM,
                null,
                $exception::class,
            );

            Log::error('privacy_export_build_failed', [
                'reference' => $request->reference,
                'exception' => $exception::class,
            ]);

            throw $exception;
        } finally {
            $this->cleanup($workdir);
        }
    }

    /**
     * Resolve the ONE account a request is about, or refuse without guessing.
     *
     * A linked user_id wins. Otherwise the address must match exactly one
     * account. Zero or several matches move the request to NeedsInformation -
     * a reviewer asks a human which account is meant - and the build is refused.
     */
    public function resolveAccount(DataSubjectRequest $request): User
    {
        if ($request->user_id !== null) {
            $user = User::query()->find((int) $request->user_id);

            if ($user instanceof User) {
                return $user;
            }
        }

        $email = DataSubjectRequest::normaliseEmail((string) $request->email_encrypted);

        $matches = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->limit(2)
            ->get();

        if ($matches->count() === 1) {
            $user = $matches->first();

            if ($user instanceof User) {
                $request->user_id = (int) $user->getKey();
                $request->saveQuietly();

                $this->requests->recordEvent(
                    $request,
                    DataSubjectRequestEvent::EVENT_NOTE,
                    DataSubjectRequestEvent::ACTOR_SYSTEM,
                    null,
                    'account_linked:single_email_match',
                );

                return $user;
            }
        }

        $this->flagUnresolved($request, $matches->count());

        throw PrivacyRequestException::accountUnresolved($matches->count());
    }

    /**
     * Announce a built archive to the requester.
     *
     * THE ONLY THING THAT SENDS THE LINK, and it is deliberately a separate,
     * staff-initiated step: the mail carries a signed URL that downloads
     * everything the platform holds about a person, so it is released by a human
     * after the metadata on the panel has been looked at - not automatically by
     * the job that assembled it.
     *
     * Refuses an archive that is not ready, has expired, or has already been
     * released, so a double click cannot produce a second link or reset a
     * download counter that a previous mail already spent.
     */
    public function release(PersonalDataExport $export): PersonalDataExport
    {
        $request = $export->request;

        if (! $request instanceof DataSubjectRequest) {
            throw PrivacyRequestException::exportNotReady((string) $export->getKey());
        }

        if (! $request->isVerified()) {
            throw PrivacyRequestException::notVerified();
        }

        if (! $export->isReady() || $export->isExpired()) {
            throw PrivacyRequestException::exportNotReady((string) $request->reference);
        }

        if ($export->isReleased()) {
            throw PrivacyRequestException::exportNotReady((string) $request->reference);
        }

        if (! $this->verifyDigest($export)) {
            // The row says ready but the bytes no longer match their digest: do
            // not mail a link to a file that has been altered or truncated.
            throw PrivacyRequestException::exportFileMissing((string) $export->path);
        }

        $export->released_at = Carbon::now();
        $export->save();

        $this->requests->recordEvent(
            $request,
            DataSubjectRequestEvent::EVENT_EXPORT_RELEASED,
            DataSubjectRequestEvent::ACTOR_STAFF,
            null,
            'released_export:'.(int) $export->getKey(),
        );

        $this->requests->mailExportReady($request, $export);

        $this->audit->log(
            userId: null,
            action: AuditAction::DataExport,
            riskLevel: RiskLevel::High,
            auditable: $request,
            description: 'A personal-data export archive was released to the requester.',
            metadata: [
                'reference' => $request->reference,
                'export_id' => (int) $export->getKey(),
            ],
        );

        return $export->refresh();
    }

    /**
     * Re-check the archive's digest before it is delivered.
     *
     * Streamed, so a large archive is not read into memory.
     */
    public function verifyDigest(PersonalDataExport $export): bool
    {
        $disk = Storage::disk($export->disk);

        if (! $disk->exists($export->path)) {
            return false;
        }

        $stream = $disk->readStream($export->path);

        if (! is_resource($stream)) {
            return false;
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            $digest = hash_final($context);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return hash_equals((string) $export->sha256, $digest);
    }

    /**
     * Delete one expired (or retired) archive and mark the row.
     *
     * Returns true when a file was actually removed. Idempotent: a second call
     * finds the file gone and only re-marks the row.
     */
    public function purge(PersonalDataExport $export, bool $dryRun = false): bool
    {
        $disk = Storage::disk($export->disk);
        $fileExisted = $disk->exists($export->path);

        if ($dryRun) {
            return $fileExisted;
        }

        if ($fileExisted) {
            $disk->delete($export->path);
        }

        $export->status = PersonalDataExport::STATUS_EXPIRED;
        $export->save();

        return $fileExisted;
    }

    /**
     * The retention answer for an erasure/restriction request, for the panel.
     *
     * Kept on this service so the panel has one collaborator for the whole
     * "answer the request" workflow, and so the guard itself stays a pure
     * reader with no idea a UI exists.
     *
     * @return array{
     *     blocked: bool,
     *     user_id: int|null,
     *     reasons: list<array{code: string, label_key: string, detail: array<string, string|int>}>
     * }
     */
    public function retentionReport(DataSubjectRequest $request): array
    {
        return $this->retention->evaluate($request);
    }

    /**
     * Move an unbuildable request to NeedsInformation and say why.
     */
    private function flagUnresolved(DataSubjectRequest $request, int $matches): void
    {
        $note = $matches === 0
            ? 'account_unresolved:no_match'
            : 'account_unresolved:ambiguous';

        $this->requests->recordEvent(
            $request,
            DataSubjectRequestEvent::EVENT_NOTE,
            DataSubjectRequestEvent::ACTOR_SYSTEM,
            null,
            $note,
        );

        if ($request->status->canTransitionTo(\App\Enums\PrivacyRequestStatus::NeedsInformation)) {
            $this->requests->transition(
                $request,
                \App\Enums\PrivacyRequestStatus::NeedsInformation,
                DataSubjectRequestEvent::ACTOR_SYSTEM,
                null,
                $note,
                false,
            );
        }
    }

    // =====================================================================
    // Datasets. Each one writes ONE file about ONE person.
    // =====================================================================

    private function writeProfile(string $dir, User $user): void
    {
        // Whitelisted columns: no password hash, no remember token, no internal
        // risk flags. "Everything we hold about a person" is not the same as
        // "every column on the row".
        $profile = [
            'reference' => 'profile',
            'name' => (string) $user->name,
            'username' => (string) $user->username,
            'email' => (string) $user->email,
            'phone' => $user->phone,
            'date_of_birth' => $user->date_of_birth?->format('Y-m-d'),
            'gender' => $user->gender,
            'city' => $user->city,
            'country' => $user->country,
            'nationality' => $user->nationality,
            'status' => $user->status?->value,
            'kyc_status' => $user->kyc_status?->value,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'phone_verified_at' => $user->phone_verified_at?->toIso8601String(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
            'preferences' => is_array($user->preferences) ? $user->preferences : [],
        ];

        $this->writeDocument($dir.'/profile.json', $profile);
    }

    private function writeWallets(string $dir, User $user): void
    {
        $this->writeRows(
            $dir.'/wallets.json',
            DB::table('wallets')->where('user_id', (int) $user->getKey()),
            [
                'type',
                'status',
                'currency',
                'balance',
                'locked_balance',
                'total_deposited',
                'total_withdrawn',
                'total_wagered',
                'total_won',
                'created_at',
                'updated_at',
            ],
        );

        $walletIds = DB::table('wallets')->where('user_id', (int) $user->getKey())->pluck('id')->all();

        if ($walletIds === [] || ! $this->tableExists('ledger_entries')) {
            return;
        }

        // Ledger lines for the person's OWN wallets only. account_id is dropped:
        // it identifies a platform ledger account, not the person.
        $this->writeRows(
            $dir.'/ledger.json',
            DB::table('ledger_entries')->whereIn('wallet_id', $walletIds),
            [
                'type',
                'direction',
                'amount',
                'currency',
                'balance_after',
                'description',
                'posted_at',
                'created_at',
            ],
        );
    }

    private function writeTransactions(string $dir, User $user): void
    {
        if (! $this->tableExists('financial_transactions')) {
            return;
        }

        $this->writeRows(
            $dir.'/transactions.json',
            DB::table('financial_transactions')->where('user_id', (int) $user->getKey()),
            [
                'reference_number',
                'type',
                'status',
                'currency',
                'amount',
                'fee',
                'description',
                'processed_at',
                'created_at',
            ],
        );
    }

    private function writeDeposits(string $dir, User $user): void
    {
        if (! $this->tableExists('deposits')) {
            return;
        }

        $this->writeRows(
            $dir.'/deposits.json',
            DB::table('deposits')->where('user_id', (int) $user->getKey()),
            [
                'reference_number',
                'method',
                'provider',
                'status',
                'currency',
                'amount',
                'fee',
                'net_amount',
                'confirmed_at',
                'failed_at',
                'created_at',
            ],
        );
    }

    /**
     * Withdrawals with a MASKED destination.
     *
     * The payout details are the person's own data, but an archive sitting in a
     * mail-linked ZIP is a far weaker place than the encrypted column they came
     * from. So the archive keeps the fact of a destination (provider, bank name,
     * last four characters) and not the full account number.
     */
    private function writeWithdrawals(string $dir, User $user): void
    {
        $path = $dir.'/withdrawals.json';
        $handle = $this->open($path);

        $first = true;
        $this->writeLine($handle, '[');

        Withdrawal::query()
            ->where('user_id', (int) $user->getKey())
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($rows) use ($handle, &$first): void {
                foreach ($rows as $row) {
                    if (! $row instanceof Withdrawal) {
                        continue;
                    }

                    $payload = [
                        'reference_number' => $row->reference_number,
                        'method' => $row->method?->value ?? null,
                        'status' => $row->status?->value ?? (string) $row->status,
                        'currency' => $row->currency?->value ?? $row->currency,
                        'amount' => (string) $row->amount,
                        'fee' => (string) $row->fee,
                        'net_amount' => (string) $row->net_amount,
                        'destination_masked' => $this->maskedDestination($row),
                        'requested_at' => $row->requested_at?->toIso8601String(),
                        'approved_at' => $row->approved_at?->toIso8601String(),
                        'completed_at' => $row->completed_at?->toIso8601String(),
                        'rejected_at' => $row->rejected_at?->toIso8601String(),
                        'created_at' => $row->created_at?->toIso8601String(),
                    ];

                    $this->writeLine($handle, ($first ? '' : ',').$this->encode($payload));
                    $first = false;
                }
            }, 'id');

        $this->writeLine($handle, "\n]\n");
        $this->close($handle);
    }

    private function writeBets(string $dir, User $user): void
    {
        if (! $this->tableExists('bets')) {
            return;
        }

        $this->writeRows(
            $dir.'/bets.json',
            DB::table('bets')->where('user_id', (int) $user->getKey()),
            [
                'bet_number',
                'type',
                'status',
                'currency',
                'stake_amount',
                'potential_payout',
                'actual_payout',
                'total_numbers',
                'placed_at',
                'won_at',
                'cancelled_at',
                'created_at',
            ],
        );

        if (! $this->tableExists('bet_items')) {
            return;
        }

        $this->writeJoinRows(
            $dir.'/bet_items.json',
            DB::table('bet_items')
                ->join('bets', 'bets.id', '=', 'bet_items.bet_id')
                ->where('bets.user_id', (int) $user->getKey())
                ->select([
                    'bets.bet_number as bet_number',
                    'bet_items.number as number',
                    'bet_items.position as position',
                    'bet_items.amount as amount',
                    'bet_items.payout_multiplier as payout_multiplier',
                    'bet_items.is_winner as is_winner',
                    'bet_items.actual_payout as actual_payout',
                ]),
        );
    }

    private function writeConsents(string $dir, User $user): void
    {
        if (! $this->tableExists('user_consents')) {
            return;
        }

        $this->writeRows(
            $dir.'/consents.json',
            DB::table('user_consents')->where('user_id', (int) $user->getKey()),
            [
                'document_type',
                'document_version',
                'document_hash',
                'action',
                'consented_at',
                'locale',
                'source',
            ],
        );
    }

    /**
     * KYC status and timestamps only.
     *
     * No document images, no document numbers, no reviewer notes: the fact of a
     * verification decision is the person's data, the evidence behind it is not
     * something this lane will ever mail out.
     */
    private function writeKycStatus(string $dir, User $user): void
    {
        $payload = [
            'reference' => 'kyc',
            'account_kyc_status' => $user->kyc_status?->value,
            'verifications' => [],
            'documents' => [],
        ];

        if ($this->tableExists('kyc_verifications')) {
            $payload['verifications'] = DB::table('kyc_verifications')
                ->where('user_id', (int) $user->getKey())
                ->select(['status', 'source', 'decided_at', 'expires_at', 'created_at'])
                ->orderBy('id')
                ->limit(200)
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all();
        }

        if ($this->tableExists('kyc_documents')) {
            // document_type and status only. document_number, file_path and
            // original_filename are never selected, so they cannot be exported
            // even by a later careless change to this array.
            $payload['documents'] = DB::table('kyc_documents')
                ->where('user_id', (int) $user->getKey())
                ->select(['document_type', 'status', 'verified_at', 'expires_at', 'submitted_at', 'created_at'])
                ->orderBy('id')
                ->limit(200)
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all();
        }

        $this->writeDocument($dir.'/kyc.json', $payload);
    }

    private function writeNotificationPreferences(string $dir, User $user): void
    {
        if (! $this->tableExists('notification_preferences')) {
            return;
        }

        $this->writeRows(
            $dir.'/notification_preferences.json',
            DB::table('notification_preferences')->where('user_id', (int) $user->getKey()),
            ['event_type', 'channel', 'enabled', 'quiet_hours', 'created_at', 'updated_at'],
        );
    }

    /**
     * The person's own security timeline.
     *
     * IP addresses and device fingerprints are deliberately left out: they are
     * not needed to answer "what happened on my account", and an exported file
     * is the last place they should be multiplied.
     */
    private function writeSecurityEvents(string $dir, User $user): void
    {
        if (! $this->tableExists('security_events')) {
            return;
        }

        $this->writeRows(
            $dir.'/security_events.json',
            DB::table('security_events')->where('user_id', (int) $user->getKey()),
            ['event_type', 'risk_level', 'reviewed', 'occurred_at'],
        );
    }

    /**
     * Support messages this person sent.
     *
     * Matched on the exact normalised address, because a public contact message
     * has no account link by design. Internal notes are NOT read here: they live
     * in contact_message_events and belong to the platform's record of how it
     * handled the case, not to the requester's copy of their own words.
     */
    private function writeSupportMessages(string $dir, User $user): void
    {
        if (! $this->tableExists('contact_messages')) {
            return;
        }

        $path = $dir.'/support_messages.json';
        $handle = $this->open($path);

        $email = DataSubjectRequest::normaliseEmail((string) $user->email);

        $this->writeLine($handle, '[');
        $first = true;

        DB::table('contact_messages')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->select([
                'id',
                'public_reference',
                'subject',
                'message',
                'status',
                'delivery_state',
                'locale',
                'resolved_at',
                'created_at',
            ])
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($rows) use ($handle, &$first): void {
                foreach ($rows as $row) {
                    $payload = (array) $row;
                    unset($payload['id']);

                    $this->writeLine($handle, ($first ? '' : ',').$this->encode($payload));
                    $first = false;
                }
            }, 'id');

        $this->writeLine($handle, "\n]\n");
        $this->close($handle);
    }

    private function writePrivacyRequests(string $dir, User $user): void
    {
        if (! $this->tableExists('data_subject_requests')) {
            return;
        }

        $this->writeRows(
            $dir.'/privacy_requests.json',
            DB::table('data_subject_requests')->where('user_id', (int) $user->getKey()),
            ['reference', 'type', 'status', 'locale', 'received_at', 'due_at', 'completed_at', 'created_at'],
        );
    }

    /**
     * A human-readable index, in the requester's own language.
     */
    private function writeReadme(string $dir, DataSubjectRequest $request, User $user, string $locale): void
    {
        $lines = [
            (string) trans('privacy_requests.export.readme_title', [], $locale),
            str_repeat('=', 60),
            '',
            (string) trans('privacy_requests.export.readme_reference', ['reference' => $request->reference], $locale),
            (string) trans('privacy_requests.export.readme_generated', ['generated' => Carbon::now()->toIso8601String()], $locale),
            '',
            (string) trans('privacy_requests.export.readme_contents', [], $locale),
            '  - README.txt',
            '  - profile.json',
            '  - wallets.json',
            '  - ledger.json',
            '  - transactions.json',
            '  - deposits.json',
            '  - withdrawals.json',
            '  - bets.json',
            '  - bet_items.json',
            '  - consents.json',
            '  - kyc.json',
            '  - notification_preferences.json',
            '  - security_events.json',
            '  - support_messages.json',
            '  - privacy_requests.json',
            '',
            (string) trans('privacy_requests.export.readme_excluded', [], $locale),
            '',
            (string) trans('privacy_requests.export.readme_account', ['username' => (string) $user->username], $locale),
            '',
        ];

        file_put_contents($dir.'/README.txt', implode("\n", $lines));
    }

    // =====================================================================
    // Streaming helpers
    // =====================================================================

    /**
     * @param  list<string>  $columns
     */
    private function writeRows(string $path, \Illuminate\Database\Query\Builder $query, array $columns): void
    {
        $handle = $this->open($path);

        $this->writeLine($handle, '[');
        $first = true;

        $query->select(array_merge(['id'], $columns))->orderBy('id');

        foreach ($query->cursor() as $row) {
            $payload = [];

            foreach ($columns as $column) {
                $payload[$column] = $this->normaliseValue(((array) $row)[$column] ?? null);
            }

            $this->writeLine($handle, ($first ? '' : ',').$this->encode($payload));
            $first = false;
        }

        $this->writeLine($handle, "\n]\n");
        $this->close($handle);
    }

    private function writeJoinRows(string $path, \Illuminate\Database\Query\Builder $query): void
    {
        $handle = $this->open($path);

        $this->writeLine($handle, '[');
        $first = true;

        foreach ($query->orderBy('bet_items.id')->cursor() as $row) {
            $payload = $this->normaliseRow((array) $row);

            $this->writeLine($handle, ($first ? '' : ',').$this->encode($payload));
            $first = false;
        }

        $this->writeLine($handle, "\n]\n");
        $this->close($handle);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function writeDocument(string $path, array $document): void
    {
        file_put_contents($path, json_encode(
            $this->normaliseRow($document),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
        ));
    }

    /**
     * @return resource
     */
    private function open(string $path)
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('The export file could not be opened.');
        }

        return $handle;
    }

    /**
     * @param  resource  $handle
     */
    private function writeLine($handle, string $line): void
    {
        if (fwrite($handle, $line) === false) {
            throw new RuntimeException('The export file could not be written.');
        }
    }

    /**
     * @param  resource  $handle
     */
    private function close($handle): void
    {
        if (is_resource($handle)) {
            fclose($handle);
        }
    }

    private function zip(string $dir, string $zipPath): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The export archive could not be created.');
        }

        try {
            foreach (glob($dir.'/*') ?: [] as $file) {
                if (is_file($file) && $file !== $zipPath) {
                    $zip->addFile($file, basename($file));
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function workDirectory(): string
    {
        $base = storage_path('app/private/privacy-tmp');
        $dir = $base.'/'.Str::uuid()->toString();

        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('The export workspace could not be created.');
        }

        return $dir;
    }

    private function cleanup(?string $dir): void
    {
        if ($dir === null || ! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.'/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($dir);
    }

    private function storagePath(): string
    {
        $root = trim((string) config('privacy_requests.export.root', 'privacy-exports'), '/');

        return sprintf('%s/%s/%s.zip', $root, Carbon::now()->format('Y/m'), Str::random(40));
    }

    private function ttlDays(): int
    {
        return max(1, (int) config('privacy_requests.export.ttl_days', 7));
    }

    private function tableExists(string $table): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A destination reduced to what identifies it, not what spends from it.
     *
     * @return array<string, string>
     */
    private function maskedDestination(Withdrawal $withdrawal): array
    {
        $details = $withdrawal->payout_details;
        $masked = [];

        if (! is_array($details)) {
            return $masked;
        }

        foreach ($details as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }

            $lower = strtolower($key);
            $string = (string) $value;

            if (str_contains($lower, 'name') || $lower === 'bank' || str_contains($lower, 'provider')) {
                // A beneficiary or bank NAME stays: it identifies the destination
                // and is not a credential.
                $masked[$key] = $string;

                continue;
            }

            if (str_contains($lower, 'number')
                || str_contains($lower, 'account')
                || str_contains($lower, 'iban')
                || str_contains($lower, 'card')
                || str_contains($lower, 'phone')) {
                $masked[$key.'_masked'] = $this->mask($string);

                continue;
            }
        }

        return $masked;
    }

    private function mask(string $value): string
    {
        $length = mb_strlen($value);

        if ($length === 0) {
            return '';
        }

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4).mb_substr($value, -4);
    }

    private function normaliseValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        if (is_string($value) && $value !== '' && preg_match('/^-?\d+\.\d+$/', $value) === 1) {
            // Money columns arrive as strings on purpose elsewhere in this
            // application; keep them strings so no precision is invented.
            return $value;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normaliseRow(array $row): array
    {
        $out = [];

        foreach ($row as $key => $value) {
            $out[$key] = $this->normaliseValue($value);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        $encoded = json_encode(
            $this->normaliseRow($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        return is_string($encoded) ? $encoded : 'null';
    }
}
