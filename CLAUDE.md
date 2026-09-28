# CLAUDE.md — SccIT Ticket System

Operating contract for Claude Code in this repository. Read it before acting.

---

## 1. Project identity

**SccIT** — a school IT service-management system: fault ticketing, equipment
register, locations, maintenance, and role dashboards for three roles
(**administrator**, **technician**, **teacher**).

| Layer | Stack |
|---|---|
| Backend | PHP 8.3 · Laravel 13 · Sanctum 4 (SPA cookie auth, single-origin) |
| Frontend | React 19 · TypeScript 6 · Vite 8 · Tailwind 4 · React Router 7 · TanStack Query 5 · Zod 4 |
| Data | PostgreSQL · Redis · Mailpit (dev mail sink) |
| Runtime | Docker Compose — dev and production stacks, both local |
| Test/QA | Pest 4 · PHPStan (Larastan, level 6) · Pint · Vitest · ESLint · Prettier |

### Architecture

- `backend/app/Domains/<Domain>/` — the unit of organisation. Domains present:
  `Analytics`, `Assets`, `FloorPlan`, `Identity`, `KnowledgeBase`, `Locations`,
  `Maintenance`, `Tickets`. Inside a domain: `Actions/`,
  `Http/{Controllers,Requests,Resources}/`, `Policies/`, `Services/`, plus
  `Console/`, `Events/`, `Exceptions/` as needed.
- **Eloquent models are flat** in `backend/app/Models/` — they are *not* inside
  domain folders. Enums are flat in `backend/app/Enums/`.
- Enum-backed columns are `varchar` + `CHECK` constraint, not native PG enums.
- Public record identifiers are **UUID route keys** (`{ticket:uuid}`), never
  auto-increment ids.
- `frontend/src/features/<feature>/` mirrors the backend domains:
  `api/`, `hooks/{queries,mutations}.ts`, `components/`, `pages/`, `schemas.ts`,
  `types.ts`.

### Phase numbering — two systems, do not conflate

The SPMP's work-package numbers are offset from the phase numbers used in
conversation and in code comments. Both are correct in their own document:

| Conversational phase | SPMP work package | Subject |
|---|---|---|
| Phase 2.4 | WP-2.2 | Locations + role dashboards |
| Phase 2.5 | WP-2.3 + WP-2.4 | Assets (PC units, catalog, lifecycle, QR) |
| Phase 2.6 | WP-2.5 | Ticket Management |

Route-file and code comments use the **conversational** numbering ("Phase 2.6").
When writing to the SPMP, use its own numbering and keep the offset noted.

---

## 2. Source-of-truth hierarchy

When two sources disagree, the higher one wins. **Always.**

1. **Current code and migrations** in this repository.
2. **Verified requirements/architecture documents** —
   `docs/Software Requirements Specification.md`,
   `docs/Software Design Description.md`,
   `docs/Software Project Management Plan.md`.
3. **Verified tests** — `backend/tests/`, `frontend/src/**/*.test.*`.
4. **Project memory and indexes** — Graphify, codebase-memory-mcp, claude-mem,
   auto-memory, and any note or summary.

The single exception: a **documented architectural decision** (an SRS `OI-*`, an
SDD `DD-*`, or an explicit client decision recorded in the docs) that
deliberately describes a target the code has not reached yet. Such a decision
governs *intent*; the code still governs *what is true today*. Say which one you
are describing.

### Never let a memory outrank the repository

Memory layers describe the repository **as it was when they were written**. They
go stale silently. Before relying on a remembered fact that names a file, class,
migration, route, permission, or flag: **verify it still exists in the repo.** If
an index or memory contradicts the code, the code is right and the index needs
refreshing — say so and refresh it rather than working around it.

---

## 3. Tooling

| Tool | Purpose here | Activation |
|---|---|---|
| **codebase-memory-mcp** | Code knowledge graph — what calls what, architecture, graph-aware search | Automatic (user-level MCP server) |
| **Graphify** | Fast structural orientation over a local graph of this repo | Automatic (`.mcp.json`, pre-approved in `.claude/settings.json`) |
| **Auto memory** | How the user works; approved decisions; project state across sessions | Automatic (loaded each session) |
| **Impeccable** | Frontend design/audit skill; design tokens in `.impeccable/design.json` | On demand — `Skill(impeccable)` |
| **claude-mem** | Cross-session narrative memory — the *why* behind past work | Automatic (plugin + Bun worker; see §3.1) |

