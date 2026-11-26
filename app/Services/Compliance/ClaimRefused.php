<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use RuntimeException;

/**
 * A claim refused because it asserts something no attestation backs.
 *
 * Carries the offending phrases, not only a message, so the operator can fix them
 * all at once and so a test can assert WHICH claim tripped the guard.
 */
final class ClaimRefused extends RuntimeException
{
    /**
     * @param  list<string>  $matches
     */
    public function __construct(
        public readonly string $field,
        public readonly array $matches,
    ) {
        parent::__construct(sprintf(
            'The %s contains a claim this system cannot evidence: %s. A credential may only be published from a valid compliance attestation.',
            $field,
            implode(', ', $matches),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'error' => 'claim_refused',
            'field' => $this->field,
            'matches' => $this->matches,
            'message' => $this->getMessage(),
        ];
    }
}
