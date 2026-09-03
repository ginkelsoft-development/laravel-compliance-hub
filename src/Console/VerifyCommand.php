<?php

declare(strict_types=1);

namespace Ginkelsoft\ComplianceHub\Console;

use Ginkelsoft\ComplianceCore\Config\LogSecret;
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

    /**
     * Shown when `compliance.log_secret` (and its legacy fallback) are
     * both empty. An empty secret means every chain is signed with an
     * empty string, so verification always "passes" without actually
     * proving anything — this is a developer misconfiguration, not a
     * tampering finding, so it must not fail the run by itself.
     */
    private const MISSING_SECRET_WARNING = 'compliance.log_secret is empty — audit-log chains are being signed '
        .'with an empty secret and verification cannot detect tampering. Set COMPLIANCE_LOG_SECRET (or '
        .'DATA_RETENTION_LOG_SECRET for legacy installs) before relying on compliance:verify.';

    public function handle(VerifyAllChains $verifier): int
    {
        $secretMissing = LogSecret::value() === '';

        if ($secretMissing) {
            $this->warn(self::MISSING_SECRET_WARNING);
        }

        $results = $verifier->verify();

        $rows = [];
        $broken = 0;
        $hasData = false;

        foreach ($results as $r) {
            if (! $r['present']) {
                $status = '— (table missing)';
            } elseif ($r['verified'] === true) {
                $status = 'OK';
            } else {
                $status = 'TAMPERED';
                $broken++;
            }

            if ($r['rows'] > 0) {
                $hasData = true;
            }

            $rows[] = [$r['label'], $r['table'], $r['rows'], $status];
        }

        $this->table(['Control', 'Table', 'Rows', 'Status'], $rows);

        if ($broken > 0) {
            $this->error(sprintf('%d audit-log chain(s) failed verification.', $broken));

            return self::FAILURE;
        }

        // An empty secret makes "verified" meaningless once there is data
        // to verify: everything is signed and checked with the same empty
        // string, so a tampered row would still say "OK". Fail the run so
        // it cannot be trusted silently — but only once there is anything
        // to actually mis-verify.
        if ($secretMissing && $hasData) {
            $this->error('Audit-log chains contain data but were verified with an empty log_secret; this run cannot be trusted.');

            return self::FAILURE;
        }

        $this->info('All present audit-log chains verified.');

        return self::SUCCESS;
    }
}
