<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\HttpsServiceProvider;

return [
    AppServiceProvider::class,
    HttpsServiceProvider::class,
    AdminPanelProvider::class,
];
