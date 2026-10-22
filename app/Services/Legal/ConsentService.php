<?php

declare(strict_types=1);

namespace App\Services\Legal;

use App\Models\User;
use App\Models\UserConsent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Records immutable acceptance of the Terms and acknowledgement of the Privacy Notice.
 */
final class ConsentService
{
    public function __construct(
        private readonly LegalDocumentService $documents,
    ) {}

    /**
     * @return array{terms: UserConsent, privacy: UserConsent}
     */
    public function recordRegistration(User $user, Request $request): array
    {
        return $this->recordCurrentDocuments($user, $request, 'registration');
    }

    /**
     * @return array{terms: UserConsent, privacy: UserConsent}
     */
    public function acceptCurrentDocuments(User $user, Request $request, string $source = 'consent_page'): array
    {
        return $this->recordCurrentDocuments($user, $request, $source);
    }

    /**
     * Whether the user has recorded both current legal-document actions.
     */
    public function hasCurrentAcceptance(User $user, ?string $locale = null): bool
    {
        $locale = $this->locale($locale);

        foreach ([
            ['type' => 'terms', 'action' => 'accepted'],
            ['type' => 'privacy', 'action' => 'acknowledged'],
        ] as $required) {
            $current = $this->documents->currentData($required['type'], $locale);

            if (($current['status'] ?? null) !== 'AVAILABLE'
                || ! is_array($current['sections'] ?? null)
                || $current['sections'] === []
                || ! is_string($current['version'] ?? null)
                || ! is_string($current['content_hash'] ?? null)) {
                return false;
            }

            $accepted = UserConsent::query()
                ->where('user_id', (int) $user->getKey())
                ->where('document_type', $required['type'])
                ->where('document_version', $current['version'])
                ->where('document_hash', $current['content_hash'])
                ->where('action', $required['action'])
                ->exists();

            if (! $accepted) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array<string, mixed>> */
    public function currentDocuments(?string $locale = null): array
    {
        $locale = $this->locale($locale);

        return array_map(function (string $type) use ($locale): array {
            $data = $this->documents->currentData($type, $locale);

            return [
                'document_type' => $type,
                'locale' => $locale,
                'version' => (string) ($data['version'] ?? ''),
                'content_hash' => (string) ($data['content_hash'] ?? ''),
                'effective_at' => (string) ($data['effective_at'] ?? ''),
                'title' => (string) ($data['title'] ?? ''),
                'meta_description' => (string) ($data['meta_description'] ?? ''),
                'sections' => is_array($data['sections'] ?? null) ? array_values($data['sections']) : [],
                'status' => (string) ($data['status'] ?? 'UNAVAILABLE'),
            ];
        }, ['terms', 'privacy']);
    }

    /** @return array{terms: UserConsent, privacy: UserConsent} */
    private function recordCurrentDocuments(User $user, Request $request, string $source): array
    {
        if (! $request->boolean('terms')) {
            throw ValidationException::withMessages(['terms' => 'Explicit acceptance of the current Terms is required.']);
        }

        if (! $request->boolean('privacy_notice')) {
            throw ValidationException::withMessages(['privacy_notice' => 'Acknowledgement of the current Privacy Notice is required.']);
        }

        if (! in_array($source, ['registration', 'consent_page', 'api'], true)) {
            throw new LogicException('Unsupported consent source.');
        }

        $locale = $this->locale();
        $expectedDocuments = [];

        foreach (['terms', 'privacy'] as $documentType) {
            $version = $request->input($documentType.'_version');
            $hash = $request->input($documentType.'_hash');

            if (! is_string($version) || trim($version) === '' || ! is_string($hash) || preg_match('/^[a-f0-9]{64}$/iD', $hash) !== 1) {
                throw ValidationException::withMessages([
                    $documentType.'_version' => 'The current legal-document version and content hash are required.',
                ]);
            }

            $expectedDocuments[$documentType] = [
                'version' => trim($version),
                'hash' => strtolower($hash),
            ];
        }

        foreach ($expectedDocuments as $documentType => $expected) {
            $current = $this->documents->currentData($documentType, $locale);

            if (($current['status'] ?? null) !== 'AVAILABLE'
                || ! is_array($current['sections'] ?? null)
                || $current['sections'] === []) {
                throw ValidationException::withMessages([
                    'legal_documents' => 'The current legal documents are not available for acknowledgement. Please try again later.',
                ]);
            }

            if (($current['version'] ?? null) !== $expected['version']
                || ! is_string($current['content_hash'] ?? null)
                || ! hash_equals($current['content_hash'], $expected['hash'])) {
                throw ValidationException::withMessages([
                    'legal_documents' => 'A current legal document changed after it was displayed. Review the latest Terms and Privacy Notice and submit again.',
                ]);
            }
        }

        return DB::transaction(function () use ($user, $request, $source, $locale, $expectedDocuments): array {
            $terms = $this->recordOne(
                $user,
                $request,
                'terms',
                'accepted',
                $source,
                $locale,
                $expectedDocuments['terms']['version'],
                $expectedDocuments['terms']['hash'],
            );
            $privacy = $this->recordOne(
                $user,
                $request,
                'privacy',
                'acknowledged',
                $source,
                $locale,
                $expectedDocuments['privacy']['version'],
                $expectedDocuments['privacy']['hash'],
            );

            return [
                'terms' => $terms,
                'privacy' => $privacy,
            ];
        }, 3);
    }

    private function recordOne(
        User $user,
        Request $request,
        string $documentType,
        string $action,
        string $source,
        string $locale,
        string $expectedVersion,
        string $expectedHash,
    ): UserConsent {
        $snapshot = $this->documents->currentSnapshot($documentType, $locale);

        if ($snapshot['version'] !== $expectedVersion || ! hash_equals($snapshot['hash'], $expectedHash)) {
            throw ValidationException::withMessages([
                'legal_documents' => 'A current legal document changed after it was displayed. Review the latest Terms and Privacy Notice and submit again.',
            ]);
        }

        $identity = [
            'user_id' => (int) $user->getKey(),
            'document_type' => $documentType,
            'document_version' => $snapshot['version'],
            'document_hash' => $snapshot['hash'],
            'action' => $action,
        ];
        $existing = UserConsent::query()->where($identity)->first();

        if ($existing instanceof UserConsent) {
            // The content hash is part of the unique identity. This branch also
            // guards against a schema/model regression that would otherwise
            // silently rewrite the historic acceptance evidence.
            if ((string) $existing->document_hash !== $snapshot['hash']) {
                throw new LogicException('An existing consent record does not match the current legal-document hash.');
            }

            return $existing;
        }

        $now = now();
        DB::table('user_consents')->insertOrIgnore($identity + [
            'legal_document_id' => $snapshot['legal_document_id'],
            'consented_at' => $now,
            'ip_address' => $request->ip(),
            'user_agent' => $this->userAgent($request),
            'locale' => $locale,
            'source' => $source,
            'created_at' => $now,
        ]);

        $recorded = UserConsent::query()->where($identity)->first();

        if (! $recorded instanceof UserConsent
            || ! hash_equals($snapshot['hash'], (string) $recorded->document_hash)) {
            throw new LogicException('The immutable consent record could not be confirmed after insertion.');
        }

        return $recorded;
    }

    private function locale(?string $locale = null): string
    {
        $resolved = $locale !== null && $locale !== '' ? $locale : (string) app()->getLocale();

        return in_array($resolved, ['en', 'th'], true) ? $resolved : (string) config('app.fallback_locale', 'en');
    }

    private function userAgent(Request $request): ?string
    {
        $value = $request->userAgent();

        return is_string($value) && $value !== '' ? substr($value, 0, 512) : null;
    }
}
