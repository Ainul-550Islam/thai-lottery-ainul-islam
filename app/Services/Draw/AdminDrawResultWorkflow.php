<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\Enums\DrawConfirmationStatus;
use App\Enums\DrawStatus;
use App\Models\Draw;
use App\Models\User;
use App\Support\Admin\AdminAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Manual two-number panel intake/review; never asserts official-source verification. */
final class AdminDrawResultWorkflow
{
    public function __construct(
        private readonly DrawResultIngestionService $ingestion,
        private readonly DrawResultConfirmationService $confirmation,
    ) {}

    public function canIngest(Draw $draw): bool
    {
        $record = $this->ingestion->pendingIngestion((int) $draw->getKey());

        return $this->currentActor() !== null && $draw->status === DrawStatus::Drawing
            && ($record === null || $this->isManualCard($record));
    }

    public function canConfirm(Draw $draw): bool
    {
        $actor = $this->currentActor();
        $record = $this->ingestion->pendingIngestion((int) $draw->getKey());

        return $actor !== null && $draw->status === DrawStatus::Drawing
            && $record !== null && $this->isManualCard($record)
            && (int) ($record['ingested_by'] ?? 0) !== (int) $actor->getKey();
    }

    public function pendingFingerprint(Draw $draw): ?string
    {
        $record = $this->ingestion->pendingIngestion((int) $draw->getKey());

        return $record !== null && $this->isManualCard($record) ? (string) $record['fingerprint'] : null;
    }

    /** Identity and source are server-owned, never accepted from form fields. */
    public function ingest(int $drawId, array $numbers): array
    {
        return DB::transaction(function () use ($drawId, $numbers): array {
            $actor = $this->requireActor();
            $draw = $this->lockedPendingDraw($drawId);
            $record = $this->ingestion->pendingIngestion($drawId);
            if ($record !== null && ! $this->isManualCard($record)) {
                throw ValidationException::withMessages(['first_prize' => 'This pending card requires its dedicated source-review workflow.']);
            }

            return $this->ingestion->ingest($drawId, $this->numbers($numbers), 'admin-panel', (int) $actor->getKey());
        });
    }

    /** Bind review to the mounted card, then delegate the atomic four-eyes act. */
    public function confirm(int $drawId, array $numbers, string $notes, string $fingerprint): array
    {
        $notes = trim($notes);
        if (mb_strlen($notes) < 5 || mb_strlen($notes) > 500) {
            throw ValidationException::withMessages(['notes' => 'Review notes must contain 5–500 characters.']);
        }

        return DB::transaction(function () use ($drawId, $numbers, $notes, $fingerprint): array {
            $actor = $this->requireActor();
            $this->lockedPendingDraw($drawId);
            $record = $this->ingestion->pendingIngestion($drawId);
            if ($record === null || ! $this->isManualCard($record)
                || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1
                || ! hash_equals((string) $record['fingerprint'], $fingerprint)) {
                throw ValidationException::withMessages(['fingerprint' => 'The pending card changed or is unavailable. Reload and review it again.']);
            }

            return $this->confirmation->confirm($drawId, (int) $actor->getKey(), $this->numbers($numbers), $notes);
        });
    }

    private function currentActor(): ?User
    {
        $id = auth()->id();
        $actor = $id === null ? null : User::query()->find($id);

        return $actor instanceof User && AdminAccess::canAccessPanel($actor)
            && AdminAccess::allows($actor, AdminAccess::MANAGE_DRAWS) ? $actor : null;
    }

    private function requireActor(): User
    {
        return $this->currentActor() ?? throw new AuthorizationException('An active authorized draw operator is required.');
    }

    private function lockedPendingDraw(int $drawId): Draw
    {
        $draw = Draw::query()->lockForUpdate()->findOrFail($drawId);
        if ($draw->status !== DrawStatus::Drawing) {
            throw ValidationException::withMessages(['first_prize' => 'The draw is not awaiting result review.']);
        }

        return $draw;
    }

    private function isManualCard(array $record): bool
    {
        $payload = $record['payload'] ?? null;

        return ($record['status'] ?? null) === DrawConfirmationStatus::Pending->value
            && ($record['source'] ?? null) === 'admin-panel'
            && is_array($payload) && count($payload) === 2
            && isset($payload['first_prize'], $payload['bottom_two'])
            && is_string($record['fingerprint'] ?? null)
            && preg_match('/\A[a-f0-9]{64}\z/D', $record['fingerprint']) === 1
            && (int) ($record['ingested_by'] ?? 0) > 0;
    }

    private function numbers(array $input): array
    {
        return ['first_prize' => $input['first_prize'] ?? null, 'bottom_two' => $input['bottom_two'] ?? null];
    }
}
