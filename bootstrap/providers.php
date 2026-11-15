<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\BotServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\ProductionSafetyServiceProvider;
use App\Providers\PaymentOpsServiceProvider;
use App\Providers\QueueServiceProvider;
use App\Providers\RewardsServiceProvider;
use App\Providers\ThaiRailsServiceProvider;
use App\Providers\WalletConversionServiceProvider;
use App\Providers\FrontendOpsServiceProvider;
use App\Providers\LotteryGamesServiceProvider;

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
    // The existing ops provider owns payment readiness boot refusal and routes.
    PaymentOpsServiceProvider::class,
    WalletConversionServiceProvider::class,
    RewardsServiceProvider::class,
    AdminPanelProvider::class,
    FrontendOpsServiceProvider::class,
    // Batch 2: digit-game framework (registry, providers, settlement adapter, listeners).
    LotteryGamesServiceProvider::class,
];