Use the graph tools for orientation and impact analysis; use `Read`/`Grep` to
confirm anything you are about to change. **A graph answer is a hypothesis until
the file confirms it.**

### 3.1 Known tooling limitations

- **claude-mem history begins 2026-08-27, not earlier.** Its worker requires the
  Bun runtime (`bun:sqlite`); Bun was missing until then, so the worker had never
  started and **no observation was ever recorded** for Phases 2.1–2.6. The
  database now exists and captures forward from that date. Treat claude-mem as
  silent about everything before it: the *why* for earlier phases lives in git
  commit messages, `docs/`, and auto-memory. Never infer "it didn't happen" from
  an empty claude-mem result.
- If claude-mem tools start failing again, the worker is down. Restart with
  `bun plugin/scripts/worker-service.cjs restart` from
  `~/.claude/plugins/marketplaces/thedotmack`, then check
  `http://127.0.0.1:37777/health`. Its semantic search additionally needs
  `uv`/`uvx` (absent here) and silently falls back to keyword search.
- **LightRAG is not installed** in this project. Do not reference it as an
  available knowledge source.
- **The Obsidian vault (`vault/`) contains no authored notes** — it is an empty
  scaffold with plugins, and is gitignored. Canonical documentation is `docs/`.
- `graphify-out/graph.html` is not generated above ~5000 nodes; `graph.json` and
  `GRAPH_REPORT.md` still are.

### 3.2 Index refresh rules

Refresh when a stale answer would otherwise be wrong — after any change to
domain structure, routes, migrations, or the model layer; and always after a
phase lands.

```bash
graphify update . --force        # rebuilds graphify-out/graph.json
```

```text
mcp__codebase-memory-mcp__index_repository(repo_path=<abs repo path>, mode="full")
```

Use **`mode="full"`**. `moderate` and `fast` exclude
`backend/database/migrations` and `frontend/src/features/assets` — precisely the
material that must be indexed.

Both indexes are machine-local and regenerable, and both are gitignored.
Re-index rather than reasoning from a stale graph. If indexing fails at the
`dump` phase, an orphaned `codebase-memory-mcp` process is usually holding the
SQLite write lock — check for one before assuming corruption.

**Staleness is detected automatically, but refresh is manual by design.**
`scripts/claude-index-freshness.sh` runs on every SessionStart (wired in
`.claude/settings.json`) and injects a warning when either index has fallen
behind — Graphify by comparing `GRAPH_REPORT.md`'s build commit to `HEAD`,
codebase-memory by comparing its DB mtime to the HEAD commit time and to any
uncommitted source file. It costs ~0.3s and prints nothing when both are fresh.

It deliberately **does not rebuild**: a rebuild takes 1–2 minutes and must not be
attached to session startup. If the warning appears, run the refresh command it
gives you before trusting any graph answer.

### 3.3 Health check

```bash
claude mcp list                                    # all three servers -> Connected
curl -sS http://127.0.0.1:37777/api/stats          # claude-mem worker + observation count
bash scripts/claude-index-freshness.sh             # silence == both indexes current
grep -m1 'Built from commit' graphify-out/GRAPH_REPORT.md
```

Keep Graphify registered **only** in `.mcp.json`. A duplicate local-scope entry
in `~/.claude.json` silently shadows it (local scope wins) and reintroduces the
absolute-path, drive-letter-cased registration that failed before. `claude mcp
list` reports this as a "Conflicting scopes" diagnostic — if it appears, run
`claude mcp remove graphify -s local`.

---

## 4. Git safety

- **`recovery/phase-2.4-2.6-baseline` @ `c9ab8a4` is immutable.** It is the
  protected snapshot of ~2 months of work that existed only in the working tree.
  Never modify, rewrite, reset, rebase, force-push or delete it. It is also on
  `origin`; keep it that way.
- Work on a branch **based on that baseline**. Never commit directly to `main`,
  and do not merge the recovery branch into `main` — it is a safety checkpoint,
  not phase history.
- Commit or push **only when asked**.
- Never `--no-verify`, never skip signing, and never run `migrate:fresh` against
  a stack holding data the user has not agreed to lose.
- Before any destructive git or Docker operation, look at what it will destroy
  and say so first.

---

## 5. Development vs production parity

Two Compose stacks run locally and **both must be kept in step**.

| | Dev | Production |
|---|---|---|
| File | `compose.yaml` | `compose.prod.yaml` |
| Project name | default | **`-p sccit_prod` (required)** |
| App | `http://localhost:8080` | `http://localhost:8081` |
| Mailpit | `:8025` | `:8026` |
| Code | bind-mounted, live | **baked into the image** |

