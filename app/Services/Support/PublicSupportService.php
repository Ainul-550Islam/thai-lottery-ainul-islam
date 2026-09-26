<?php

declare(strict_types=1);

namespace App\Services\Support;

/**
 * Centralised public support / contact metadata for the Home page and footer.
 *
 * Reads config('home.support') only. Missing email/phone/hours stay null —
 * the view must render an honest "not configured" state instead of inventing
 * a phone number or office address.
 */
class PublicSupportService
{
    /**
     * @return array{
     *     status: string,
     *     email: string|null,
     *     phone: string|null,
     *     hours: string|null,
     *     route_name: string|null,
     *     route_url: string|null,
     *     message: string,
     * }
     */
    public function contact(): array
    {
        $email = $this->nullableString(config('home.support.email'));
        $phone = $this->nullableString(config('home.support.phone'));
        $hours = $this->nullableString(config('home.support.hours'));
        $routeName = $this->nullableString(config('home.support.route_name'));

        $routeUrl = null;
        if ($routeName !== null && route()->has($routeName)) {
            try {
                $routeUrl = route($routeName);
            } catch (\Throwable) {
                $routeUrl = null;
            }
        }

        $configured = $email !== null || $phone !== null || $routeUrl !== null;

        return [
            'status' => $configured ? 'CONFIGURED' : 'NOT_CONFIGURED',
            'email' => $email,
            'phone' => $phone,
            'hours' => $hours,
            'route_name' => $routeName,
            'route_url' => $routeUrl,
            'message' => $configured
                ? ''
                : 'Support contact details are not configured yet.',
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
