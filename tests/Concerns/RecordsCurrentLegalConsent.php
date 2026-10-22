<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\User;
use App\Services\Legal\ConsentService;
use Illuminate\Http\Request;
use LogicException;

trait RecordsCurrentLegalConsent
{
    protected function recordCurrentLegalConsent(User $user): void
    {
        $consents = app(ConsentService::class);
        $documents = [];

        foreach ($consents->currentDocuments() as $document) {
            $documents[(string) ($document['document_type'] ?? '')] = $document;
        }

        foreach (['terms', 'privacy'] as $type) {
            $document = $documents[$type] ?? null;

            if (! is_array($document)
                || ($document['status'] ?? null) !== 'AVAILABLE'
                || ! is_string($document['version'] ?? null)
                || trim($document['version']) === ''
                || ! is_string($document['content_hash'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/iD', $document['content_hash']) !== 1) {
                throw new LogicException('The legal-consent fixture requires an available current '.$type.' document.');
            }
        }

        $request = Request::create('/test/legal/consent', 'POST', [
            'terms' => '1',
            'privacy_notice' => '1',
            'terms_version' => $documents['terms']['version'],
            'terms_hash' => $documents['terms']['content_hash'],
            'privacy_version' => $documents['privacy']['version'],
            'privacy_hash' => $documents['privacy']['content_hash'],
        ], [], [], [
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_USER_AGENT' => 'PHPUnit current-legal-consent fixture',
        ]);

        $consents->acceptCurrentDocuments($user, $request, 'registration');
    }
}
