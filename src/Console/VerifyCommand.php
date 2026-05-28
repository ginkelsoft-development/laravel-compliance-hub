<?php

declare(strict_types=1);

namespace Ginkelsoft\ComplianceHub\Console;

use Ginkelsoft\ComplianceHub\Actions\VerifyAllChains;
use Illuminate\Console\Command;

/**
 * Verifies every audit-log hash chain in the GinkelSoft compliance
 * family and exits non-zero when at least one chain fails.
 *
 * Intended use in a scheduler:
 *
 *   $schedule->command('compliance:verify')->dailyAt('03:00')
 *       ->onFailure(fn () => notifyTeam('compliance chain broken'));
 */
class VerifyCommand extends Command
{
    /** @var string */
    protected $signature = 'compliance:verify';

    /** @var string */
    protected $description = 'Verify the hash chain of every audit log in the GinkelSoft compliance family.';

    public function handle(VerifyAllChains $verifier): int
    {
        $results = $verifier->verify();

        $rows = [];
        $broken = 0;

        foreach ($results as $r) {
            if (! $r['present']) {
                $status = '— (table missing)';
            } elseif ($r['verified'] === true) {
                $status = 'OK';
            } else {
                $status = 'TAMPERED';
                $broken++;
            }

            $rows[] = [$r['label'], $r['table'], $r['rows'], $status];
        }

        $this->table(['Control', 'Table', 'Rows', 'Status'], $rows);

        if ($broken > 0) {
            $this->error(sprintf('%d audit-log chain(s) failed verification.', $broken));

            return self::FAILURE;
        }

        $this->info('All present audit-log chains verified.');

        return self::SUCCESS;
    }
}
