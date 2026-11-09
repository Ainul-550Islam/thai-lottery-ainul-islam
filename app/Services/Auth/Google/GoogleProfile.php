<?php

declare(strict_types=1);

namespace App\Services\Auth\Google;

/** Claims taken ONLY from a verified Google id_token. Immutable. */
final readonly class GoogleProfile
{
    public function __construct(
        public string $subject,     // `sub`
        public string $email,       // lowercased
        public bool $emailVerified,
        public string $name,
        public ?string $picture,    // https + googleusercontent host, <= 255 chars, else null
    ) {}

    /** @return array<string, mixed> */
    public function toSession(): array
    {
        return [
            'sub' => $this->subject, 'email' => $this->email, 'email_verified' => $this->emailVerified,
            'name' => $this->name, 'picture' => $this->picture,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromSession(array $data): self
    {
        return new self(
            (string) ($data['sub'] ?? ''), (string) ($data['email'] ?? ''), (bool) ($data['email_verified'] ?? false),
            (string) ($data['name'] ?? ''), is_string($data['picture'] ?? null) ? $data['picture'] : null,
        );
    }
}
