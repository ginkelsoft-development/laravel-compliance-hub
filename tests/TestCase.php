<?php

declare(strict_types=1);

namespace Ginkelsoft\ComplianceHub\Tests;

use Ginkelsoft\ComplianceCore\ComplianceCoreServiceProvider;
use Ginkelsoft\ComplianceHub\ComplianceHubServiceProvider;
use Ginkelsoft\DataBreachRegistry\DataBreachRegistryServiceProvider;
use Ginkelsoft\DataConsent\DataConsentServiceProvider;
use Ginkelsoft\DataRetention\DataRetentionServiceProvider;
use Ginkelsoft\DataRightToBeForgotten\DataRightToBeForgottenServiceProvider;
use Ginkelsoft\DataSubjectAccess\DataSubjectAccessServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ComplianceCoreServiceProvider::class,
            DataRetentionServiceProvider::class,
            DataRightToBeForgottenServiceProvider::class,
            DataSubjectAccessServiceProvider::class,
            DataConsentServiceProvider::class,
            DataBreachRegistryServiceProvider::class,
            ComplianceHubServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('compliance.log_secret', 'test-log-secret');
    }

    /**
     * The hub itself ships no migrations — it only verifies the chains
     * produced by other family packages. Tell Testbench to run every
     * sibling package's migrations so the tables exist before any test
     * runs.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../vendor/ginkelsoft/laravel-data-retention/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/ginkelsoft/laravel-data-right-to-be-forgotten/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/ginkelsoft/laravel-data-subject-access/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/ginkelsoft/laravel-data-consent/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/ginkelsoft/laravel-data-breach-registry/database/migrations');
    }
}
