<?php

declare(strict_types=1);

return ['enabled' => filter_var(env('REAL_MONEY_ENABLED', false), FILTER_VALIDATE_BOOLEAN)];
