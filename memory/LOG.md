# LOG.md — append-only run log

One line per completed gate. Newest at bottom. Format: `<commit> P<phase>.G<n>: summary — notes`.

- `717681d` P0.G2: Laravel 12 skeleton — dev DB XAMPP MariaDB 3306 (database `careos`).
- `71089f6` P0.G3: quality rails — Pest, PHPStan L5, Pint; CI on MySQL 8.
- `dc86617` P0.G4: module skeleton (Platform/Audit/AiCore) + architecture guard (Pest arch test).
- `664cf25` P0.G5: repo governance — CLAUDE.md, DECISIONS.md, PROJECT-STATE.md, DEFERRED.md.
- `a477c6a` P0.C: consolidation — Phase 0 complete; 5 tests / 14 assertions.
- `88597f8` P0A.G1: fail-closed multi-tenancy — TenantContext + BelongsToTenant + isolation suite (5 isolation tests).
- `bde40bd` P0A.G2: auth (Fortify) + mandatory TOTP MFA + tenant identification; User → Modules\Platform.
- `776dcac` P0A.G3: org hierarchy — branches + departments, tenant-scoped, cross-tenant FK guard.
- `25af062` P0A.G4: RBAC — roles/permissions/branch-scoped assignments + Gate::before (super-admin sole bypass).
- `dc50bd9` P0A.G5: plans (integer minor units) + feature flags + typed settings; flag resolution order.
- `7672b09` P0A.G6: append-only hash-chained partitioned `audit_events` + AuditService (verifyChain, DB triggers).
- `cac62d7` P0A.G7: audit integration (auth/RBAC/config events) + read-logging (LogsReads) + break-glass.
- `5e6296a` P0A.G8: Inertia+Vue3+TS+Tailwind v4 shell — login, 2FA challenge/enroll, role redirect; 75 tests / 202 assertions.
- `01f262b` P0A.GM: cross-agent memory & context system — AGENTS.md + codex.md thin pointers + memory/ (docs only).
- `0cd0f5f` P0A.GF: CI fix — build frontend (setup-node 20 + npm ci + npm run build) before tests, so the Vite manifest exists; ci.yml only. Local 75 passed / 202 assertions.
- `6ae661c` P0A.GF3: CI fix — Inertia page dir renamed `resources/js/Pages` → `resources/js/pages` (git mv) to match inertia-laravel's `pages.paths` (`js/pages`), fixing case-sensitive Linux `ensure_pages_exist`; app.ts glob → `./pages`; setup-node bumped 20→22. Local 75 passed / 202 assertions. CI #15 GREEN on MySQL 8.
- `(P0A.C)` Phase A COMPLETE — Platform core + Audit + app shell. 16 commits; Pest 75/202, PHPStan L5 clean, Pint clean, npm build green, CI green on MySQL 8. Proven: fail-closed tenancy, mandatory MFA, RBAC+branch scope, hash-chained append-only audit + triggers, break-glass, Inertia shell, cross-agent memory. Next: Phase B — People & Patients.
- `(pending)` P0B.G1: People module - staff profiles + credential vault; 82 tests / 241 assertions.
