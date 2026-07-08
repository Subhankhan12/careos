# codex.md — Codex entry point

**Read [`AGENTS.md`](AGENTS.md) first — it is the single source of truth** for CareOS (project,
stack, hard rules, workflow, module map). Then follow the **MEMORY PROTOCOL** defined there:
before a task read `PROJECT-STATE.md` → `DECISIONS.md`/`DEFERRED.md` → the relevant
`memory/modules/<Module>.md`; after a task update `memory/LOG.md`, the touched module file,
`PROJECT-STATE.md`, and `DECISIONS.md`.

Rule text lives ONLY in `AGENTS.md` so it can never drift. Do not duplicate it here.

## Environment notes

- Windows + PowerShell; run commands one per line (no `&&` chaining).
- PHP CLI is `C:\xampp\php\php.exe`; Composer runs via `C:\xampp\php\php.exe C:\xampp\php\composer`.
- `composer check` = Pint (lint) + PHPStan L5 (analyse) + Pest (test). Must be green before commit.
- Execute only the pasted gate, end with the specified GATE REPORT + exactly one commit, then STOP.
