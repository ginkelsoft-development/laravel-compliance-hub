<?php

declare(strict_types=1);

use Composer\InstalledVersions;

// TestCase::defineDatabaseMigrations() resolves each family package's
// migrations path via Composer\InstalledVersions instead of a hardcoded
// `vendor/ginkelsoft/...` path, because the family packages are installed
// as Composer path-repositories and their exact vendor layout can change.
// These tests guard the defensive skip-on-missing-package behaviour.
it('resolvePackageInstallPath returns null for a package that is not installed, without throwing', function (): void {
    expect(InstalledVersions::isInstalled('ginkelsoft/this-package-does-not-exist'))->toBeFalse();

    $reflection = new ReflectionMethod($this, 'resolvePackageInstallPath');
    $reflection->setAccessible(true);

    expect($reflection->invoke($this, 'ginkelsoft/this-package-does-not-exist'))->toBeNull();
});

it('resolvePackageInstallPath returns a real path for an installed family package', function (): void {
    $reflection = new ReflectionMethod($this, 'resolvePackageInstallPath');
    $reflection->setAccessible(true);

    $path = $reflection->invoke($this, 'ginkelsoft/laravel-data-retention');

    expect($path)->toBeString()->not->toBe('');
});

it('the migration discovery loop skips a missing family package instead of crashing', function (): void {
    $familyPackages = new ReflectionMethod($this, 'familyPackages');
    $familyPackages->setAccessible(true);

    $resolvePath = new ReflectionMethod($this, 'resolvePackageInstallPath');
    $resolvePath->setAccessible(true);

    $loadMigrationsFrom = new ReflectionMethod($this, 'loadMigrationsFrom');
    $loadMigrationsFrom->setAccessible(true);

    // Same package list the real defineDatabaseMigrations() uses, plus one
    // that is guaranteed not to be installed — simulating a renamed or
    // removed family package.
    $packages = [...$familyPackages->invoke($this), 'ginkelsoft/this-package-does-not-exist'];

    $run = function () use ($packages, $resolvePath, $loadMigrationsFrom): void {
        foreach ($packages as $package) {
            $path = $resolvePath->invoke($this, $package);

            if ($path === null) {
                continue;
            }

            $loadMigrationsFrom->invoke($this, $path.'/database/migrations');
        }
    };

    expect($run)->not->toThrow(Throwable::class);
});
