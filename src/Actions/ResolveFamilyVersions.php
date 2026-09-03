<?php

declare(strict_types=1);

namespace Ginkelsoft\ComplianceHub\Actions;

use Composer\InstalledVersions;

/**
 * Resolves the installed version of every package in the GinkelSoft
 * compliance family, for inclusion in `compliance:report`.
 *
 * A missing package (not required, or removed from vendor/) does not
 * throw — it is reported as `'not installed'` so the report always
 * renders, even for a partial install.
 */
final class ResolveFamilyVersions
{
    /**
     * The Composer package names in the family, in the same
     * chronological-feature order as {@see VerifyAllChains::tables()}.
     *
     * @return list<string>
     */
    public function packages(): array
    {
        return [
            'ginkelsoft/laravel-compliance-hub',
            'ginkelsoft/laravel-compliance-core',
            'ginkelsoft/laravel-data-retention',
            'ginkelsoft/laravel-data-right-to-be-forgotten',
            'ginkelsoft/laravel-data-subject-access',
            'ginkelsoft/laravel-data-consent',
            'ginkelsoft/laravel-data-breach-registry',
        ];
    }

    /**
     * Resolve the pretty version of every family package.
     *
     * @return array<string, string> Package name => version, or 'not installed'.
     */
    public function resolve(): array
    {
        $versions = [];

        foreach ($this->packages() as $package) {
            $versions[$package] = $this->resolveOne($package);
        }

        return $versions;
    }

    private function resolveOne(string $package): string
    {
        if (! InstalledVersions::isInstalled($package)) {
            return 'not installed';
        }

        try {
            $version = InstalledVersions::getPrettyVersion($package);
        } catch (\OutOfBoundsException) {
            return 'not installed';
        }

        return $version ?? 'not installed';
    }
}
