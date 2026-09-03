<?php

declare(strict_types=1);

namespace Ginkelsoft\ComplianceHub\Actions;

use Ginkelsoft\ComplianceCore\Config\LogSecret;
use Ginkelsoft\ComplianceCore\Support\HashChain;
use Ginkelsoft\ComplianceCore\Support\SubjectHash;
use Ginkelsoft\DataRetention\Models\RetentionLogEntry;
use Ginkelsoft\DataSubjectAccess\Models\SubjectAccessLogEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moves v1.x subject-access rows out of `retention_log` and into their
 * own `subject_access_log` table.
 *
 * ## Background
 *
 * Before the v2.x split (see `laravel-compliance-core`'s UPGRADE.md),
 * subject-access exports (GDPR art. 15) were logged into the shared
 * `retention_log` table with:
 *
 *   - `action`          = `subject_access_exported`
 *   - `retention_field` = `subject_access`
 *   - `model_id`        = the subject's {@see SubjectHash}
 *   - `retention_period` = a human string of the form `"<n> records"`,
 *                           the only place the record count was kept
 *
 * `subject_access_log` (v2.x) has a purpose-built schema instead:
 * `subject_hash`, `model_type`, `record_count`, `format`,
 * `performed_at`, plus its own independent hash chain. This action
 * reads the qualifying `retention_log` rows and writes the equivalent
 * `subject_access_log` rows.
 *
 * ## What this action deliberately does NOT do
 *
 * It never updates or deletes rows in `retention_log`. That table is
 * append-only by design ({@see RetentionLogEntry} blocks `update()`
 * and `delete()` at the model level) because every row is part of a
 * tamper-evident SHA-256 hash chain — mutating or removing a row would
 * invalidate every hash after it. The upstream UPGRADE.md is explicit
 * that leaving these rows in place is safe and expected:
 * "You do not need to migrate the old subject_access rows out of
 * retention_log. They stay where they are." This command exists for
 * teams who want a clean split anyway (e.g. before decommissioning
 * `retention_log` reads in a reporting tool); it is optional and
 * additive, never destructive.
 *
 * The hub itself owns no migrations or tables (see
 * `ComplianceHubServiceProvider`), so this action does not introduce
 * one either. Idempotency is achieved by re-deriving, for every
 * candidate `retention_log` row, whether an equivalent row already
 * exists in `subject_access_log` — matched on `subject_hash` +
 * `model_type` + `performed_at` + `record_count`, which uniquely
 * identifies one v1.x export event in practice.
 *
 * ## New hash chain
 *
 * Migrated rows are appended to whatever the current `subject_access_log`
 * chain is (empty, or already containing v2.x-native exports) using the
 * same {@see HashChain} primitives the rest of the family uses. This is
 * a **newly computed** chain for these rows, not a replay of their
 * original `retention_log` hashes (which belonged to a different chain
 * with different payload shapes). The original, byte-identical
 * `retention_log` chain is left completely untouched, so it keeps
 * verifying exactly as it did before.
 */
final class MigrateV1AccessRows
{
    public const ACTION = 'subject_access_exported';

    public const RETENTION_FIELD = 'subject_access';

    private const SUBJECT_HASH_PATTERN = '/^[0-9a-f]{64}$/i';

    private const RECORD_COUNT_PATTERN = '/^(\d+)\s*records?$/i';

    /**
     * Run the migration.
     *
     * @param  bool  $dryRun  When true, nothing is written; the summary
     *                        reports exactly what would happen.
     * @param  string  $format  Value stored in the new `format` column.
     *                          v1.x never recorded a format, so this is
     *                          an operator-supplied default (e.g.
     *                          `legacy`) applied to every migrated row.
     * @param  int  $chunkSize  Rows read per chunk from `retention_log`.
     * @return array{
     *     dry_run: bool,
     *     qualifying: int,
     *     already_migrated: int,
     *     migrated: int,
     *     skipped: list<array{retention_log_id: int, reason: string}>,
     *     rows: list<array{retention_log_id: int, model_type: string, subject_hash: string, record_count: int, performed_at: string, status: string}>,
     * }
     */
    public function migrate(bool $dryRun = false, string $format = 'legacy', int $chunkSize = 200): array
    {
        $secret = LogSecret::value();

        $qualifying = 0;
        $alreadyMigrated = 0;
        $migrated = 0;
        $skipped = [];
        $rows = [];

        RetentionLogEntry::query()
            ->where('retention_field', self::RETENTION_FIELD)
            ->where('action', self::ACTION)
            ->chunkById($chunkSize, function ($chunk) use (
                $dryRun, $format, $secret,
                &$qualifying, &$alreadyMigrated, &$migrated, &$skipped, &$rows,
            ): void {
                foreach ($chunk as $entry) {
                    /** @var RetentionLogEntry $entry */
                    $qualifying++;

                    $subjectHash = $entry->model_id;

                    if (! is_string($subjectHash) || preg_match(self::SUBJECT_HASH_PATTERN, $subjectHash) !== 1) {
                        $skipped[] = ['retention_log_id' => $entry->id, 'reason' => 'model_id is not a 64-char subject hash'];

                        continue;
                    }

                    $recordCount = $this->parseRecordCount($entry->retention_period);

                    if ($recordCount === null) {
                        $skipped[] = ['retention_log_id' => $entry->id, 'reason' => "retention_period '{$entry->retention_period}' does not match '<n> records'"];

                        continue;
                    }

                    $performedAt = $entry->performed_at instanceof Carbon
                        ? $entry->performed_at->clone()->utc()
                        : Carbon::parse((string) $entry->performed_at)->utc();
                    $performedAtString = $performedAt->format('Y-m-d H:i:s');

                    $alreadyExists = SubjectAccessLogEntry::query()
                        ->where('subject_hash', $subjectHash)
                        ->where('model_type', $entry->model_type)
                        ->where('record_count', $recordCount)
                        ->where('performed_at', $performedAtString)
                        ->exists();

                    if ($alreadyExists) {
                        $alreadyMigrated++;
                        $rows[] = [
                            'retention_log_id' => $entry->id,
                            'model_type' => $entry->model_type,
                            'subject_hash' => $subjectHash,
                            'record_count' => $recordCount,
                            'performed_at' => $performedAtString,
                            'status' => 'already_migrated',
                        ];

                        continue;
                    }

                    if ($dryRun) {
                        $migrated++;
                        $rows[] = [
                            'retention_log_id' => $entry->id,
                            'model_type' => $entry->model_type,
                            'subject_hash' => $subjectHash,
                            'record_count' => $recordCount,
                            'performed_at' => $performedAtString,
                            'status' => 'would_migrate',
                        ];

                        continue;
                    }

                    DB::transaction(function () use ($entry, $subjectHash, $recordCount, $format, $performedAtString, $secret): void {
                        // Re-check for a concurrently-created duplicate, and lock
                        // the tail of the chain so two runs can never both
                        // compute the same `previous_hash`.
                        $previous = SubjectAccessLogEntry::query()
                            ->orderByDesc('id')
                            ->lockForUpdate()
                            ->first();

                        $duplicate = SubjectAccessLogEntry::query()
                            ->where('subject_hash', $subjectHash)
                            ->where('model_type', $entry->model_type)
                            ->where('record_count', $recordCount)
                            ->where('performed_at', $performedAtString)
                            ->lockForUpdate()
                            ->exists();

                        if ($duplicate) {
                            return;
                        }

                        $previousHash = $previous instanceof SubjectAccessLogEntry ? $previous->hash : '';

                        $payload = [
                            'subject_hash' => $subjectHash,
                            'model_type' => $entry->model_type,
                            'record_count' => $recordCount,
                            'format' => $format,
                            'performed_at' => $performedAtString,
                        ];

                        $hash = HashChain::compute($payload, $previousHash, $secret);

                        SubjectAccessLogEntry::query()->create($payload + [
                            'previous_hash' => $previousHash,
                            'hash' => $hash,
                        ]);
                    });

                    $migrated++;
                    $rows[] = [
                        'retention_log_id' => $entry->id,
                        'model_type' => $entry->model_type,
                        'subject_hash' => $subjectHash,
                        'record_count' => $recordCount,
                        'performed_at' => $performedAtString,
                        'status' => 'migrated',
                    ];
                }
            }, 'id');

        return [
            'dry_run' => $dryRun,
            'qualifying' => $qualifying,
            'already_migrated' => $alreadyMigrated,
            'migrated' => $migrated,
            'skipped' => $skipped,
            'rows' => $rows,
        ];
    }

    /**
     * Parse the v1.x `"<n> records"` convention used in
     * `retention_log.retention_period` for subject-access rows.
     */
    private function parseRecordCount(?string $retentionPeriod): ?int
    {
        if ($retentionPeriod === null) {
            return null;
        }

        if (preg_match(self::RECORD_COUNT_PATTERN, trim($retentionPeriod), $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
