<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_webhooks')) {
            return;
        }

        if (! Schema::hasColumn('payment_webhooks', 'payload_ciphertext')) {
            Schema::table('payment_webhooks', function (Blueprint $table): void {
                $table->longText('payload_ciphertext')->nullable();
            });
        }

        if (! Schema::hasColumn('payment_webhooks', 'signature_ciphertext')) {
            Schema::table('payment_webhooks', function (Blueprint $table): void {
                $table->longText('signature_ciphertext')->nullable();
            });
        }

        DB::table('payment_webhooks')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $isRejected = (string) $row->status === 'rejected';
                    $payloadCiphertext = $row->payload_ciphertext;
                    $signatureCiphertext = $row->signature_ciphertext;
                    $signature = is_string($row->signature) ? $row->signature : null;
                    $marker = json_encode([
                        'encrypted' => ! $isRejected,
                        'payload_fingerprint' => (string) $row->payload_fingerprint,
                    ], JSON_THROW_ON_ERROR);
                    $updates = [];

                    if ($isRejected) {
                        // Refused, unverified provider input is not necessary
                        // evidence. Preserve its fingerprint and discard the
                        // attacker-controlled body and signature.
                        $updates['payload'] = $marker;
                        $updates['payload_ciphertext'] = null;
                        $updates['signature'] = $signature === null || $signature === ''
                            ? null
                            : (preg_match('/^[a-f0-9]{64}$/iD', $signature) === 1
                                ? strtolower($signature)
                                : hash('sha256', $signature));
                        $updates['signature_ciphertext'] = null;
                    } else {
                        if (! is_string($payloadCiphertext) || $payloadCiphertext === '') {
                            $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);

                            if (! is_array($payload)) {
                                throw new RuntimeException('A payment webhook payload is not a JSON object or array; encryption migration refused.');
                            }

                            $payloadCiphertext = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
                            $updates['payload'] = $marker;
                            $updates['payload_ciphertext'] = $payloadCiphertext;
                        }

                        if ($signature !== null && $signature !== ''
                            && (! is_string($signatureCiphertext) || $signatureCiphertext === '')) {
                            $updates['signature_ciphertext'] = Crypt::encryptString($signature);
                            $updates['signature'] = null;
                        }
                    }

                    if ($updates !== []) {
                        DB::table('payment_webhooks')
                            ->where('id', (int) $row->id)
                            ->update($updates);
                    }
                }
            });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Encrypted payment webhook evidence cannot be rolled back to plaintext. Restore a pre-migration backup and deploy the prior application version.',
        );
    }
};
