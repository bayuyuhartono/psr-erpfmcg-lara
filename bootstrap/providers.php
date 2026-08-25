<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\SchemaServiceProvider;

return [
    AppServiceProvider::class,
    SchemaServiceProvider::class,
    AdminPanelProvider::class,
];
