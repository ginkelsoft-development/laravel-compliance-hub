<?php

declare(strict_types=1);

use Ginkelsoft\ComplianceCore\Config\LogSecret;
use Ginkelsoft\ComplianceCore\Support\HashChain;
use Ginkelsoft\ComplianceHub\Actions\VerifyAllChains;
use Illuminate\Support\Facades\DB;

it('reports every chain as present after running all family migrations', function (): void {
    $result = (new VerifyAllChains)->verify();

    $tables = array_column($result, 'table');

    expect($tables)->toEqualCanonicalizing([
        'retention_log',
        'forget_log',
        'subject_access_log',
        'consent_log',
        'breach_event_log',
    ]);

    foreach ($result as $r) {
        expect($r['present'])->toBeTrue();
        expect($r['rows'])->toBe(0);
        // An empty chain is trivially verified.
        expect($r['verified'])->toBeTrue();
    }
});

it('verifies a chain with content correctly', function (): void {
    // Manually seed one consent_log row using the canonical hash flow.
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

    $result = (new VerifyAllChains)->verify();
    $consent = collect($result)->firstWhere('table', 'consent_log');

    expect($consent['rows'])->toBe(1);
    expect($consent['verified'])->toBeTrue();
});

it('detects a tampered chain', function (): void {
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

    // Tamper: silently overwrite the action field; the hash now no longer matches.
    DB::table('consent_log')->update(['action' => 'withdrawn']);

    $result = (new VerifyAllChains)->verify();
    $consent = collect($result)->firstWhere('table', 'consent_log');

    expect($consent['verified'])->toBeFalse();
});
