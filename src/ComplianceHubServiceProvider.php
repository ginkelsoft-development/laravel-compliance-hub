<?php

declare(strict_types=1);

namespace Ginkelsoft\ComplianceHub;

use Ginkelsoft\ComplianceHub\Console\InstallCommand;
use Ginkelsoft\ComplianceHub\Console\MigrateV1AccessRowsCommand;
use Ginkelsoft\ComplianceHub\Console\ReportCommand;
use Ginkelsoft\ComplianceHub\Console\VerifyCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Laravel Compliance Hub.
 *
 * The hub itself adds no migrations, no config, no models — its
 * Artisan commands verify, summarize, and (optionally) tidy up the
 * audit-log hash chains of every family member installed alongside it.
 *
 * Because the hub hard-requires every family package, the commands can
 * assume all five chains exist; no class_exists() probing needed.
 */
class ComplianceHubServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                VerifyCommand::class,
                ReportCommand::class,
                MigrateV1AccessRowsCommand::class,
            ]);
        }
    }
}
