<?php

declare(strict_types=1);

namespace App\Services\Support;

use Illuminate\Support\Facades\Route;

/**
 * Centralised public support / contact metadata for the Home page, the footer
 * and the Contact page.
 *
 * Missing email/phone/hours stay null - the view renders an honest
 * "not configured" state instead of inventing a phone number or an office
 * address. An invented address is a message a visitor believes they sent and
 * nobody receives.
 *
 * PROMPT 10 gave the contact lane its own settings and pointed them here
 * rather than adding a second service. config('contact.support') wins where it
 * is set and config('home.support') is the fallback, so a deployment can name
 * a dedicated support address without the footer and the Contact page
 * disagreeing about who to write to - which is exactly what two services would
 * have produced the first time one of them was updated.
 */
class PublicSupportService
{
    /**
     * @return array{
     *     status: string,
     *     email: string|null,
     *     phone: string|null,
     *     hours: string|null,
     *     name: string|null,
     *     route_name: string|null,
     *     route_url: string|null,
     *     message: string,
     * }
     */
    public function contact(): array
    {
        $email = $this->nullableString(config('contact.support.email'))
            ?? $this->nullableString(config('home.support.email'));
        $phone = $this->nullableString(config('home.support.phone'));
        $hours = $this->nullableString(config('contact.support.hours'))
            ?? $this->nullableString(config('home.support.hours'));
        $routeName = $this->nullableString(config('home.support.route_name'));

        $routeUrl = null;

        // PROMPT 10: this read `route()->has($routeName)`, which throws an
        // ArgumentCountError - the route() HELPER requires a name, and it is
        // Route::has() that answers this question. The whole method therefore
        // threw on every call since the first commit.
        //
        // It was invisible because HomePageDataService wraps each section in a
        // try/catch and degrades to an empty block, so the Home page and the
        // footer have been silently rendering NO support details rather than
        // failing. The contact page surfaced it because it needs the result.
        if ($routeName !== null && Route::has($routeName)) {
            try {
                $routeUrl = route($routeName);
            } catch (\Throwable) {
                $routeUrl = null;
            }
        }

        $configured = $email !== null || $phone !== null || $routeUrl !== null;

        return [
            'status' => $configured ? 'CONFIGURED' : 'NOT_CONFIGURED',
            'name' => $this->nullableString(config('contact.support.name')),
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