```bash
# dev
docker compose exec -T app <cmd>
# production
docker compose -p sccit_prod -f compose.prod.yaml exec -T app_prod <cmd>
```

**Omitting `-p sccit_prod` silently targets the wrong stack.** This has bitten
this project before; it is not optional.

**Parity rule:** production bakes code plus config/route/event caches into the
image, so *source changes do not reach production until the image is rebuilt*. A
phase is not verified until the production image has been rebuilt and the same
behaviour confirmed on `:8081`. Check parity by comparing migration count and
domain contents inside the prod container against the repo — never by assuming.

**Dependency changes in dev go through `scripts/dep-update.sh`, never a bare
`composer`/`npm` command.** `backend/` is bind-mounted, so a live `composer
update` rewrites real class-definition source under the *running* app/queue/
scheduler/reverb containers. Dev's OPcache revalidates every request with
tracing JIT on, and the compiled-code cache and JIT buffer are shared memory
across the whole worker pool — a worker executing JIT-native code from the old
definition can run against memory another worker's invalidation reallocates
mid-request once the new class differs structurally. This caused 592 SIGSEGV
php-fpm crashes during OD-1 (2026-09-28), invisible to Pest/Pint/PHPStan
(`opcache.enable_cli=0`) and only visible on the served stack. Confirmed by
controlled reproduction: `composer dump-autoload` under 600 concurrent requests
(a real vendor/ rewrite, but data only, not code) — zero crashes. **Not a
production risk by architecture**: prod installs during `docker build` into an
immutable layer, never into a running container, and its
`opcache.validate_timestamps=0` means a live prod worker never revalidates at
all. `scripts/dep-update.sh` runs the command, restarts every affected dev
service, and verifies 0 crashes under load before returning — do not disable
JIT or hand-restart instead of using it.

---

## 6. Testing and verification

A change is done only when the full gate passes. Run in Docker, not on the host.

```bash
# Backend
docker compose exec -T app ./vendor/bin/pest
docker compose exec -T app ./vendor/bin/pint --test
docker compose exec -T app ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G

# Frontend
docker compose exec -T node sh -lc "cd /app && npx tsc -b"
docker compose exec -T node sh -lc "cd /app && npm run lint"
docker compose exec -T node sh -lc "cd /app && npm run format:check"
docker compose exec -T node sh -lc "cd /app && npm run test"
docker compose exec -T node sh -lc "cd /app && npm run build"

# Migrations — check both stacks
docker compose exec -T app php artisan migrate:status
```

Requirements:

- **Authorization gets a dedicated test file per module**, asserting the negative
  cases (403/404) role by role — not just the happy path.
- For row-scoped modules, assert that a record absent from a user's list is
  **equally unreachable by direct UUID**. A list filter without the matching
  single-record check is an IDOR.
- Assert redacted projections against the **encoded payload**, so a field cannot
  leak back in through a later resource change.
- Report results honestly: paste real counts and real failures. Never describe a
  gate as passing that you did not run.

---

## 7. Documentation synchronisation

- Canonical Markdown lives in `docs/`; Word deliverables are generated into
  `deliverables/word/` by the `docs/build_*.py` pipeline. **Never hand-edit the
  `.docx` files.**
- **The SRS/SDD/SPMP are frozen at Version 1.0 by client instruction.** Content
  is amended in place; the version label and date are *not* bumped. Record what
  changed in the document's revision-history table.
- Every phase closes with a documentation reconciliation: new `DD-*` decisions
  written down, affected `FR-*` amended, the SPMP work package marked, and the
  §8.4 permission matrix updated when a role's permissions change.
- SDD §14.1 tracks implemented-vs-planned. Only mark a domain *implemented* when
  it is in the repo and verified — the phase tag must reflect reality.

---

## 8. Working style

- **Verify, don't assume.** Check the file, run the command, read the output.
- Respect approval gates: present findings and a recommendation, then wait. Do
  not roll a fresh audit straight into implementation.
- Preserve existing work. Do not rebuild, reset or "clean up" Phase 2.4/2.5/2.6
  code as a side effect of another task.
- Match the surrounding code's idiom and comment density. This codebase carries
  substantial explanatory comments on authorization and lifecycle decisions —
  keep that standard; explain *why*, not *what*.
- When you find a real problem outside the current scope, report it. Do not
  silently fix it, and do not silently leave it.
