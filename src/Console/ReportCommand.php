<?php

declare(strict_types=1);

namespace Ginkelsoft\ComplianceHub\Console;

use Ginkelsoft\ComplianceHub\Actions\ResolveFamilyVersions;
use Ginkelsoft\ComplianceHub\Actions\VerifyAllChains;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Produces a bundled compliance report covering every audit log in the
 * family, in Markdown (default) or JSON. No personal data — counts and
 * verify status only.
 *
 * Intended for monthly export to the DPO, or to drop into a quarterly
 * board pack as evidence of GDPR art. 5(2) accountability.
 *
 *   php artisan compliance:report
 *   php artisan compliance:report --format=json
 *   php artisan compliance:report --output=storage/reports/2026-05.md
 */
class ReportCommand extends Command
{
    /** @var string */
    protected $signature = 'compliance:report
        {--format=markdown : Output format: markdown or json}
        {--output= : Write to this file instead of STDOUT}';

    /** @var string */
    protected $description = 'Produce a bundled compliance report across every audit log in the GinkelSoft compliance family.';

    public function handle(VerifyAllChains $verifier, ResolveFamilyVersions $versionResolver): int
    {
        $formatOption = $this->option('format');
        $format = is_string($formatOption) ? strtolower($formatOption) : 'markdown';

        if (! in_array($format, ['markdown', 'json'], true)) {
            $this->error("Unsupported format '{$format}'. Available: markdown, json.");

            return self::FAILURE;
        }

        $results = $verifier->verify();
        $familyVersions = $versionResolver->resolve();
        $generatedAt = Carbon::now()->utc();

        $rendered = $format === 'json'
            ? $this->renderJson($results, $familyVersions, $generatedAt)
            : $this->renderMarkdown($results, $familyVersions, $generatedAt);

        $outputOption = $this->option('output');

        if (is_string($outputOption) && $outputOption !== '') {
            $dir = dirname($outputOption);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($outputOption, $rendered);
            $this->info("Report written to {$outputOption}.");
        } else {
            $this->line($rendered);
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{label: string, table: string, present: bool, rows: int, verified: bool|null}>  $results
     * @param  array<string, string>  $familyVersions
     */
    private function renderJson(array $results, array $familyVersions, Carbon $generatedAt): string
    {
        $payload = [
            'generated_at' => $generatedAt->format(\DateTimeInterface::ATOM),
            'chains' => $results,
            'family_versions' => $familyVersions,
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '{}' : $encoded;
    }

    /**
     * @param  list<array{label: string, table: string, present: bool, rows: int, verified: bool|null}>  $results
     * @param  array<string, string>  $familyVersions
     */
    private function renderMarkdown(array $results, array $familyVersions, Carbon $generatedAt): string
    {
        $lines = [];
        $lines[] = '# GinkelSoft Compliance Report';
        $lines[] = '';
        $lines[] = 'Generated at: '.$generatedAt->format(\DateTimeInterface::ATOM).' UTC';
        $lines[] = '';
        $lines[] = '| Control | Table | Rows | Status |';
        $lines[] = '| ------- | ----- | ---: | ------ |';

        foreach ($results as $r) {
            if (! $r['present']) {
                $status = '— (table missing)';
            } elseif ($r['verified'] === true) {
                $status = 'OK';
            } else {
                $status = '**TAMPERED**';
            }
            $lines[] = sprintf('| %s | `%s` | %d | %s |', $r['label'], $r['table'], $r['rows'], $status);
        }

        $lines[] = '';
        $lines[] = 'This report contains **no personal data** — only audit-chain row counts and verification status.';
        $lines[] = '';
        $lines[] = '## Family package versions';
        $lines[] = '';
        $lines[] = '| Package | Version |';
        $lines[] = '| ------- | ------- |';

        foreach ($familyVersions as $package => $version) {
            $lines[] = sprintf('| `%s` | %s |', $package, $version);
        }

        $lines[] = '';

        return implode("\n", $lines);
    }
}
