<?php

use App\Providers\AppServiceProvider;
use App\Providers\SettingsServiceProvider;

return [
    AppServiceProvider::class,

    /*
     * Last, deliberately. It overwrites config values that the
     * providers above have already read their own settings from, so it
     * has to run after them — and it is written to fall back silently
     * to the config file if the settings table is not there yet.
     */
    SettingsServiceProvider::class,
];
