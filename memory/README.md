# memory/ — cross-agent memory & context

This directory makes CareOS self-describing so any agent boots into full project awareness and
every task leaves a durable record. It is the index into the repo — **the repo is the truth**;
memory points into it. See [`../AGENTS.md`](../AGENTS.md) for the authoritative brief.

## Layout

- `README.md` — this file.
- `LOG.md` — **append-only** run log; one line per completed gate (newest at bottom).
- `modules/<Module>.md` — one file per module: purpose, key tables, key classes, invariants,
  status, open items.

## Protocol (also in AGENTS.md)

**BEFORE a task** — read `AGENTS.md` → `PROJECT-STATE.md` → `DECISIONS.md`/`DEFERRED.md` →
the relevant `memory/modules/<Module>.md`.

**AFTER a task** —
1. Append one line to `LOG.md` (commit hash + one-line summary + test count where known).
2. Update the touched `modules/*.md`.
3. Update `PROJECT-STATE.md` (phase, gates done, next action).
4. Log new decisions in `DECISIONS.md`.

## Conventions

- Keep entries **short and factual**. Prefer file/class names over prose.
- `LOG.md` is **append-only** — never rewrite past lines; correct via a new line.
- Module files are living documents — update in place to reflect current reality.
