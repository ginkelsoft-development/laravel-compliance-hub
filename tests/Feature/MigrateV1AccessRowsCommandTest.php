<?php

declare(strict_types=1);

use Ginkelsoft\ComplianceCore\Config\LogSecret;
use Ginkelsoft\ComplianceCore\Support\HashChain;
use Ginkelsoft\ComplianceCore\Support\SubjectHash;
use Ginkelsoft\DataRetention\Models\RetentionLogEntry;
use Ginkelsoft\DataSubjectAccess\Models\SubjectAccessLogEntry;

/**
 * Seeds `retention_log` with a realistic mix of v1.x rows — ordinary
 * storage-limitation rows plus the old-style `subject_access_exported`
 * rows — and verifies `compliance:migrate-v1-access-rows` moves only
 * the latter into `subject_access_log`, correctly, completely, and
 * without ever touching the source table.
 */
function seedV1RetentionRow(array $overrides = []): RetentionLogEntry
{
    $secret = LogSecret::value();
    $previous = RetentionLogEntry::query()->orderByDesc('id')->first();
    $previousHash = $previous instanceof RetentionLogEntry ? $previous->hash : '';

    $payload = array_merge([
        'model_type' => 'App\\Models\\Client',
        'model_id' => '01HXYZFIXTURE001',
        'action' => 'deleted',
        'retention_period' => '5 years',
        'retention_field' => 'ended_at',
        'expired_at' => '2026-01-01 00:00:00',
        'performed_at' => '2026-05-28 02:00:00',
    ], $overrides);

    $hash = HashChain::compute($payload, $previousHash, $secret);

    return RetentionLogEntry::query()->create($payload + [
        'previous_hash' => $previousHash,
        'hash' => $hash,
    ]);
}

function seedV1SubjectAccessRow(string $subjectId, string $modelType, int $recordCount, string $performedAt): RetentionLogEntry
{
    $secret = LogSecret::value();
    $subjectHash = SubjectHash::compute($subjectId, $secret);

    return seedV1RetentionRow([
        'model_type' => $modelType,
        'model_id' => $subjectHash,
        'action' => 'subject_access_exported',
        'retention_period' => "{$recordCount} records",
        'retention_field' => 'subject_access',
        // v1.x subject-access rows have no real "expiry"; the column is
        // NOT NULL, so the same instant as performed_at was recorded.
        'expired_at' => $performedAt,
        'performed_at' => $performedAt,
    ]);
}

