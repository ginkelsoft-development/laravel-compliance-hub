<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Ginkelsoft\ComplianceCore\Config\LogSecret;
use Ginkelsoft\ComplianceCore\Support\HashChain;
use Ginkelsoft\ComplianceHub\Actions\ResolveFamilyVersions;
use Illuminate\Support\Facades\DB;

// De drie regels worden in één keer geschreven (SymfonyStyle::warn schrijft de
// hele meerregelige tekst in één write-call), dus we controleren ze hieronder
// als één aaneengesloten substring in plaats van losse expectsOutputToContain
// calls per regel: Mockery matcht per doWrite-call maar één expectation
// tegelijk, dus losse calls op dezelfde write zouden elkaar wegconcurreren.
function missingSecretWarningText(): string
{
    return <<<'TEXT'
        ⚠ compliance.log_secret is empty.
          Audit-log hash chains are NOT tamper-evident against attackers with DB write access.
          Set COMPLIANCE_LOG_SECRET in .env and run `compliance:verify` again.
        TEXT;
}

it('compliance:verify exits 0 when all chains are empty', function (): void {
    $this->artisan('compliance:verify')->assertExitCode(0);
});

it('compliance:verify warns but exits 0 when log_secret is empty and every chain is empty', function (): void {
    config()->set('compliance.log_secret', '');

    $this->artisan('compliance:verify')
        ->expectsOutputToContain(missingSecretWarningText())
        ->assertExitCode(0);
});

it('compliance:verify warns and exits non-zero when log_secret is empty and a chain has rows', function (): void {
    config()->set('compliance.log_secret', '');

    $payload = [
        'subject_id' => '01HXYZ',
        'purpose' => 'newsletter',
        'version' => '2026-05',
        'action' => 'granted',
        'source' => 'web',
        'metadata' => null,
        'occurred_at' => '2026-05-28 10:00:00',
    ];
    $hash = HashChain::compute($payload, '', '');

    DB::table('consent_log')->insert($payload + [
        'previous_hash' => '',
        'hash' => $hash,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('compliance:verify')
        ->expectsOutputToContain(missingSecretWarningText())
        // De resultaattabel moet er ondanks de waarschuwing nog steeds staan: de
        // run breekt niet af, de operator ziet nog steeds elke chain-status.
        ->expectsOutputToContain('consent_log')
        ->assertFailed();
});

it('compliance:verify does not warn when log_secret is set', function (): void {
    $this->artisan('compliance:verify')
        ->doesntExpectOutputToContain('compliance.log_secret is empty')
        ->assertExitCode(0);
});

it('compliance:verify exits non-zero when a chain is tampered', function (): void {
    $secret = LogSecret::value();
    $payload = [
        'subject_id' => '01HXYZ',
        'purpose' => 'newsletter',
        'version' => '2026-05',
        'action' => 'granted',
        'source' => 'web',
        'metadata' => null,
        'occurred_at' => '2026-05-28 10:00:00',
    ];
    $hash = HashChain::compute($payload, '', $secret);

    DB::table('consent_log')->insert($payload + [
        'previous_hash' => '',
        'hash' => $hash,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('consent_log')->update(['action' => 'withdrawn']);

    $this->artisan('compliance:verify')->assertFailed();
});

it('compliance:report renders markdown by default and writes every chain', function (): void {
    $path = sys_get_temp_dir().'/compliance-hub-test-'.uniqid().'.md';

    $this->artisan('compliance:report', ['--output' => $path])->assertExitCode(0);

    $content = (string) file_get_contents($path);

    expect($content)->toContain('# GinkelSoft Compliance Report')
        ->and($content)->toContain('retention_log')
        ->and($content)->toContain('forget_log')
        ->and($content)->toContain('subject_access_log')
        ->and($content)->toContain('consent_log')
        ->and($content)->toContain('breach_event_log');

    @unlink($path);
});

it('compliance:report renders JSON when asked', function (): void {
    $path = sys_get_temp_dir().'/compliance-hub-test-'.uniqid().'.json';

    $this->artisan('compliance:report', ['--format' => 'json', '--output' => $path])->assertExitCode(0);

    $content = (string) file_get_contents($path);
    $decoded = json_decode($content, true);

    expect($decoded)->toBeArray()
        ->and($decoded)->toHaveKeys(['generated_at', 'chains', 'family_versions'])
        ->and(array_column($decoded['chains'], 'table'))->toEqualCanonicalizing([
            'retention_log',
            'forget_log',
            'subject_access_log',
            'consent_log',
            'breach_event_log',
        ]);

    expect($decoded['family_versions'])->toHaveKeys([
        'ginkelsoft/laravel-compliance-hub',
        'ginkelsoft/laravel-compliance-core',
        'ginkelsoft/laravel-data-retention',
        'ginkelsoft/laravel-data-right-to-be-forgotten',
        'ginkelsoft/laravel-data-subject-access',
        'ginkelsoft/laravel-data-consent',
        'ginkelsoft/laravel-data-breach-registry',
    ]);

    foreach ($decoded['family_versions'] as $package => $version) {
        expect($version)->toBeString()->not->toBe('');
    }

    @unlink($path);
});

it('compliance:report rejects an unknown format', function (): void {
    $this->artisan('compliance:report', ['--format' => 'pdf'])->assertFailed();
});

it('compliance:report Markdown output includes a family package versions section', function (): void {
    $path = sys_get_temp_dir().'/compliance-hub-test-'.uniqid().'.md';

    $this->artisan('compliance:report', ['--output' => $path])->assertExitCode(0);

    $content = (string) file_get_contents($path);

    expect($content)->toContain('## Family package versions')
        ->and($content)->toContain('ginkelsoft/laravel-compliance-hub')
        ->and($content)->toContain('ginkelsoft/laravel-compliance-core')
        ->and($content)->toContain('ginkelsoft/laravel-data-retention')
        ->and($content)->toContain('ginkelsoft/laravel-data-right-to-be-forgotten')
        ->and($content)->toContain('ginkelsoft/laravel-data-subject-access')
        ->and($content)->toContain('ginkelsoft/laravel-data-consent')
        ->and($content)->toContain('ginkelsoft/laravel-data-breach-registry');

    @unlink($path);
});

it('ResolveFamilyVersions reports "not installed" for a missing package without throwing', function (): void {
    $resolver = new ResolveFamilyVersions;

    expect(InstalledVersions::isInstalled('ginkelsoft/this-package-does-not-exist'))->toBeFalse();

    $reflection = new ReflectionMethod($resolver, 'resolveOne');
    $reflection->setAccessible(true);

    expect($reflection->invoke($resolver, 'ginkelsoft/this-package-does-not-exist'))->toBe('not installed');
});
