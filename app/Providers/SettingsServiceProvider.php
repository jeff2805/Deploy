<?php

namespace App\Providers;

use App\Services\SettingsService;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Makes the stored settings the live configuration.
 *
 * THIS IS THE WHOLE INTEGRATION. Every part of VisitDasol that already
 * reads config('visitdasol.map.zoom') or config('mail.from.name') goes
 * on reading exactly that, and starts getting the office's answer
 * because this provider has already overwritten it. No controller, no
 * service and no view had to change to honour a setting.
 *
 * It is registered last in bootstrap/providers.php so that anything a
 * setting overrides has been loaded by the time it runs.
 *
 * If anything at all goes wrong — no database, no settings table, a
 * cache store that is down — the site keeps the values from
 * config/visitdasol.php and behaves exactly as it did before this
 * module existed. A settings screen is not worth a white page.
 */
class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
    }

    public function boot(): void
    {
        /*
         * Console commands that build or tear down the schema must not
         * query a table that is mid-migration. They also have no use
         * for the overrides.
         */
        if ($this->app->runningInConsole() && $this->isSchemaCommand()) {
            return;
        }

        try {
            $this->app->make(SettingsService::class)->applyToConfig();
        } catch (Throwable) {
            // Deliberately silent: see the note above the class.
        }
    }

    private function isSchemaCommand(): bool
    {
        $command = $_SERVER['argv'][1] ?? '';

        foreach (['migrate', 'db:', 'schema:', 'package:discover'] as $prefix) {
            if (str_starts_with($command, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
