<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\ChatServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\ProductionSafetyServiceProvider;
use App\Providers\TransferServiceProvider;

return [
    AppServiceProvider::class,
    ChatServiceProvider::class,
    // Refuses to boot on an unsafe production configuration
    // (fixture lanes, APP_DEBUG, sync queue, non-expiring tokens...).
    ProductionSafetyServiceProvider::class,
    TransferServiceProvider::class,
    AuthServiceProvider::class,
    EventServiceProvider::class,
    AdminPanelProvider::class,
];
