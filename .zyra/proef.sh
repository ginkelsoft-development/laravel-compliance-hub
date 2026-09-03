#!/usr/bin/env bash
#
# Proefdraai-script voor laravel-compliance-hub.
#
# Dit is een Composer-pakket (geen op zichzelf staande Laravel-app): er is
# geen artisan/.env aan de root. Orchestra Testbench levert een
# wegwerp-Laravel-app die het pakket (en al zijn familiepakketten)
# automatisch laadt via `vendor/bin/testbench`.
#
# Dit script:
#   1. kloont de vijf familiepakketten als siblings naast deze map (net als
#      CI, nodig voor de Composer path-repositories in composer.json),
#   2. draait composer install,
#   3. migreert een schone sqlite-database (elke familiemigratie wordt
#      automatisch geladen door de service providers van de sibling
#      packages, geen publish nodig),
#   4. zet twee voorbeeld-rijen in `retention_log` neer zoals het
#      monolithische v1.x-pakket subject-access exports logde, zodat
#      `compliance:migrate-v1-access-rows` iets te doen heeft,
#   5. draait het nieuwe commando eerst met --dry-run en dan echt, zodat je
#      meteen ziet dat het werkt,
#   6. start `php artisan serve` (via testbench) op 0.0.0.0:$PORT zodat een
#      mens de draaiende app kan zien.
#
# Extra commando's om zelf te proberen na het starten (nieuwe shell):
#   vendor/bin/testbench compliance:install --skip-migrate
#   vendor/bin/testbench compliance:verify
#   vendor/bin/testbench compliance:report
#   vendor/bin/testbench compliance:migrate-v1-access-rows --dry-run
#   vendor/bin/testbench compliance:migrate-v1-access-rows -v
set -euo pipefail

cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

PORT="${PORT:-8000}"

echo "== laravel-compliance-hub: proefdraai =="

SIBLINGS=(
    laravel-compliance-core
    laravel-data-retention
    laravel-data-right-to-be-forgotten
    laravel-data-subject-access
    laravel-data-consent
    laravel-data-breach-registry
)

echo "-- familiepakketten (path-repositories) controleren"
for repo in "${SIBLINGS[@]}"; do
    if [ ! -d "../$repo" ]; then
        echo "   klonen: $repo"
        git clone --depth 1 --branch development \
            "https://github.com/ginkelsoft-development/$repo.git" "../$repo"
    else
        echo "   aanwezig: $repo"
    fi
done

echo "-- composer install"
composer install --no-interaction

SKELETON="vendor/orchestra/testbench-core/laravel"
SQLITE="$SKELETON/database/database.sqlite"

echo "-- schone testbench-app voorbereiden (sqlite db)"
mkdir -p "$SKELETON/database"
rm -f "$SQLITE"
touch "$SQLITE"

echo "-- migrate (elk familiepakket levert zijn eigen migraties via loadMigrationsFrom)"
vendor/bin/testbench migrate --force --no-interaction

echo "-- compliance:install --skip-migrate (publiceert alle 11 family-tags voor het eerst;"
echo "   --skip-migrate omdat migrate hierboven al draaide — nogmaals migrate zou botsen"
echo "   met de zojuist gepubliceerde migratiekopieën, zie de docblock van InstallCommand)"
vendor/bin/testbench compliance:install --skip-migrate --force --no-interaction

echo "-- COMPLIANCE_LOG_SECRET zetten voor deze sessie (alleen voor het proefdraaien)"
export COMPLIANCE_LOG_SECRET="proefdraai-secret-niet-voor-productie"

echo "-- twee v1.x-achtige subject-access rijen in retention_log zetten,"
echo "   zoals het monolithische v1.x-pakket ze schreef (action ="
echo "   subject_access_exported, retention_field = subject_access)"
vendor/bin/testbench tinker --no-interaction --execute="
    \$secret = \Ginkelsoft\ComplianceCore\Config\LogSecret::value();
    foreach ([['proefpersoon-1', 'App\\\\Models\\\\Profile', 3, '2026-01-15 09:00:00'], ['proefpersoon-2', 'App\\\\Models\\\\Invoice', 5, '2026-02-20 11:30:00']] as [\$subjectId, \$modelType, \$count, \$performedAt]) {
        \$subjectHash = \Ginkelsoft\ComplianceCore\Support\SubjectHash::compute(\$subjectId, \$secret);
        \$previous = \Ginkelsoft\DataRetention\Models\RetentionLogEntry::query()->orderByDesc('id')->first();
        \$previousHash = \$previous ? \$previous->hash : '';
        \$payload = [
            'model_type' => \$modelType,
            'model_id' => \$subjectHash,
            'action' => 'subject_access_exported',
            'retention_period' => \$count.' records',
            'retention_field' => 'subject_access',
            'expired_at' => \$performedAt,
            'performed_at' => \$performedAt,
        ];
        \$hash = \Ginkelsoft\ComplianceCore\Support\HashChain::compute(\$payload, \$previousHash, \$secret);
        \Ginkelsoft\DataRetention\Models\RetentionLogEntry::query()->create(\$payload + ['previous_hash' => \$previousHash, 'hash' => \$hash]);
    }
    echo 'Voorbeeldrijen aangemaakt.'.PHP_EOL;
"

echo "-- compliance:migrate-v1-access-rows --dry-run (niets wordt geschreven)"
vendor/bin/testbench compliance:migrate-v1-access-rows --dry-run --no-interaction

echo "-- compliance:migrate-v1-access-rows (schrijft nu echt naar subject_access_log)"
vendor/bin/testbench compliance:migrate-v1-access-rows --no-interaction

echo "-- compliance:migrate-v1-access-rows nogmaals (bewijst idempotentie: 0 gemigreerd)"
vendor/bin/testbench compliance:migrate-v1-access-rows --no-interaction

echo "-- compliance:verify (retention_log EN subject_access_log moeten beide OK zijn)"
vendor/bin/testbench compliance:verify --no-interaction || true

echo "-- app draait nu op http://0.0.0.0:$PORT (Ctrl+C om te stoppen)"
exec vendor/bin/testbench serve --host=0.0.0.0 --port="$PORT"
