<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\WalletConversionServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\ProductionSafetyServiceProvider;

return [
    AppServiceProvider::class,
    // Refuses to boot on an unsafe production configuration
    // (fixture lanes, APP_DEBUG, sync queue, non-expiring tokens...).
    ProductionSafetyServiceProvider::class,
    AuthServiceProvider::class,
    EventServiceProvider::class,

    // Cash <-> Win conversion: contract binding, event wiring and its own timers.
    WalletConversionServiceProvider::class,
    AdminPanelProvider::class,
];
