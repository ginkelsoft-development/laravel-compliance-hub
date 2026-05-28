<?php

declare(strict_types=1);

namespace Ginkelsoft\ComplianceHub\Actions;

use Ginkelsoft\ComplianceCore\Config\LogSecret;
use Ginkelsoft\ComplianceCore\Support\HashChain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verifies the SHA-256 hash chain of every audit log in the
 * GinkelSoft compliance family.
 *
 * Returns one entry per chain. A `null` value for `verified` means the
 * table is not present in the database (the family member's migration
 * has not been run); a `bool` means the chain was verified (true) or
 * found tampered (false).
 *
 * Tables not present in the schema do not count as failures — the
 * `VerifyCommand` reports them as "missing" rather than an error so a
 * partial install does not page anyone.
 */
final class VerifyAllChains
{
    /**
     * The audit-log tables in the family, in chronological-feature order.
     *
     * Each entry is `[label, table_name]`. The label is shown to the
     * operator; the table name is what is queried.
     *
     * @return list<array{label: string, table: string}>
     */
    public function tables(): array
    {
        return [
            ['label' => 'Storage limitation (retention_log)',         'table' => 'retention_log'],
            ['label' => 'Right to be forgotten (forget_log)',         'table' => 'forget_log'],
            ['label' => 'Subject access (subject_access_log)',        'table' => 'subject_access_log'],
            ['label' => 'Consent (consent_log)',                      'table' => 'consent_log'],
            ['label' => 'Breach event log (breach_event_log)',        'table' => 'breach_event_log'],
        ];
    }

    /**
     * Verify every chain.
     *
     * @return list<array{label: string, table: string, present: bool, rows: int, verified: bool|null}>
     */
    public function verify(): array
    {
        $secret = LogSecret::value();
        $out = [];

        foreach ($this->tables() as $entry) {
            $table = $entry['table'];
            $label = $entry['label'];

            if (! Schema::hasTable($table)) {
                $out[] = [
                    'label' => $label,
                    'table' => $table,
                    'present' => false,
                    'rows' => 0,
                    'verified' => null,
                ];

                continue;
            }

            /** @var list<array<string, mixed>> $rows */
            $rows = DB::table($table)->orderBy('id')->get()
                ->map(fn ($row) => (array) $row)
                ->all();

            $out[] = [
                'label' => $label,
                'table' => $table,
                'present' => true,
                'rows' => count($rows),
                'verified' => HashChain::verify($rows, $secret),
            ];
        }

        return $out;
    }
}
