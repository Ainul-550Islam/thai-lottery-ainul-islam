<?php

declare(strict_types=1);

return [
    'turnstile' => ['enabled'=>(bool) env('TURNSTILE_ENABLED', false),'site_key'=>env('TURNSTILE_SITE_KEY'),'secret_key'=>env('TURNSTILE_SECRET_KEY')],
    'hcaptcha' => ['enabled'=>(bool) env('HCAPTCHA_ENABLED', false),'site_key'=>env('HCAPTCHA_SITE_KEY'),'secret_key'=>env('HCAPTCHA_SECRET_KEY')],
];
