<?php

// TYPE: Domain service
// PURPOSE: Create, list, read, and reply to authenticated owner-scoped support cases without exposing anonymous ContactMessage records.

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Models\SupportCase;
use App\Models\SupportMessage;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class SupportCaseService
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * @return list<SupportCase>
     */
    public function listFor(User $owner): array
    {
        return SupportCase::query()
            ->where('owner_user_id', (int) $owner->getAuthIdentifier())
            ->with(['messages' => static fn ($query) => $query
                ->where('internal', false)
                ->latest('id')
                ->limit(1)])
            ->latest('updated_at')
            ->limit(100)
            ->get()
            ->all();
    }

    public function findForOwner(User $owner, string $reference): SupportCase
    {
        $case = SupportCase::query()
            ->where('owner_user_id', (int) $owner->getAuthIdentifier())
            ->where('public_reference', $reference)
            ->with(['messages' => static fn ($query) => $query
                ->where('internal', false)
                ->oldest('id')])
            ->first();

        if (! $case instanceof SupportCase) {
            throw new RuntimeException('Support case not found.');
        }

        return $case;
    }

    /**
     * @param array{category: string, subject: string, body: string, priority?: string} $data
     */
    public function create(User $owner, array $data): SupportCase
    {
        $case = DB::transaction(function () use ($owner, $data): SupportCase {
            $case = SupportCase::query()->create([
                'uuid' => (string) Str::uuid(),
                'public_reference' => SupportCase::buildPublicReference(),
                'owner_user_id' => (int) $owner->getAuthIdentifier(),
                'category' => $data['category'],
                'priority' => $data['priority'] ?? SupportCase::PRIORITY_NORMAL,
                'status' => SupportCase::STATUS_OPEN,
                'subject' => $data['subject'],
            ]);

            $case->messages()->create([
                'sender_user_id' => (int) $owner->getAuthIdentifier(),
                'body' => $data['body'],
                'internal' => false,
            ]);

            $this->audit->log(
                userId: (int) $owner->getAuthIdentifier(),
                action: AuditAction::Create,
                riskLevel: RiskLevel::Low,
                auditable: $case,
                description: 'Owner-scoped support case created.',
                metadata: ['public_reference' => $case->public_reference, 'category' => $case->category],
            );

            return $case;
        });

        return $case->load(['messages' => static fn ($query) => $query
            ->where('internal', false)
            ->oldest('id')]);
    }

    public function reply(User $owner, string $reference, string $body): SupportCase
    {
        $case = $this->findForOwner($owner, $reference);

        if (in_array($case->status, [SupportCase::STATUS_CLOSED, SupportCase::STATUS_RESOLVED], true)) {
            throw new RuntimeException('Closed support cases cannot receive replies.');
        }

        DB::transaction(function () use ($owner, $case, $body): void {
            $case->messages()->create([
                'sender_user_id' => (int) $owner->getAuthIdentifier(),
                'body' => $body,
                'internal' => false,
            ]);
            $case->forceFill(['status' => SupportCase::STATUS_OPEN])->save();

            $this->audit->log(
                userId: (int) $owner->getAuthIdentifier(),
                action: AuditAction::Update,
                riskLevel: RiskLevel::Low,
                auditable: $case,
                description: 'Owner-scoped support case reply created.',
                metadata: ['public_reference' => $case->public_reference],
            );
        });

        return $case->fresh(['messages' => static fn ($query) => $query
            ->where('internal', false)
            ->oldest('id')]);
    }
}
