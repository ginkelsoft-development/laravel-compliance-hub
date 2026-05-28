<?php

declare(strict_types=1);

namespace Ginkelsoft\ComplianceHub;

use Ginkelsoft\ComplianceHub\Console\ReportCommand;
use Ginkelsoft\ComplianceHub\Console\VerifyCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Laravel Compliance Hub.
 *
 * The hub itself adds no migrations, no config, no models — its sole
 * job is to expose two Artisan commands that verify and summarize the
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
                VerifyCommand::class,
                ReportCommand::class,
            ]);
        }
    }
}
