<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\BotServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\ProductionSafetyServiceProvider;
use App\Providers\QueueServiceProvider;
use App\Providers\ThaiRailsServiceProvider;
use App\Providers\WalletConversionServiceProvider;
use App\Providers\FrontendOpsServiceProvider;

return [
    AppServiceProvider::class,
    // Refuses to boot on an unsafe production configuration
    // (fixture lanes, APP_DEBUG, sync queue, non-expiring tokens...).
    ProductionSafetyServiceProvider::class,
    AuthServiceProvider::class,
    BotServiceProvider::class,
    EventServiceProvider::class,
    QueueServiceProvider::class,
    ThaiRailsServiceProvider::class,
    WalletConversionServiceProvider::class,
    AdminPanelProvider::class,
    FrontendOpsServiceProvider::class,
];
