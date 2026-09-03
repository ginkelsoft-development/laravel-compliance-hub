<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

// Elke gepubliceerde `-config` en `-migrations` bestand landt in de
// Testbench-skeleton onder vendor/, die niet automatisch wordt
// opgeruimd tussen runs. We ruimen zelf op zodat herhaalde lokale
// testruns geen bestanden blijven opstapelen.
function cleanUpPublishedFamilyFiles(): void
{
    foreach ([
        'compliance.php',
        'data-retention.php',
        'forget.php',
        'subject-access.php',
        'consent.php',
        'breach.php',
    ] as $config) {
        @unlink(config_path($config));
    }

    foreach ([
        'create_retention_log_table',
        'create_forget_log_table',
        'create_subject_access_log_table',
        'create_consent_log_table',
        'create_breach_register_table',
        'create_breach_event_log_table',
    ] as $migrationName) {
        foreach (glob(database_path("migrations/*_{$migrationName}.php")) ?: [] as $file) {
            @unlink($file);
        }
    }
}

afterEach(function (): void {
    cleanUpPublishedFamilyFiles();
});

it('compliance:install publishes all 11 family tags', function (): void {
    $this->artisan('compliance:install', ['--skip-migrate' => true])
        ->expectsOutputToContain('compliance-config')
        ->expectsOutputToContain('data-retention-config')
        ->expectsOutputToContain('data-retention-migrations')
        ->expectsOutputToContain('forget-config')
        ->expectsOutputToContain('forget-migrations')
        ->expectsOutputToContain('subject-access-config')
        ->expectsOutputToContain('subject-access-migrations')
        ->expectsOutputToContain('consent-config')
        ->expectsOutputToContain('consent-migrations')
        ->expectsOutputToContain('breach-config')
        ->expectsOutputToContain('breach-migrations')
        ->assertExitCode(0);
});

it('compliance:install writes every config file to disk', function (): void {
    $this->artisan('compliance:install', ['--skip-migrate' => true, '--force' => true])
        ->assertExitCode(0);

    expect(file_exists(config_path('compliance.php')))->toBeTrue()
        ->and(file_exists(config_path('data-retention.php')))->toBeTrue()
        ->and(file_exists(config_path('forget.php')))->toBeTrue()
        ->and(file_exists(config_path('subject-access.php')))->toBeTrue()
        ->and(file_exists(config_path('consent.php')))->toBeTrue()
        ->and(file_exists(config_path('breach.php')))->toBeTrue();
});

it('compliance:install --skip-migrate does not run migrate', function (): void {
    $this->artisan('compliance:install', ['--skip-migrate' => true])
        ->expectsOutputToContain('Skipping `migrate`')
        ->doesntExpectOutputToContain('Running migrations')
        ->assertExitCode(0);
});

it('compliance:install runs migrate by default', function (): void {
    $this->artisan('compliance:install')
        ->expectsOutputToContain('Running migrations');
});

it('compliance:install reports a clear, non-fatal error if migrate cannot complete', function (): void {
    // De familie laadt haar eigen migraties altijd automatisch (elke
    // ServiceProvider roept loadMigrationsFrom onvoorwaardelijk aan), dus
    // een net gepubliceerde -migrations kopie met een nieuwe timestamp
    // botst onvermijdelijk met de al bestaande tabel. compliance:install
    // mag hier niet op crashen — het moet dit netjes melden.
    File::ensureDirectoryExists(database_path('migrations'));

    $this->artisan('compliance:install', ['--force' => true])
        ->assertFailed();
});
