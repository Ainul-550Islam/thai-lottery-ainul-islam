<?php

declare(strict_types=1);

/*
 * Public social profile links shown in the site footer. Each value stays empty until the
 * operator sets it. App\Services\Web\SocialLinkService rejects any URL that is not an https
 * link on that platform's own domain.
 */
return [
    'youtube' => env('SOCIAL_YOUTUBE_URL'),
    'facebook' => env('SOCIAL_FACEBOOK_URL'),
    'tiktok' => env('SOCIAL_TIKTOK_URL'),
];
