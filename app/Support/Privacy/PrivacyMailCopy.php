<?php

declare(strict_types=1);

namespace App\Support\Privacy;

use Illuminate\Mail\Mailables\Address;

/**
 * Resolves one localized privacy-mail sentence with its interpolation values.
 *
 * WHY A HELPER AND NOT trans() AT THE CALL SITE. Every mail in this lane takes
 * the same three values (the reference, the app name and, where relevant, a
 * date), and getting one of them wrong in one of five mails produces a subject
 * line reading ":reference". This class has exactly one way to build the
 * sentence, so the five mailables cannot drift apart.
 *
 * IT IMMUTABLY ACCUMULATES VALUES rather than taking them as one array, because
 * some calls need the sentence before the values are known (the envelope is
 * built before the content in Laravel's Mailable lifecycle).
 */
final class PrivacyMailCopy
{
    /** @var array<string, string> */
    private array $values = [];

    private string $locale = 'en';

    private function __construct(private readonly string $key) {}

    public static function for(string $key): self
    {
        return new self($key);
    }

    public function locate(string $locale): self
    {
        if (in_array($locale, ['en', 'th', 'bn'], true)) {
            $this->locale = $locale;
        }

        return $this;
    }

    public function reference(string $reference): self
    {
        $this->values['reference'] = $reference;

        return $this;
    }

    public function appName(): self
    {
        $this->values['app'] = (string) config('app.name', '');

        return $this;
    }

    /**
     * @param  array<string, string>  $values
     */
    public function with(array $values): self
    {
        foreach ($values as $key => $value) {
            $this->values[$key] = $value;
        }

        return $this;
    }

    public function render(): string
    {
        $merged = $this->values + [
            'reference' => '',
            'app' => (string) config('app.name', ''),
        ];

        return (string) trans($this->key, $merged, $this->locale);
    }

    public function __toString(): string
    {
        return $this->render();
    }

    /**
     * The From identity every privacy mail uses.
     *
     * Falls back to the framework's configured from address when the privacy
     * lane has none, so a deployment that never set a dedicated privacy mailbox
     * still sends from an address the mail transport is authorised for. When
     * neither exists the address is null and the framework refuses to send -
     * which is the honest outcome, not a mail from a fabricated domain.
     */
    public static function fromAddress(): ?Address
    {
        $address = config('mail.from.address');
        $name = config('mail.from.name');

        if (! is_string($address) || trim($address) === '') {
            return null;
        }

        return new Address(trim($address), is_string($name) ? $name : null);
    }
}
