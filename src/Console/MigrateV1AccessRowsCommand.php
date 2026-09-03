<?php

declare(strict_types=1);

namespace Ginkelsoft\ComplianceHub\Console;

use Ginkelsoft\ComplianceHub\Actions\MigrateV1AccessRows;
use Illuminate\Console\Command;

/**
 * Moves v1.x subject-access rows out of `retention_log` into the
 * purpose-built `subject_access_log` table.
 *
 * This is opt-in cleanup, not a required upgrade step — see
 * {@see MigrateV1AccessRows} for why the original `retention_log` rows
 * are left in place rather than updated or deleted.
 *
 *   php artisan compliance:migrate-v1-access-rows --dry-run
 *   php artisan compliance:migrate-v1-access-rows
 *   php artisan compliance:migrate-v1-access-rows --format=json
 *
 * Safe to run repeatedly: rows that were already migrated (or that
 * already exist natively in `subject_access_log`) are detected and
 * skipped, so re-running never creates duplicates.
 */
class MigrateV1AccessRowsCommand extends Command
{
    /** @var string */
    protected $signature = 'compliance:migrate-v1-access-rows
        {--dry-run : Report what would be migrated without writing anything}
        {--format=legacy : Value stored in subject_access_log.format for migrated rows (v1.x never recorded one)}
        {--chunk=200 : Number of retention_log rows read per chunk}';

    /** @var string */
    protected $description = 'Move v1.x subject-access rows from retention_log into subject_access_log.';

    public function handle(MigrateV1AccessRows $migrator): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $formatOption = $this->option('format');
        $format = is_string($formatOption) && $formatOption !== '' ? $formatOption : 'legacy';

        $chunkOption = $this->option('chunk');
        $chunk = is_numeric($chunkOption) ? max(1, (int) $chunkOption) : 200;

        $summary = $migrator->migrate(dryRun: $dryRun, format: $format, chunkSize: $chunk);

        if ($dryRun) {
            $this->info('Dry run — no rows were written.');
        }

        $this->line(sprintf(
            'Qualifying v1.x subject-access rows in retention_log: %d',
            $summary['qualifying'],
        ));
        $this->line(sprintf('Already present in subject_access_log: %d', $summary['already_migrated']));
        $this->line(sprintf(
            '%s: %d',
            $dryRun ? 'Would migrate' : 'Migrated',
            $summary['migrated'],
        ));

        if ($summary['skipped'] !== []) {
            $this->warn(sprintf('Skipped %d row(s) that could not be interpreted:', count($summary['skipped'])));

            $this->table(
                ['retention_log.id', 'reason'],
                array_map(
                    fn (array $s): array => [$s['retention_log_id'], $s['reason']],
                    $summary['skipped'],
                ),
            );
        }

        if ($this->output->isVerbose() && $summary['rows'] !== []) {
            $this->table(
                ['retention_log.id', 'model_type', 'subject_hash', 'record_count', 'performed_at', 'status'],
                array_map(
                    fn (array $r): array => [
                        $r['retention_log_id'],
                        $r['model_type'],
                        substr($r['subject_hash'], 0, 12).'…',
                        $r['record_count'],
                        $r['performed_at'],
                        $r['status'],
                    ],
                    $summary['rows'],
                ),
            );
        }

        $this->info($dryRun
            ? 'Dry run complete. Re-run without --dry-run to write these rows.'
            : 'Migration complete. retention_log was left untouched — see the class docblock for why.');

        return self::SUCCESS;
    }
}
