# Ginkelsoft Laravel Compliance Hub

[![Tests](https://github.com/ginkelsoft-development/laravel-compliance-hub/actions/workflows/tests.yml/badge.svg?branch=development)](https://github.com/ginkelsoft-development/laravel-compliance-hub/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-MIT-green.svg?style=flat-square)](LICENSE)
[![Laravel](https://img.shields.io/badge/Laravel-10--13-brightgreen?style=flat-square&logo=laravel)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2%20--%208.5-blue?style=flat-square&logo=php)](https://php.net)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen?style=flat-square)](phpstan.neon.dist)

## Overview

Umbrella for the GinkelSoft GDPR / AVG compliance family. Installing this
single package pulls in every member of the family and adds two Artisan
commands that operate across all five audit-log chains in one shot:

- **`compliance:verify`** — runs `HashChain::verify()` on every audit-log
  table in the family. Exits **non-zero** when at least one chain is
  tampered or otherwise unverifiable. Perfect for a scheduled job that
  pages whoever is on call.
- **`compliance:report`** — bundles row counts and verify status per
  chain into a single Markdown or JSON report. **No personal data** —
  only counts and status, safe to drop into a board pack.
- **`compliance:migrate-v1-access-rows`** — optional cleanup for
  installations upgrading from the monolithic v1.x package: copies old
  `subject_access_exported` rows out of `retention_log` into the
  purpose-built `subject_access_log` table. Idempotent, transaction-safe,
  and `--dry-run`-able. See below.

This package is the **art. 5(2) accountability** evidence-generator for
the family. It owns no migrations and no models of its own; everything
it does is built on top of `Ginkelsoft\ComplianceCore\Support\HashChain`
and the per-control packages' audit tables.

## The family

Installing this package installs the whole family.

| Package | GDPR Article(s) | Role |
| ------- | --------------- | ---- |
| [`laravel-compliance-core`](https://github.com/ginkelsoft-development/laravel-compliance-core) | art. 5(2) | Shared primitives |
| [`laravel-data-retention`](https://github.com/ginkelsoft-development/laravel-data-retention) | art. 5(1)(e) | Storage limitation |
| [`laravel-data-right-to-be-forgotten`](https://github.com/ginkelsoft-development/laravel-data-right-to-be-forgotten) | art. 17 | Subject-driven erasure |
| [`laravel-data-subject-access`](https://github.com/ginkelsoft-development/laravel-data-subject-access) | art. 15 + 20 | Subject access |
| [`laravel-data-consent`](https://github.com/ginkelsoft-development/laravel-data-consent) | art. 6(1)(a) + 7 | Consent registry |
| [`laravel-data-breach-registry`](https://github.com/ginkelsoft-development/laravel-data-breach-registry) | art. 33 + 34 | Breach registry |
| **`laravel-compliance-hub`** | **art. 5(2)** | **Umbrella + verify/report — this package** |

## Installation

```bash
composer require ginkelsoft/laravel-compliance-hub
```

Composer pulls in core + the five functional packages automatically. Then
publish each one's config and migrations:

```bash
php artisan vendor:publish --tag=compliance-config
php artisan vendor:publish --tag=data-retention-config
php artisan vendor:publish --tag=data-retention-migrations
php artisan vendor:publish --tag=forget-config
php artisan vendor:publish --tag=forget-migrations
php artisan vendor:publish --tag=subject-access-config
php artisan vendor:publish --tag=subject-access-migrations
php artisan vendor:publish --tag=consent-config
php artisan vendor:publish --tag=consent-migrations
php artisan vendor:publish --tag=breach-config
php artisan vendor:publish --tag=breach-migrations
php artisan migrate
```

Then add a single shared signing secret to `.env`:

```env
COMPLIANCE_LOG_SECRET="$(openssl rand -base64 32)"
```

This one secret signs every chain — retention, forget, subject access,
consent, breach. Rotate it only as part of an explicit, documented audit
rotation procedure.

## Usage

### Verify every chain in one shot

```bash
php artisan compliance:verify
```

Output:

```
+--------------------------------------------+-----------------------+------+--------+
| Control                                    | Table                 | Rows | Status |
+--------------------------------------------+-----------------------+------+--------+
| Storage limitation (retention_log)         | retention_log         | 1234 | OK     |
| Right to be forgotten (forget_log)         | forget_log            |   42 | OK     |
| Subject access (subject_access_log)        | subject_access_log    |    7 | OK     |
| Consent (consent_log)                      | consent_log           |  890 | OK     |
| Breach event log (breach_event_log)        | breach_event_log      |   12 | OK     |
+--------------------------------------------+-----------------------+------+--------+
All present audit-log chains verified.
```

Schedule it:

```php
// app/Console/Kernel.php
$schedule->command('compliance:verify')->dailyAt('03:00')
    ->onFailure(fn () => notifyTeam('Compliance chain broken — investigate.'));
```

Exit code is `0` when every present chain verifies, `1` when at least
one chain fails. Tables that are not present in the schema (a family
package whose migration has not been run) are reported as `— (table
missing)` and do **not** count as a failure — so a partial install does
not page anyone.

### Bundled report

```bash
php artisan compliance:report                                # Markdown to STDOUT
php artisan compliance:report --format=json                  # JSON to STDOUT
php artisan compliance:report --output=storage/reports/2026-05.md
php artisan compliance:report --format=json --output=storage/reports/2026-05.json
```

The report contains row counts and verify status per chain, plus a
"generated_at" timestamp. **No personal data.** Suitable for handing to
the DPO every month or pasting into a board pack as evidence of art. 5(2)
accountability.

### Migrate v1.x subject-access rows out of `retention_log`

In the monolithic v1.x package, subject-access exports (GDPR art. 15)
were logged into the shared `retention_log` table (`action =
subject_access_exported`, `retention_field = subject_access`). v2.x
gives subject access its own `subject_access_log` table instead. **You
do not have to migrate anything** — the upstream UPGRADE.md is explicit
that old rows staying in `retention_log` is safe and its hash chain
keeps verifying either way. If you want a clean split anyway (e.g.
before pointing a reporting tool only at `subject_access_log`), run:

```bash
php artisan compliance:migrate-v1-access-rows --dry-run   # preview, writes nothing
php artisan compliance:migrate-v1-access-rows              # write the rows
php artisan compliance:migrate-v1-access-rows -v            # verbose per-row table
php artisan compliance:migrate-v1-access-rows --format=json # tag migrated rows' format column
```

What it does and does not do:

- **Reads** `retention_log` rows matching the v1.x subject-access
  signature, and **writes** the equivalent row to `subject_access_log`,
  appended to that table's own hash chain (a freshly computed chain for
  the moved rows — not a replay of their original `retention_log`
  hashes, which belong to a different chain with a different payload
  shape).
- **Never updates or deletes anything in `retention_log`.** That table
  is append-only by design — mutating or removing a row would
  invalidate every hash chained after it. The original rows stay
  exactly where they are and their chain keeps verifying unchanged.
- **Idempotent.** Every candidate row is matched against
  `subject_access_log` on `subject_hash` + `model_type` +
  `record_count` + `performed_at` before writing; rows already present
  are skipped. Running the command any number of times never creates
  duplicates.
- **Transaction-safe.** Each row is written inside its own
  `DB::transaction()`, which locks the tail of `subject_access_log` for
  update and re-checks for a concurrent duplicate before appending — so
  it can safely run alongside live `retention:export` traffic.
- Rows it cannot interpret (a `model_id` that is not a 64-char subject
  hash, or a `retention_period` that does not match the v1.x `"<n>
  records"` convention) are skipped and reported with a reason; they
  never abort the run.
- v1.x never recorded an export `format`; migrated rows get
  `--format`'s value (default `legacy`) so they are easy to tell apart
  from native v2.x exports later.

The hub still owns no migrations or tables of its own — this command
only reads `retention_log` (owned by `laravel-data-retention`) and
writes through `SubjectAccessLogEntry` (owned by
`laravel-data-subject-access`).

## What the hub does NOT do

- `compliance:verify` and `compliance:report` never write anything —
  they only **read** the audit logs to verify hashes. The one exception
  is the opt-in `compliance:migrate-v1-access-rows`, which writes new
  rows to `subject_access_log` and never touches `retention_log` (see
  above).
- It does not own its own tables. Every chain it verifies (or, for the
  migration command, writes to) is the responsibility of one of the
  family packages.
- It does not enforce a UI. Verify + report are CLI-first; build your own
  Filament / Nova / Livewire layer on top if you want a dashboard.

## Compliance notes

* **GDPR art. 5(2)** — Accountability. The bundled report + scheduled
  verification together are the operational realization of this principle:
  evidence that every control is in place and every audit trail is
  internally consistent.

This package is **not legal advice**. The judgements that produce the
data this package verifies — retention periods, severity assessments,
DPIA decisions — belong to your DPO.

## Testing

```bash
composer install
vendor/bin/pest
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pint --test
```

## Reporting bugs

Found a bug or unexpected behaviour? We want to hear about it.

**Preferred — open a GitHub issue:**
[https://github.com/ginkelsoft-development/laravel-compliance-hub/issues/new](https://github.com/ginkelsoft-development/laravel-compliance-hub/issues/new)

When opening an issue, please include:

1. **Versions** — PHP, Laravel, and `ginkelsoft/laravel-compliance-hub`
   (`composer show ginkelsoft/laravel-compliance-hub`).
2. **What you did** — the artisan command, code snippet, or steps that
   triggered the bug.
3. **What you expected** vs **what actually happened** — include full
   error output or a stack trace if there is one.
4. **A minimal reproduction** if you can — a failing test or a small
   code sample beats a long description.

**Security-sensitive findings** (anything that could expose personal
data, break a hash-chain, or bypass an audit log) — please **do not**
open a public issue. E-mail **info@ginkelsoft.com** directly with
"SECURITY" in the subject line and we will respond privately.

**Not on GitHub?** You can also e-mail **info@ginkelsoft.com** with the
same information.

## Contact

For commercial support, integration questions, or anything that doesn't
fit a GitHub issue: **info@ginkelsoft.com** —
[https://ginkelsoft.com](https://ginkelsoft.com).

## License

MIT License — see [LICENSE](LICENSE).
(c) 2026 Ginkelsoft
