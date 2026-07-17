# DB-PARITY — MariaDB 10.4 (dev) vs MySQL 8 (prod + CI)

Verified in **P0P.G15**. Development runs XAMPP **MariaDB 10.4.32** (`careos`, 3306);
CI and the production target run **MySQL 8**. Every migration and the full Pest
suite must behave identically on both. This file lists every known divergence, how
each is handled, and the one-step re-verification commands.

## How to re-verify parity

- **CI (authoritative):** every push to `main` runs `.github/workflows/ci.yml`
  against a **clean `mysql:8` service**: full migration set from scratch
  (`php artisan migrate --force`), an explicit **zero-pending assertion**
  (`migrate:status` must show no `Pending`), then `composer check`
  (Pint + PHPStan + the entire Pest suite — Evals, parallel hammers,
  airplane-mode, simulated-month reconciliation, security/immutability sweeps).
- **Manually against any MySQL 8:** point `.env` at a **THROWAWAY** MySQL 8
  database (the first step is `migrate:fresh` — it drops every table), then:
  `composer test:mysql`.
- **Dev engine (MariaDB):** `composer check` (the normal path).

## Engine divergences and how each is handled

1. **Implicit `ON UPDATE CURRENT_TIMESTAMP` (the big one).** With MariaDB 10.4's
   default `explicit_defaults_for_timestamp=OFF`, the FIRST non-nullable
   `TIMESTAMP` column of a table silently receives
   `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`; MySQL 8 (default ON)
   does not. On an UPDATE-able table that rewrites a recorded moment on the dev
   engine only. **Rule: mutable moment columns are `DATETIME`** (first applied in
   Comms, D-phase). P0P.G15 found and fixed three remaining reachable cases:
   `patient_consents.granted_at` (was rewritten by consent WITHDRAWAL on MariaDB),
   `portal_login_tokens.expires_at` (rewritten by token consumption), and
   `thread_reads.read_at` (masked but a trap) — all now `DATETIME`. The six
   append-only ledgers (`ai_interactions`, `integrity_checks`, `messages`,
   `payment_allocations`, `reconciliation_runs`, `refunds`) keep non-nullable
   `TIMESTAMP` safely: their UPDATE is blocked by DB triggers, so the implicit can
   never fire. Locked by `tests/Feature/Platform/MutableMomentParityTest.php`,
   whose schema-guard test scans `information_schema` on WHATEVER engine it runs
   on and fails if any non-append-only table carries an `on update` column.
2. **FULLTEXT ngram parser.** MySQL 8 ships the `ngram` parser; MariaDB does not.
   The patients migration tries `WITH PARSER ngram` and falls back to a plain
   FULLTEXT index when the engine rejects it
   (`Modules/Patients/.../create_patients_table.php`). FULLTEXT is supporting
   evidence only in duplicate detection (deterministic scoring dominates), so the
   parser difference cannot change outcomes.
3. **Spatial columns / SPATIAL index / geofence.** MariaDB requires spatially
   indexed columns to be NOT NULL: `visit_events.location` stays the nullable
   business value while the non-null `location_index` mirror carries the SPATIAL
   index. `ST_Distance_Sphere(ST_GeomFromText(…, 4326))` runs on both engines
   (MariaDB ≥ 10.4.19 has ST_Distance_Sphere; dev is 10.4.32) — proven by the
   geofence suite passing on both.
4. **CHECK constraints.** Five raw CHECKs (charges source-XOR, payments/refunds
   positive amounts, allocations non-zero, thread-participants party-XOR). Both
   MariaDB 10.4 and MySQL 8.0.16+ ENFORCE CHECK constraints with identical
   syntax; the P.3 suite asserts the violations throw on both.
5. **Triggers (append-only / immutability).** All guards use
   `SIGNAL SQLSTATE '45000'` — identical on both engines. The P.3
   `ImmutabilitySweepTest` exercises every protected table (raw UPDATE/DELETE
   rejected on all 15; conditional triggers' positive side still allows draft
   edits) and runs inside the full suite on BOTH engines.
6. **Partitioned audit_events.** `PARTITION BY RANGE` monthly partitioning —
   supported identically on both; migrations build it from scratch in CI.
7. **Generated columns / enum columns.** None exist (swept: no
   `storedAs`/`virtualAs`, no `->enum(`), so the engines' differences there are
   moot.
8. **Charset/collation.** Pinned explicitly in `config/database.php`:
   `utf8mb4` / `utf8mb4_unicode_ci` — both engines support it, so string
   comparison and uniqueness semantics are identical (never the MySQL-8-only
   `utf8mb4_0900_ai_ci` default).
9. **sql_mode.** `'strict' => true` makes Laravel set the SAME explicit sql_mode
   list (including `ONLY_FULL_GROUP_BY`, `STRICT_TRANS_TABLES`) on every
   connection for both engines, so grouping/strictness behave identically
   regardless of engine defaults.
10. **CI-env (not engine) divergence worth knowing:** the CI job exports
    `CACHE_STORE=redis` / `QUEUE_CONNECTION=redis` at the OS level, and phpunit's
    `<env>` CANNOT override OS env vars. Consequence: tests share a REAL Redis
    across the run. Two past incidents: the P0G.G2 queue-idempotency test (fixed
    by pinning `queue.default=sync` in that test) and the P0P.G7 kiosk
    rate-limiter (throttle counters persisted across tests → 429s only in CI;
    fixed in P0P.G15 by flushing the cache store per test in `CheckInTest` — a
    config pin is NOT enough because Fortify resolves the RateLimiter singleton
    at boot). If a CI-only failure ever appears again, check this class first.

## Status

Full parity verified at P0P.G15: fresh MySQL 8 migrate = zero errors / zero
pending; full suite green on both engines with identical counts (see the CI run
for the P0P.G15 commit and memory/LOG.md for the exact numbers).
