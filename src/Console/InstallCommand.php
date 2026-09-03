<?php

declare(strict_types=1);

namespace Ginkelsoft\ComplianceHub\Console;

use Ginkelsoft\ComplianceHub\Actions\PublishFamilyAssets;
use Illuminate\Console\Command;

/**
 * Meta-command that publishes every `vendor:publish` tag registered by
 * the GinkelSoft compliance family in one pass, and runs the resulting
 * migrations.
 *
 * Without this command, installing the family means running
 * `vendor:publish --tag=...` eleven times by hand (one config + one
 * migrations tag per package, plus the hub's own config) and then
 * remembering to `php artisan migrate`. This command does all of that
 * in one call:
 *
 *   php artisan compliance:install
 *   php artisan compliance:install --force
 *   php artisan compliance:install --no-interaction
 *   php artisan compliance:install --skip-migrate
 *
 * `--no-interaction` is not declared on the signature: every Artisan
 * command already accepts it globally (it is a default Symfony Console
 * option), and redeclaring it throws "An option named ... already
 * exists." It works out of the box.
 *
 * Every family package auto-loads its own migrations unconditionally
 * (`$this->loadMigrationsFrom(...)` in its service provider), so a
 * plain `php artisan migrate` already creates every table without
 * publishing anything. Publishing the `*-migrations` tags copies the
 * *same* migrations into `database/migrations/` under a new,
 * timestamped filename — a different migration name as far as the
 * migrator is concerned. If both the vendor copy and the published
 * copy are pending, `migrate` runs both and the second `CREATE TABLE`
 * fails because the table already exists from the first. This is a
 * pre-existing limitation of the family's migration-publishing design,
 * not something this command can fix on its own — so the `migrate`
 * step is wrapped defensively: a failure here is reported clearly
 * without crashing, and does not lose the config/migration files that
 * were already published successfully.
 */
class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'compliance:install
        {--force : Overwrite any existing published files}
        {--skip-migrate : Publish everything but do not run `migrate`}';

    /** @var string */
    protected $description = 'Publish every config and migration tag in the GinkelSoft compliance family, then migrate.';

    public function handle(PublishFamilyAssets $publisher): int
    {
        $force = (bool) $this->option('force');

        $this->info('Publishing the GinkelSoft compliance family assets...');
        $this->newLine();

        $results = $publisher->publish($force);

        $rows = array_map(
            fn (array $r): array => [$r['label'], $r['tag'], $r['files']],
            $results,
        );

        $this->table(['Asset', 'Tag', 'Files'], $rows);

        $totalFiles = array_sum(array_column($results, 'files'));
        $this->info(sprintf('Published %d tag(s), %d file(s) total.', count($results), $totalFiles));

        if ($this->option('skip-migrate')) {
            $this->line('Skipping `migrate` (--skip-migrate given). Run `php artisan migrate` yourself when ready.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Running migrations...');

        try {
            $migrateExitCode = $this->call('migrate', array_filter([
                '--force' => $force,
            ]));
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('`migrate` threw an exception: '.$e->getMessage());
            $this->line('Assets were published, but the database was not fully migrated. This can happen if the tables already exist — see the class docblock for why publishing migrations in this family can collide with the auto-loaded copy. Re-run with --skip-migrate and migrate manually if needed.');

            return self::FAILURE;
        }

        if ($migrateExitCode !== self::SUCCESS) {
            $this->error('`migrate` failed. Assets were published, but the database was not fully migrated — see the output above.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('GinkelSoft compliance family installed. Run `php artisan compliance:verify` to check the audit-log chains.');

        return self::SUCCESS;
    }
}