it('dry-run reports the rows that would move without writing anything', function (): void {
    seedV1RetentionRow(); // an ordinary retention row — must be ignored
    seedV1SubjectAccessRow('alice-01', 'App\\Models\\Profile', 3, '2026-05-28 03:00:00');
    seedV1SubjectAccessRow('bob-02', 'App\\Models\\Invoice', 7, '2026-05-28 04:00:00');

    $this->artisan('compliance:migrate-v1-access-rows', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run — no rows were written.')
        ->expectsOutputToContain('Qualifying v1.x subject-access rows in retention_log: 2')
        ->expectsOutputToContain('Would migrate: 2')
        ->assertExitCode(0);

    expect(SubjectAccessLogEntry::query()->count())->toBe(0);
    expect(RetentionLogEntry::query()->count())->toBe(3);
});

it('migrates qualifying rows completely and correctly, leaving retention_log untouched', function (): void {
    seedV1RetentionRow(overrides: ['model_id' => '01HXYZFIXTURE001']);
    seedV1RetentionRow(overrides: [
        'model_id' => '01HXYZFIXTURE002',
        'action' => 'anonymized',
        'performed_at' => '2026-05-28 02:00:01',
    ]);
    $accessRow1 = seedV1SubjectAccessRow('alice-01', 'App\\Models\\Profile', 3, '2026-05-28 03:00:00');
    $accessRow2 = seedV1SubjectAccessRow('bob-02', 'App\\Models\\Invoice', 7, '2026-05-28 04:00:00');

    $retentionLogHashesBefore = RetentionLogEntry::query()->orderBy('id')->pluck('hash')->all();
    $secret = LogSecret::value();

    $this->artisan('compliance:migrate-v1-access-rows')
        ->expectsOutputToContain('Qualifying v1.x subject-access rows in retention_log: 2')
        ->expectsOutputToContain('Migrated: 2')
        ->assertExitCode(0);

    // Exactly the qualifying rows landed in subject_access_log, mapped correctly.
    expect(SubjectAccessLogEntry::query()->count())->toBe(2);

    $alice = SubjectAccessLogEntry::query()->where('subject_hash', $accessRow1->model_id)->first();
    expect($alice)->not->toBeNull()
        ->and($alice->model_type)->toBe('App\\Models\\Profile')
        ->and($alice->record_count)->toBe(3)
        ->and($alice->format)->toBe('legacy')
        ->and($alice->performed_at->format('Y-m-d H:i:s'))->toBe('2026-05-28 03:00:00');

    $bob = SubjectAccessLogEntry::query()->where('subject_hash', $accessRow2->model_id)->first();
    expect($bob)->not->toBeNull()
        ->and($bob->model_type)->toBe('App\\Models\\Invoice')
        ->and($bob->record_count)->toBe(7)
        ->and($bob->format)->toBe('legacy');

    // The new subject_access_log chain is internally valid.
    $newChainRows = SubjectAccessLogEntry::query()->orderBy('id')->get()
        ->map(fn ($row) => $row->getAttributes())->all();
    expect(HashChain::verify($newChainRows, $secret))->toBeTrue();

    // retention_log itself was never mutated: same row count, same hashes, same order.
    expect(RetentionLogEntry::query()->count())->toBe(4);
    expect(RetentionLogEntry::query()->orderBy('id')->pluck('hash')->all())->toBe($retentionLogHashesBefore);

    $retentionChainRows = RetentionLogEntry::query()->orderBy('id')->get()
        ->map(fn ($row) => $row->getAttributes())->all();
    expect(HashChain::verify($retentionChainRows, $secret))->toBeTrue();
});

it('is idempotent: running it again migrates nothing new and creates no duplicates', function (): void {
    seedV1SubjectAccessRow('alice-01', 'App\\Models\\Profile', 3, '2026-05-28 03:00:00');
    seedV1SubjectAccessRow('bob-02', 'App\\Models\\Invoice', 7, '2026-05-28 04:00:00');

    $this->artisan('compliance:migrate-v1-access-rows')->assertExitCode(0);
    expect(SubjectAccessLogEntry::query()->count())->toBe(2);

    $this->artisan('compliance:migrate-v1-access-rows')
        ->expectsOutputToContain('Already present in subject_access_log: 2')
        ->expectsOutputToContain('Migrated: 0')
        ->assertExitCode(0);

    expect(SubjectAccessLogEntry::query()->count())->toBe(2);

    // A third run, also as a dry run, must agree nothing is left to do.
    $this->artisan('compliance:migrate-v1-access-rows', ['--dry-run' => true])
        ->expectsOutputToContain('Would migrate: 0')
        ->assertExitCode(0);

    expect(SubjectAccessLogEntry::query()->count())->toBe(2);
});

it('skips rows it cannot interpret and reports why, without failing the run', function (): void {
    // record_count cannot be parsed from a non-conforming retention_period.
    seedV1RetentionRow([
        'model_id' => SubjectHash::compute('carol-03', LogSecret::value()),
        'action' => 'subject_access_exported',
        'retention_period' => 'three records',
        'retention_field' => 'subject_access',
        'expired_at' => '2026-05-28 05:00:00',
        'performed_at' => '2026-05-28 05:00:00',
    ]);
    // model_id is not a valid subject hash.
    seedV1RetentionRow([
        'model_id' => 'not-a-hash',
        'action' => 'subject_access_exported',
        'retention_period' => '2 records',
        'retention_field' => 'subject_access',
        'expired_at' => '2026-05-28 06:00:00',
        'performed_at' => '2026-05-28 06:00:00',
    ]);
    seedV1SubjectAccessRow('dave-04', 'App\\Models\\Profile', 1, '2026-05-28 07:00:00');

    $this->artisan('compliance:migrate-v1-access-rows')
        ->expectsOutputToContain('Qualifying v1.x subject-access rows in retention_log: 3')
        ->expectsOutputToContain('Migrated: 1')
        ->expectsOutputToContain('Skipped 2 row(s) that could not be interpreted:')
        ->assertExitCode(0);

    expect(SubjectAccessLogEntry::query()->count())->toBe(1);
});

it('accepts a custom --format for migrated rows', function (): void {
    seedV1SubjectAccessRow('eve-05', 'App\\Models\\Profile', 2, '2026-05-28 08:00:00');

    $this->artisan('compliance:migrate-v1-access-rows', ['--format' => 'json-legacy'])
        ->assertExitCode(0);

    expect(SubjectAccessLogEntry::query()->first()->format)->toBe('json-legacy');
});
