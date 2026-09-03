<?php

declare(strict_types=1);

namespace Ginkelsoft\ComplianceHub\Tests;

use Composer\InstalledVersions;
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
     *
     * The install path is resolved via Composer\InstalledVersions instead
     * of a hardcoded `vendor/ginkelsoft/...` path, because the family
     * packages are installed as Composer path-repositories in CI/local dev
     * (symlinked from sibling checkouts) and their exact vendor layout can
     * change. A package that can't be resolved is skipped defensively
     * rather than crashing the test suite.
     */
    protected function defineDatabaseMigrations(): void
    {
        foreach ($this->familyPackages() as $package) {
            $path = $this->resolvePackageInstallPath($package);

            if ($path === null) {
                continue;
            }

            $this->loadMigrationsFrom($path.'/database/migrations');
        }
    }

    /**
     * @return array<int, string>
     */
    protected function familyPackages(): array
    {
        return [
            'ginkelsoft/laravel-data-retention',
            'ginkelsoft/laravel-data-right-to-be-forgotten',
            'ginkelsoft/laravel-data-subject-access',
            'ginkelsoft/laravel-data-consent',
            'ginkelsoft/laravel-data-breach-registry',
        ];
    }

    protected function resolvePackageInstallPath(string $package): ?string
    {
        if (! class_exists(InstalledVersions::class) || ! InstalledVersions::isInstalled($package)) {
            return null;
        }

        return InstalledVersions::getInstallPath($package);
    }
}
