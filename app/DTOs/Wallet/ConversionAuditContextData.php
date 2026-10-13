<?php

declare(strict_types=1);

namespace App\DTOs\Wallet;

use App\Enums\WalletConversionChannel;
use Illuminate\Http\Request;

/**
 * Who caused this conversion, from where, and with what request identity.
 *
 * WHY THIS IS CAPTURED SEPARATELY FROM THE CONVERSION ROW
 * The conversion row records what happened to the money. It should not also be
 * the place where HTTP concerns (user agent, IP, request id) are smuggled in,
 * because a console-driven reconciliation has none of those and would then have
 * to write empty columns that look like data loss. The context is therefore a
 * value object: built once at the edge, passed explicitly, and flattened into
 * the audit row where an investigator actually reads it.
 *
 * The actor id is nullable on purpose. A scheduled expiry sweep has no actor —
 * it is the system acting on its own clock — and pretending otherwise (say, by
 * attributing it to user 1) would corrupt the audit trail rather than complete
 * it. The channel enum is what distinguishes "system acted" from "operator
 * acted", so a null actor is never ambiguous.
 *
 * Nothing sensitive is captured: no payloads, no credentials, no full headers.
 */
final readonly class ConversionAuditContextData
{
    public WalletConversionChannel $channel;

    public ?int $actorUserId;

    public ?string $ipAddress;

    public ?string $userAgent;

    public ?string $requestId;

    /**
     * @var array<string, scalar|null>
     */
    public array $metadata;

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        WalletConversionChannel $channel,
        ?int $actorUserId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $requestId = null,
        array $metadata = [],
    ) {
        $this->channel = $channel;
        // A non-positive actor id is not an actor. It normalises to null so an
        // unauthenticated console run is recorded as a system action rather
        // than as a user who does not exist.
        $this->actorUserId = ($actorUserId !== null && $actorUserId > 0) ? $actorUserId : null;
        $this->ipAddress = self::truncate($ipAddress, 45);
        $this->userAgent = self::truncate($userAgent, 512);
        $this->requestId = self::truncate($requestId, 128);
        $this->metadata = $metadata;
    }

    /**
     * Build from an inbound HTTP request. The user agent is truncated to the
     * column width the audit table will store, at the point of capture, so a
     * hostile 10 KB user agent can never reach the database layer.
     */
    public static function fromRequest(
        Request $request,
        WalletConversionChannel $channel,
        ?int $actorUserId = null,
    ): self {
        $authenticatedId = optional($request->user())->getKey();

        return new self(
            channel: $channel,
            actorUserId: $actorUserId ?? ($authenticatedId !== null ? (int) $authenticatedId : null),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: self::firstNonEmpty(
                $request->headers->get('X-Request-Id'),
                $request->headers->get('X-Correlation-Id'),
            ),
            metadata: [],
        );
    }

    /**
     * Build from a console command or an automated process.
     *
     * @param  array<string, scalar|null>  $metadata
     */
    public static function forSystem(
        WalletConversionChannel $channel = WalletConversionChannel::Console,
        ?int $actorUserId = null,
        array $metadata = [],
    ): self {
        return new self(
            channel: $channel,
            actorUserId: $actorUserId,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?WalletConversionChannel $channel = null): self
    {
        $resolved = $channel ?? WalletConversionChannel::Web;

        if (isset($data['channel'])) {
            $resolved = $data['channel'] instanceof WalletConversionChannel
                ? $data['channel']
                : WalletConversionChannel::tryFrom((string) $data['channel']) ?? $resolved;
        }

        return new self(
            channel: $resolved,
            actorUserId: isset($data['actor_user_id']) ? (int) $data['actor_user_id'] : null,
            ipAddress: isset($data['ip_address']) ? (string) $data['ip_address'] : null,
            userAgent: isset($data['user_agent']) ? (string) $data['user_agent'] : null,
            requestId: isset($data['request_id']) ? (string) $data['request_id'] : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }

    public function isSystemAction(): bool
    {
        return $this->actorUserId === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'channel' => $this->channel->value,
            'actor_user_id' => $this->actorUserId,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'request_id' => $this->requestId,
            'metadata' => $this->metadata,
        ];
    }

    private static function truncate(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, $length);
    }

    private static function firstNonEmpty(?string ...$values): ?string
    {
        foreach ($values as $value) {
            $trimmed = trim((string) $value);

            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }
}
