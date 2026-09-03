<?php

declare(strict_types=1);

namespace Ginkelsoft\ComplianceHub\Actions;

use Ginkelsoft\ComplianceHub\ComplianceHubServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

/**
 * Publishes every `vendor:publish` tag registered by the GinkelSoft
 * compliance family, in one pass.
 *
 * The hub hard-requires every family package (see
 * {@see ComplianceHubServiceProvider}), so this list is fixed rather
 * than discovered — a missing tag would mean a broken install, which
 * `compliance:install` should surface loudly rather than silently skip.
 */
final class PublishFamilyAssets
{
    /**
     * Every publish tag in the family, in package-install order.
     *
     * @return list<array{tag: string, label: string}>
     */
    public function tags(): array
    {
        return [
            ['tag' => 'compliance-config', 'label' => 'Compliance core config'],
            ['tag' => 'data-retention-config', 'label' => 'Data retention config'],
            ['tag' => 'data-retention-migrations', 'label' => 'Data retention migration'],
            ['tag' => 'forget-config', 'label' => 'Right to be forgotten config'],
            ['tag' => 'forget-migrations', 'label' => 'Right to be forgotten migration'],
            ['tag' => 'subject-access-config', 'label' => 'Subject access config'],
            ['tag' => 'subject-access-migrations', 'label' => 'Subject access migration'],
            ['tag' => 'consent-config', 'label' => 'Consent config'],
            ['tag' => 'consent-migrations', 'label' => 'Consent migration'],
            ['tag' => 'breach-config', 'label' => 'Breach registry config'],
            ['tag' => 'breach-migrations', 'label' => 'Breach registry migration'],
        ];
    }

    /**
     * Publish every tag in {@see tags()}.
     *
     * @return list<array{tag: string, label: string, files: int}>
     */
    public function publish(bool $force): array
    {
        $results = [];

        foreach ($this->tags() as $entry) {
            $tag = $entry['tag'];
            $files = count(ServiceProvider::pathsToPublish(null, $tag));

            Artisan::call('vendor:publish', [
                '--tag' => $tag,
                '--force' => $force,
            ]);

            $results[] = [
                'tag' => $tag,
                'label' => $entry['label'],
                'files' => $files,
            ];
        }

        return $results;
    }
}
