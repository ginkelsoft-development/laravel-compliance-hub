<?php

declare(strict_types=1);

use Ginkelsoft\ComplianceCore\Config\LogSecret;
use Ginkelsoft\ComplianceCore\Support\HashChain;
use Illuminate\Support\Facades\DB;

it('compliance:verify exits 0 when all chains are empty', function (): void {
    $this->artisan('compliance:verify')->assertExitCode(0);
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
        ->and($decoded)->toHaveKeys(['generated_at', 'chains'])
        ->and(array_column($decoded['chains'], 'table'))->toEqualCanonicalizing([
            'retention_log',
            'forget_log',
            'subject_access_log',
            'consent_log',
            'breach_event_log',
        ]);

    @unlink($path);
});

it('compliance:report rejects an unknown format', function (): void {
    $this->artisan('compliance:report', ['--format' => 'pdf'])->assertFailed();
});
