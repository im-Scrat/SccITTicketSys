# Production Developer File Map

Where to edit what in SccIT, how the layers connect, and how a change reaches
`http://localhost:8081`. Written for working by hand in an editor such as
Cursor.

> **Scope.** Since 2026-10-10 the **production stack** ([compose.prod.yaml](../compose.prod.yaml),
> `-p sccit_prod`, `:8081`) is the only stack; the development stack was
> retired. Every path below was checked against the repository on 2026-10-10
> (commit `0e707ef` plus the working tree), and every link re-checked on
> 2026-10-11 (commit `ae616af`). Paths shown as text are relative to the
> repository root; link targets are relative to this file — see
> [How the links work](#how-the-links-work).

---

## Quick navigation

The files opened most often. Each link opens the file in your editor.

| For | Open |
|---|---|
| Frontend routes — every URL, its page and its guard | [App.tsx](../frontend/src/App.tsx) |
| Role-based navigation — the menu each role sees | [navConfig.ts](../frontend/src/layouts/navConfig.ts) |
| Ticket reporting — the fault-report form | [CreateTicketPage.tsx](../frontend/src/features/tickets/pages/CreateTicketPage.tsx) |
| Ticket backend — report, my tickets, detail, edit, status change, "not fixed" | [TicketController.php](../backend/app/Domains/Tickets/Http/Controllers/TicketController.php) |
| AI assistant — one question in, one grounded answer out, with citations | [AssistantService.php](../backend/app/Domains/KnowledgeBase/Services/AssistantService.php) |
| AI assistant instructions — `instructions()` | [AssistantAgent.php](../backend/app/Domains/KnowledgeBase/Agents/AssistantAgent.php) |
| AI data safety — personal data and credentials stripped before text leaves for Gemini | [PromptRedactor.php](../backend/app/Domains/KnowledgeBase/Services/PromptRedactor.php) |
| API routes — every endpoint and its `can:` permission gate | [api.php](../backend/routes/api.php) |
| Database migrations — one new file per schema change (§4) | `backend/database/migrations/` (a folder — see below) |
| Frontend tests — Vitest, next to the code as `*.test.ts` / `*.test.tsx` | setup [setup.ts](../frontend/src/test/setup.ts), config [vite.config.ts](../frontend/vite.config.ts) |
| Backend tests — Pest, `backend/tests/Feature/<Domain>/` and `backend/tests/Unit/` | harness [Pest.php](../backend/tests/Pest.php), [TestCase.php](../backend/tests/TestCase.php); how to run them: [TESTING.md](TESTING.md) |
| Live UI preview — `npm run dev:live` in `frontend/`, then `http://localhost:5173` (§1) | [vite.config.ts](../frontend/vite.config.ts) |
| Production deployment — run as `sh scripts/deploy-prod.sh` (§1) | [deploy-prod.sh](../scripts/deploy-prod.sh) |
| Operations documentation — deploy, backups and restore, rollback, verification | [OPERATIONS.md](OPERATIONS.md) |

### How the links work

Every link is a standard relative Markdown link, resolved from this file's
folder (`docs/`) — hence the leading `../`. None names a drive, a user folder
or an absolute path, so the links work in any clone: in Cursor, in VS Code and
on GitHub.

- **Click in the preview.** Open the Command Palette (`Ctrl+Shift+P`;
  `Cmd+Shift+P` on macOS), run *Markdown: Open Preview to the Side*, then click
  a link — the file opens in an editor tab. In VS Code, `Ctrl+Shift+V` opens
  the preview in place.
- **Or Ctrl+click in the source.** In the Markdown source, hold `Ctrl` (`Cmd`
  on macOS) and click a link.
- **If a link does nothing,** check that the `editor.links` setting is on and
  that the built-in *Markdown Language Features* extension is enabled. As a
  fallback, drop the leading `../` from the link target — the rest is the path
  from the repository root — and paste it into Quick Open (`Ctrl+P`). Quick
  Open needs the repository root open as the folder (File → Open Folder…), as
  §5 recommends.
- **Not every path is a link.** An editor opens files, not folders, so a
  folder is a link only where it has an index file (the UI kit's `index.ts`);
  paste a folder path into Quick Open to list its files, or find it in the
  Explorer. Secrets, local data and generated output (§0) are deliberately
  plain text: they are gitignored, so a link would be dead in a fresh clone,
  and one click should not pull a secret into an editor's AI context.
- **If a link reports a missing file,** the file moved or was renamed — find it
  with Quick Open and update the link here. VS Code's `markdown.validate.enabled`
  setting flags broken links while you edit this file.

---

## 0. What you can edit — and what you must not

| Kind | Paths | Rule |
|---|---|---|
| **Backend source** | `backend/app/`, `backend/routes/`, `backend/database/`, `backend/config/`, `backend/tests/`, [backend/bootstrap/app.php](../backend/bootstrap/app.php) | Edit freely; ships on the next deploy |
| **Frontend source** | `frontend/src/`, [frontend/index.html](../frontend/index.html), `frontend/public/` | Edit freely; ships on the next deploy |
| **Build / tool config** | [frontend/vite.config.ts](../frontend/vite.config.ts), [frontend/tsconfig.app.json](../frontend/tsconfig.app.json), [frontend/eslint.config.js](../frontend/eslint.config.js), [frontend/.prettierrc.json](../frontend/.prettierrc.json), [frontend/package.json](../frontend/package.json), [backend/composer.json](../backend/composer.json), [backend/phpstan.neon](../backend/phpstan.neon), [backend/pint.json](../backend/pint.json), [backend/phpunit.xml](../backend/phpunit.xml) | Edit with care; dependency changes also need the lock files (§5) |
| **Infrastructure** | [compose.prod.yaml](../compose.prod.yaml), `docker/`, `scripts/`, [.github/workflows/ci.yml](../.github/workflows/ci.yml), [Makefile](../Makefile) | Changes affect builds and deploys |
| **Docs** | `docs/`, [README.md](../README.md), [CLAUDE.md](../CLAUDE.md) | SRS/SDD/SPMP are frozen at v1.0 — see [CLAUDE.md](../CLAUDE.md) §7 |
| **Generated — do not edit** | `frontend/dist/`, `backend/bootstrap/cache/`, `backend/storage/`, `graphify-out/`, `deliverables/word/`, `docs/_publish/`, `frontend/test-results/`, `frontend/tmp-ally/` | Rebuilt by tools; `frontend/dist/` is a local build, production builds its own inside the image |
| **Dependencies — do not edit** | `backend/vendor/`, `frontend/node_modules/` | Host copies for the editor and the live UI preview only; production installs its own. `frontend/node_modules/` starts empty — fill it once with `npm ci` (§1) |
| **Local data — never commit** | `backups/` | Real rows (password hashes) and uploads — gitignored |
| **Deployment records — commit deliberately** | `releases/` | Not gitignored: [releases/current](../releases/current) and [releases/2df17db/manifest.json](../releases/2df17db/manifest.json) are tracked, later manifests are not — decide case by case ([docs/OPERATIONS.md](OPERATIONS.md) §3) |
| **Secrets — never commit or paste** | `.env`, `backend/.env.production`, `secrets/gemini_api_key.txt` | Gitignored; templates: [.env.example](../.env.example), [backend/.env.production.example](../backend/.env.production.example) |
| **Retired** | [compose.yaml](../compose.yaml), `docker/node/`, [docker/nginx/default.conf](../docker/nginx/default.conf), [docker/nginx/security-headers.dev.conf](../docker/nginx/security-headers.dev.conf), `backend/.env` | Dev stack only; kept for reference and CI's opt-in browser job |

**Inside the containers, not on disk:** `sccit_prod_app` runs a *copy* of
`backend/` baked into the image at `/var/www/html`; `sccit_prod_nginx` serves a
*built* SPA from `/usr/share/nginx/html`; config/route/event caches are built
when the container starts ([docker/php/prod-entrypoint.sh](../docker/php/prod-entrypoint.sh)); the database lives
in `sccit_prod_pgdata`, public files in the `sccit_prod_storage` volume
(`/var/www/html/storage/app/public`) and private attachments in the
`sccit_prod_private` volume (`/var/www/html/storage/app/private`). Editing a
file inside a container is lost on the next deploy — always edit the source.

> **Private attachments.** Ticket attachments, repair photos, work-support and
> asset files are stored on the `local` disk (e.g.
> [backend/app/Domains/Tickets/Actions/AttachTicketFile.php](../backend/app/Domains/Tickets/Actions/AttachTicketFile.php)), which since
> 2026-10-11 is the `sccit_prod_private` volume — mounted on `app_prod`,
> `queue_prod`, `queue_ai_prod`, `queue_bulk_prod` and `scheduler_prod`, never on `nginx_prod`, so
> the files are reachable only through the authorizing controllers. They
> survive deploys and rollbacks and are in every snapshot (`private.tar.gz`).
> Before that date the disk was each container's own layer; no file had been
> uploaded, so nothing was lost.

---

## 1. How a change reaches `http://localhost:8081`

There is no hot reload on `:8081`. Production is rebuilt from your working tree.

### Live UI preview (frontend only)

To see frontend edits as you save them, run Vite on this PC against
production — no second stack:

```bash
cd frontend
npm ci              # once: installs exactly package-lock.json; never edits it
npm run dev:live    # then open http://localhost:5173
```

Vite serves `frontend/src/` with hot reload and forwards `/api`, `/sanctum`,
`/broadcasting`, `/storage`, `/up` and the Reverb socket to `:8081`
([frontend/vite.config.ts](../frontend/vite.config.ts), `live` mode). Sign in
as usual. Two limits:

- **It is production data.** Whatever you create, change or delete in the
  preview happens in production.
- **Backend (PHP) edits are not live.** Production runs baked images with
  OPcache file checks off; a PHP change reaches the site only through the steps
  below. `:8081` itself also changes only on deploy.

#### Sharing the preview with the team (Tailscale)

The preview listens on `127.0.0.1` only. Teammates reach it through Tailscale
Serve, which is private to the tailnet. Funnel (public exposure) is never used.

```powershell
# once per machine: the name Vite must accept (frontend/.env.live.local, gitignored)
#   SCCIT_LIVE_HOSTS=<machine>.<tailnet>.ts.net   (from `tailscale serve status`)
& "C:\Program Files\Tailscale\tailscale.exe" serve --bg --https=443 http://127.0.0.1:5173
& "C:\Program Files\Tailscale\tailscale.exe" serve status     # must say "(tailnet only)"
& "C:\Program Files\Tailscale\tailscale.exe" serve --https=443 off   # stop sharing
```

`--bg` keeps the Serve config after the terminal closes. The URL still answers
**502 whenever `npm run dev:live` is not running.** Edits saved in `frontend/src/`
hot-reload for everyone who has the page open.

Four settings in `vite.config.ts` make the shared address work. Production
stays as it is:

| Setting | Without it |
|---|---|
| `host: '127.0.0.1'` | Node binds `localhost` to `::1` only, but Serve dials `127.0.0.1`, so every request returns 502 |
| `allowedHosts` ← `SCCIT_LIVE_HOSTS` | Vite answers 403 "Blocked request. This host is not allowed" |
| `cookieDomainRewrite` | production's `Domain=localhost` cookies are dropped by the browser, so sign-in fails with 419 |
| `proxyReqWs` sets Origin | Reverb refuses the socket (`4009 Origin not allowed`), so there are no live updates |

Limits that come with sharing:

- **Who gets in is decided by Tailscale.** Only members of the tailnet (or people
  the machine is shared with) can reach the URL, as the tailnet's access rules
  allow. Invite teammates in the Tailscale admin console. Everyone still signs in
  with their own SccIT account.
- **Everyone looks like one IP to production.** The per-IP limits
  (registration 5/h, password reset 6/h, login lockout by email + IP) are
  shared by the whole team.
- **Server-built links point at `http://localhost:8081`** (`APP_URL`). That
  includes links in e-mails, which teammates cannot open. Phones scanning QR
  codes must be in the tailnet too.

### Publishing a change

1. **Edit** source (§3 tells you where).
2. **Gate** — [`sh scripts/gates.sh`](../scripts/gates.sh) runs Pint, PHPStan, Pest, tsc, ESLint,
   Prettier, Vitest and the production build (with a CSP scan of the built chunks) in a disposable environment with
   its own PostgreSQL and Redis. `--only backend` / `--only frontend` to narrow.
3. **Deploy** — [`sh scripts/deploy-prod.sh`](../scripts/deploy-prod.sh): gates → restore-verified snapshot →
   build and tag images → recreate services → `migrate --force` → smoke test. It
   asks before changing anything and refuses a dirty tracked tree unless you
   pass `--allow-dirty`; the image contains your **uncommitted** changes too.
4. **Verify** with the read-only commands below; roll back with
   [`sh scripts/rollback-prod.sh <sha>`](../scripts/rollback-prod.sh) ([docs/OPERATIONS.md](OPERATIONS.md) §3).

**Safe, read-only checks** (always `-p sccit_prod -f compose.prod.yaml`):

```bash
docker compose -p sccit_prod -f compose.prod.yaml ps
curl -s http://localhost:8081/api/health        # {"status":"healthy","checks":{"app":"ok","database":"ok","redis":"ok"},...}
docker compose -p sccit_prod -f compose.prod.yaml logs --tail=100 app_prod
docker compose -p sccit_prod -f compose.prod.yaml exec -T app_prod php artisan migrate:status
docker compose -p sccit_prod -f compose.prod.yaml exec -T app_prod php artisan route:list --path=api/tickets
sh scripts/smoke.sh --stack prod                # HTTP smoke; makes one deliberately failed login
```

**Changes data or services — only on purpose:** [scripts/deploy-prod.sh](../scripts/deploy-prod.sh),
[scripts/rollback-prod.sh](../scripts/rollback-prod.sh), [scripts/restore.sh](../scripts/restore.sh) (without `--dry-run`),
`make prod-setup` (migrate + seed), [scripts/verify-ticket-roles.sh](../scripts/verify-ticket-roles.sh) and
[scripts/verify-maintenance-roles.sh](../scripts/verify-maintenance-roles.sh) (create records through the API), and any
`docker compose ... up/down/restart`. Never `down -v` (deletes the database).

---

## 2. How the layers connect

One request, end to end — the same shape in every feature:

```
frontend/src/features/<f>/pages/XPage.tsx          screen (routed in frontend/src/App.tsx)
  └ components/*.tsx                                 UI pieces (shared kit: frontend/src/components/ui/)
  └ hooks/queries.ts · hooks/mutations.ts            TanStack Query: caching, invalidation
  └ api/<f>Api.ts                                    endpoint calls
      └ frontend/src/services/api.ts                 axios + Sanctum cookie/CSRF handling
          └ docker/nginx/prod.conf                   /api, /sanctum, /broadcasting -> php-fpm
              └ backend/routes/api.php               URL -> controller, `can:<permission>` gates
                  └ Domains/<D>/Http/Requests/       validation (FormRequest)
                  └ Domains/<D>/Http/Controllers/    thin: authorize, delegate, respond
                  └ Domains/<D>/Policies/            per-record authorization
                  └ Domains/<D>/Actions/ · Services/ business rules, lifecycles, queries
                  └ backend/app/Models/ · backend/app/Enums/   Eloquent (flat, not per-domain)
                      └ backend/database/migrations/            schema
                  └ Domains/<D>/Http/Resources/      the JSON shape (role-shaped projections)
  <- features/<f>/types.ts · schemas.ts             TypeScript types / Zod validation of that JSON
```

The diagram is plain text; the shared files in it are
[frontend/src/App.tsx](../frontend/src/App.tsx),
[frontend/src/components/ui/](../frontend/src/components/ui/index.ts),
[frontend/src/services/api.ts](../frontend/src/services/api.ts),
[docker/nginx/prod.conf](../docker/nginx/prod.conf) and
[backend/routes/api.php](../backend/routes/api.php); each feature's own files
are linked in §3.

`Domains/<D>` means `backend/app/Domains/<Domain>/`. Realtime adds one more
path: a backend **event** → [backend/routes/channels.php](../backend/routes/channels.php) (who may listen) →
Reverb (`reverb_prod`, proxied at `/reverb/app/`) → [frontend/src/services/echo.ts](../frontend/src/services/echo.ts)
→ a feature's channel hook.

**Which layers a change touches:**

| Change | Touch |
|---|---|
| New column shown in the UI | migration → model (`$fillable`/casts) → FormRequest → Action/Service → Resource → `types.ts`/`schemas.ts` → component → tests |
| New endpoint | route (in the right `can:` group) → controller → FormRequest → Policy → Action/Service → Resource → `F/<f>/api/` + `F/<f>/hooks/` → an authorization test (negative cases per role, [CLAUDE.md](../CLAUDE.md) §6) |
| New page | `F/<f>/pages/` component → lazy route + guard in [frontend/src/App.tsx](../frontend/src/App.tsx) → menu entry in [frontend/src/layouts/navConfig.ts](../frontend/src/layouts/navConfig.ts) → test |
| Who may do something | route `can:` middleware → Policy → `RequirePermission`/`RequireRole` in [App.tsx](../frontend/src/App.tsx) → [navConfig.ts](../frontend/src/layouts/navConfig.ts) → authorization tests |
| Pure UI fix | the page/component only (+ its `*.test.tsx`) |

---

## 3. Feature map

`F` = `frontend/src/features/`, `B` = `backend/app/Domains/`, `T` = `backend/tests/Feature/`.
Full paths are given the first time (§3.1). In the later tables a linked file
shows only its name — the link holds the full path, visible in the source — and
these prefixes remain on folders and placeholders, which are plain text.

### 3.1 App shell and shared frontend

| Concern | Files |
|---|---|
| Entry | [frontend/index.html](../frontend/index.html), [frontend/src/main.tsx](../frontend/src/main.tsx) |
| Routing (every URL → page, with guards) | [frontend/src/App.tsx](../frontend/src/App.tsx) |
| Layouts | [frontend/src/layouts/AppLayout.tsx](../frontend/src/layouts/AppLayout.tsx) (signed-in shell), [frontend/src/layouts/AuthLayout.tsx](../frontend/src/layouts/AuthLayout.tsx), [frontend/src/layouts/PublicLayout.tsx](../frontend/src/layouts/PublicLayout.tsx) |
| Navigation per role | [frontend/src/layouts/navConfig.ts](../frontend/src/layouts/navConfig.ts) |
| Route guards | `frontend/src/features/auth/guards/` ([`ProtectedRoute`](../frontend/src/features/auth/guards/ProtectedRoute.tsx), [`GuestRoute`](../frontend/src/features/auth/guards/GuestRoute.tsx), [`RequireRole`](../frontend/src/features/auth/guards/RequireRole.tsx), [`RequirePermission`](../frontend/src/features/auth/guards/RequirePermission.tsx)); [frontend/src/features/floor-plan/guards/RequireFloorPlan.tsx](../frontend/src/features/floor-plan/guards/RequireFloorPlan.tsx); [frontend/src/features/predictions/guards/RequirePredictions.tsx](../frontend/src/features/predictions/guards/RequirePredictions.tsx) |
| UI kit | [frontend/src/components/ui/](../frontend/src/components/ui/index.ts) ([Button](../frontend/src/components/ui/Button.tsx), [Table](../frontend/src/components/ui/Table.tsx), [Drawer](../frontend/src/components/ui/Drawer.tsx), [Modal](../frontend/src/components/ui/Modal.tsx), [StatCard](../frontend/src/components/ui/StatCard.tsx), [Tabs](../frontend/src/components/ui/Tabs.tsx), …; barrel [index.ts](../frontend/src/components/ui/index.ts)) |
| Styling and theme | [frontend/src/index.css](../frontend/src/index.css) (Tailwind 4), [frontend/src/lib/cn.ts](../frontend/src/lib/cn.ts), [frontend/src/lib/theme.ts](../frontend/src/lib/theme.ts), [frontend/src/stores/useThemeStore.ts](../frontend/src/stores/useThemeStore.ts); design tokens [.impeccable/design.json](../.impeccable/design.json), [DESIGN.md](../DESIGN.md) |
| API client | [frontend/src/services/api.ts](../frontend/src/services/api.ts), [frontend/src/services/queryClient.ts](../frontend/src/services/queryClient.ts), [frontend/src/services/lookups.ts](../frontend/src/services/lookups.ts) |
| Shared helpers (photos, input) | [frontend/src/lib/images.ts](../frontend/src/lib/images.ts) (prepare a photo before upload: type check, downscale, rename to its real extension — SDD DD-80), [frontend/src/hooks/usePreparedPhotos.ts](../frontend/src/hooks/usePreparedPhotos.ts) (the photo list both ticket upload surfaces share), [frontend/src/components/PhotoPickerButtons.tsx](../frontend/src/components/PhotoPickerButtons.tsx) ("Take a photo" / "Choose photos"), [frontend/src/hooks/useDebouncedValue.ts](../frontend/src/hooks/useDebouncedValue.ts) |
| Realtime client | [frontend/src/services/echo.ts](../frontend/src/services/echo.ts), [frontend/src/hooks/usePrivateChannel.ts](../frontend/src/hooks/usePrivateChannel.ts), [frontend/src/stores/useRealtimeStore.ts](../frontend/src/stores/useRealtimeStore.ts) |
| Public site | [frontend/src/pages/LandingPage.tsx](../frontend/src/pages/LandingPage.tsx), `frontend/src/components/marketing/`, [frontend/src/pages/SystemStatusPage.tsx](../frontend/src/pages/SystemStatusPage.tsx) |
| Test setup | [frontend/src/test/setup.ts](../frontend/src/test/setup.ts); Vitest is configured in [frontend/vite.config.ts](../frontend/vite.config.ts) |

### 3.2 Dashboards, navigation and accounts

| Feature | Frontend (`F`) | Backend (`B`) | Tests |
|---|---|---|---|
| Role switch | [DashboardPage.tsx](../frontend/src/features/dashboard/pages/DashboardPage.tsx) | [DashboardController.php](../backend/app/Domains/Analytics/Http/Controllers/DashboardController.php) | [DashboardRoleTest.php](../backend/tests/Feature/Dashboard/DashboardRoleTest.php) |
| Admin dashboard | [AdminDashboard.tsx](../frontend/src/features/dashboard/pages/AdminDashboard.tsx), `F/dashboard/components/` ([KpiRow](../frontend/src/features/dashboard/components/KpiRow.tsx), [DistributionMeter](../frontend/src/features/dashboard/components/DistributionMeter.tsx), [AdminTicketQueue](../frontend/src/features/dashboard/components/AdminTicketQueue.tsx), [LiveStatus](../frontend/src/features/dashboard/components/LiveStatus.tsx)), [useAdminDashboardChannel.ts](../frontend/src/features/dashboard/hooks/useAdminDashboardChannel.ts) | [DashboardService.php](../backend/app/Domains/Analytics/Services/DashboardService.php), [TicketMetrics.php](../backend/app/Domains/Analytics/Services/TicketMetrics.php), [AssetMetrics.php](../backend/app/Domains/Analytics/Services/AssetMetrics.php), [AdminDashboardChanged.php](../backend/app/Domains/Analytics/Events/AdminDashboardChanged.php) | [DashboardMetricsTest.php](../backend/tests/Feature/Dashboard/DashboardMetricsTest.php), [AdminDashboardRealtimeTest.php](../backend/tests/Feature/Dashboard/AdminDashboardRealtimeTest.php), [frontend/src/features/dashboard/components/DistributionMeter.test.tsx](../frontend/src/features/dashboard/components/DistributionMeter.test.tsx) |
| Teacher dashboard | [TeacherDashboard.tsx](../frontend/src/features/dashboard/pages/TeacherDashboard.tsx), [RequestSummary.tsx](../frontend/src/features/dashboard/components/RequestSummary.tsx) ("My requests": one link per figure into [MyTicketsPage](../frontend/src/features/tickets/pages/MyTicketsPage.tsx) `?view=`) | same service, role-shaped payload | [DashboardRoleTest.php](../backend/tests/Feature/Dashboard/DashboardRoleTest.php), [RequestSummary.test.tsx](../frontend/src/features/dashboard/components/RequestSummary.test.tsx), [TeacherDashboard.test.tsx](../frontend/src/features/dashboard/pages/TeacherDashboard.test.tsx) |
| Technician dashboard | [TechnicianDashboard.tsx](../frontend/src/features/dashboard/pages/TechnicianDashboard.tsx), [ScanGuide.tsx](../frontend/src/features/dashboard/components/ScanGuide.tsx) | same | [DashboardRoleTest.php](../backend/tests/Feature/Dashboard/DashboardRoleTest.php) |
| Technician monitoring (admin) | [TechniciansPage.tsx](../frontend/src/features/technicians/pages/TechniciansPage.tsx), [TechnicianDetailPage.tsx](../frontend/src/features/technicians/pages/TechnicianDetailPage.tsx) | [TechnicianMonitoringController.php](../backend/app/Domains/Analytics/Http/Controllers/Admin/TechnicianMonitoringController.php), [TechnicianMonitoring.php](../backend/app/Domains/Analytics/Services/TechnicianMonitoring.php) | [TechnicianMonitoringTest.php](../backend/tests/Feature/Dashboard/TechnicianMonitoringTest.php) |
| Account page (`/app/account`) | [WorkspaceHomePage.tsx](../frontend/src/features/auth/pages/WorkspaceHomePage.tsx) | [ProfileController.php](../backend/app/Domains/Identity/Http/Controllers/ProfileController.php) | [ProfileTest.php](../backend/tests/Feature/Auth/ProfileTest.php) |

Menu entries per role (teacher / technician / administrator) are all in
[frontend/src/layouts/navConfig.ts](../frontend/src/layouts/navConfig.ts).

### 3.3 Tickets

| Feature | Frontend (`F/tickets/`) | Backend (`B/Tickets/`) | Tests (`T/Tickets/`) |
|---|---|---|---|
| Report a fault ("Other" description, reporter priority, photos — SDD DD-75..80) | [CreateTicketPage.tsx](../frontend/src/features/tickets/pages/CreateTicketPage.tsx), [PriorityChoice.tsx](../frontend/src/features/tickets/components/PriorityChoice.tsx) + [priorityGuides.ts](../frontend/src/features/tickets/priorityGuides.ts), [WhereIsItPicker.tsx](../frontend/src/features/tickets/components/WhereIsItPicker.tsx), [ReportPhotos.tsx](../frontend/src/features/tickets/components/ReportPhotos.tsx), [PhotoDraftList.tsx](../frontend/src/features/tickets/components/PhotoDraftList.tsx), [mutations.ts](../frontend/src/features/tickets/hooks/mutations.ts) (`useReportTicket`: create, then one upload per photo), [useReportDraft.ts](../frontend/src/features/tickets/hooks/useReportDraft.ts) (unsent-report draft), [reportOutcome.ts](../frontend/src/features/tickets/reportOutcome.ts) + [ReportOutcomeNotice.tsx](../frontend/src/features/tickets/components/ReportOutcomeNotice.tsx) (the notice on the ticket afterwards), [TicketAttachments.tsx](../frontend/src/features/tickets/components/TicketAttachments.tsx) ("Photos & files"), [DuplicateSuggestions.tsx](../frontend/src/features/tickets/components/DuplicateSuggestions.tsx), [PcAlreadyReported.tsx](../frontend/src/features/tickets/components/PcAlreadyReported.tsx), [schemas.ts](../frontend/src/features/tickets/schemas.ts) | [TicketController.php](../backend/app/Domains/Tickets/Http/Controllers/TicketController.php), [TicketOptionsController.php](../backend/app/Domains/Tickets/Http/Controllers/TicketOptionsController.php) (role-shaped priorities, `requires_detail`, `uploads.max_kb`), [StoreTicketRequest.php](../backend/app/Domains/Tickets/Http/Requests/StoreTicketRequest.php), [CreateTicket.php](../backend/app/Domains/Tickets/Actions/CreateTicket.php) (`resolvePriority`), [backend/app/Enums/TicketPrioritySource.php](../backend/app/Enums/TicketPrioritySource.php) (the order of authority), [ReportableEquipment.php](../backend/app/Domains/Assets/Services/ReportableEquipment.php), [TicketParticipationController.php](../backend/app/Domains/Tickets/Http/Controllers/TicketParticipationController.php) (`storeAttachment`, throttled), [DuplicateFinder.php](../backend/app/Domains/Tickets/Services/DuplicateFinder.php) | [TicketWorkflowTest.php](../backend/tests/Feature/Tickets/TicketWorkflowTest.php), [TicketLocationPriorityTest.php](../backend/tests/Feature/Tickets/TicketLocationPriorityTest.php), [TicketReporterPriorityTest.php](../backend/tests/Feature/Tickets/TicketReporterPriorityTest.php), [TicketPriorityRenameMigrationTest.php](../backend/tests/Feature/Tickets/TicketPriorityRenameMigrationTest.php), [TicketCategoryDetailTest.php](../backend/tests/Feature/Tickets/TicketCategoryDetailTest.php), [TicketEquipmentLinkTest.php](../backend/tests/Feature/Tickets/TicketEquipmentLinkTest.php), [TicketReportPhotosTest.php](../backend/tests/Feature/Tickets/TicketReportPhotosTest.php), [CreateTicketPage.test.tsx](../frontend/src/features/tickets/pages/CreateTicketPage.test.tsx) |
| Report-form lookups (building → room → item) | [lookups.ts](../frontend/src/services/lookups.ts) (`sort=type`, `lookupRoomEquipment`), [useLocationLookup.ts](../frontend/src/hooks/useLocationLookup.ts), [useEquipmentLookup.ts](../frontend/src/hooks/useEquipmentLookup.ts) (`useRoomEquipmentLookup`), consumed by [WhereIsItPicker.tsx](../frontend/src/features/tickets/components/WhereIsItPicker.tsx) | [LocationLookupController.php](../backend/app/Domains/Locations/Http/Controllers/LocationLookupController.php), [LocationLookupRequest.php](../backend/app/Domains/Locations/Http/Requests/LocationLookupRequest.php), [LocationOptions.php](../backend/app/Domains/Locations/Services/LocationOptions.php) (`selectableRooms()`, shared with validation), [AssetLookupController.php](../backend/app/Domains/Assets/Http/Controllers/AssetLookupController.php) (`roomEquipment`), [RoomEquipmentLookupRequest.php](../backend/app/Domains/Assets/Http/Requests/RoomEquipmentLookupRequest.php), [AssetOptions.php](../backend/app/Domains/Assets/Services/AssetOptions.php) (`roomPcUnits`/`roomAssets`, no search), [ReportableEquipment.php](../backend/app/Domains/Assets/Services/ReportableEquipment.php) | [RoomEquipmentLookupAuthorizationTest.php](../backend/tests/Feature/Assets/RoomEquipmentLookupAuthorizationTest.php), [RoomEquipmentLookupTest.php](../backend/tests/Feature/Assets/RoomEquipmentLookupTest.php), [LocationOptionsTest.php](../backend/tests/Feature/Locations/LocationOptionsTest.php), [WhereIsItPicker.test.tsx](../frontend/src/features/tickets/components/WhereIsItPicker.test.tsx) |
| My tickets, detail, history tab | [MyTicketsPage.tsx](../frontend/src/features/tickets/pages/MyTicketsPage.tsx), [TicketDetailPage.tsx](../frontend/src/features/tickets/pages/TicketDetailPage.tsx), [TicketTimeline.tsx](../frontend/src/features/tickets/components/TicketTimeline.tsx), [myTicketViews.ts](../frontend/src/features/tickets/myTicketViews.ts) (the `?view=` presets the dashboard figures link to) | [TicketController.php](../backend/app/Domains/Tickets/Http/Controllers/TicketController.php), [TicketVisibility.php](../backend/app/Domains/Tickets/Services/TicketVisibility.php) (row scoping), [TicketDirectoryQuery.php](../backend/app/Domains/Tickets/Services/TicketDirectoryQuery.php) (`own()`, incl. `resolved_within`), [TicketTimelineController.php](../backend/app/Domains/Tickets/Http/Controllers/TicketTimelineController.php) | [TicketVisibilityTest.php](../backend/tests/Feature/Tickets/TicketVisibilityTest.php), [TicketApiAuthorizationTest.php](../backend/tests/Feature/Tickets/TicketApiAuthorizationTest.php), [TicketMineResolvedWithinTest.php](../backend/tests/Feature/Tickets/TicketMineResolvedWithinTest.php) (list vs dashboard-figure parity) |
| Community feed | [TicketFeedPage.tsx](../frontend/src/features/tickets/pages/TicketFeedPage.tsx), [CommunityFeedPanel.tsx](../frontend/src/features/tickets/components/CommunityFeedPanel.tsx), [TicketCardItem.tsx](../frontend/src/features/tickets/components/TicketCardItem.tsx) (the card: whole card links to the ticket, vote and comments sit above the link) | [TicketFeedController.php](../backend/app/Domains/Tickets/Http/Controllers/TicketFeedController.php), [TicketFeedResource.php](../backend/app/Domains/Tickets/Http/Resources/TicketFeedResource.php) | [TicketCommunityPrivacyTest.php](../backend/tests/Feature/Tickets/TicketCommunityPrivacyTest.php) |
| Upvotes | [VoteButton.tsx](../frontend/src/features/tickets/components/VoteButton.tsx) | [TicketParticipationController.php](../backend/app/Domains/Tickets/Http/Controllers/TicketParticipationController.php) (`vote`), [ToggleTicketVote.php](../backend/app/Domains/Tickets/Actions/ToggleTicketVote.php), [backend/app/Models/TicketVote.php](../backend/app/Models/TicketVote.php) | [TicketWorkflowTest.php](../backend/tests/Feature/Tickets/TicketWorkflowTest.php) |
| Comments (threaded one level, SDD DD-74) | [TicketComments.tsx](../frontend/src/features/tickets/components/TicketComments.tsx) (threads and replies), [TicketCommentsDialog.tsx](../frontend/src/features/tickets/components/TicketCommentsDialog.tsx) (opened from a feed card) | [TicketParticipationController.php](../backend/app/Domains/Tickets/Http/Controllers/TicketParticipationController.php) (`comments` pages by thread), [StoreTicketCommentRequest.php](../backend/app/Domains/Tickets/Http/Requests/StoreTicketCommentRequest.php) (reply rules), [ManageTicketComment.php](../backend/app/Domains/Tickets/Actions/ManageTicketComment.php), [TicketCommentPolicy.php](../backend/app/Domains/Tickets/Policies/TicketCommentPolicy.php), [TicketCommentResource.php](../backend/app/Domains/Tickets/Http/Resources/TicketCommentResource.php) | [TicketCommentRepliesTest.php](../backend/tests/Feature/Tickets/TicketCommentRepliesTest.php), [TicketApiAuthorizationTest.php](../backend/tests/Feature/Tickets/TicketApiAuthorizationTest.php), [TicketWorkflowTest.php](../backend/tests/Feature/Tickets/TicketWorkflowTest.php) |
| Assignment (admin) | [TicketManagementPage.tsx](../frontend/src/features/tickets/pages/TicketManagementPage.tsx), [TicketAdminDetailPage.tsx](../frontend/src/features/tickets/pages/TicketAdminDetailPage.tsx), [AdminTicketActions.tsx](../frontend/src/features/tickets/components/AdminTicketActions.tsx) | [TicketAssignmentController.php](../backend/app/Domains/Tickets/Http/Controllers/Admin/TicketAssignmentController.php), [AssignTicket.php](../backend/app/Domains/Tickets/Actions/AssignTicket.php), [TicketAssignmentPolicy.php](../backend/app/Domains/Tickets/Policies/TicketAssignmentPolicy.php), [TicketDirectoryController.php](../backend/app/Domains/Tickets/Http/Controllers/Admin/TicketDirectoryController.php) | [TicketAssignmentRaceTest.php](../backend/tests/Feature/Tickets/TicketAssignmentRaceTest.php) |
| Technician queue (accept, decline, start, hold, complete) | [AssignedTicketsPage.tsx](../frontend/src/features/tickets/pages/AssignedTicketsPage.tsx), [AssignedTicketDetailPage.tsx](../frontend/src/features/tickets/pages/AssignedTicketDetailPage.tsx), [AssignmentHistoryPage.tsx](../frontend/src/features/tickets/pages/AssignmentHistoryPage.tsx), [AssignmentActions.tsx](../frontend/src/features/tickets/components/AssignmentActions.tsx) | [TechnicianQueueController.php](../backend/app/Domains/Tickets/Http/Controllers/TechnicianQueueController.php), [RespondToAssignment.php](../backend/app/Domains/Tickets/Actions/RespondToAssignment.php) | [TicketWorkflowTest.php](../backend/tests/Feature/Tickets/TicketWorkflowTest.php) |
| Status workflow | [TicketStatusActions.tsx](../frontend/src/features/tickets/components/TicketStatusActions.tsx), [TicketBadges.tsx](../frontend/src/features/tickets/components/TicketBadges.tsx) | [ChangeTicketStatus.php](../backend/app/Domains/Tickets/Actions/ChangeTicketStatus.php), [TicketLifecycle.php](../backend/app/Domains/Tickets/Services/TicketLifecycle.php) | [TicketLifecycleTest.php](../backend/tests/Feature/Tickets/TicketLifecycleTest.php) |
| Escalation: "not fixed" | [TicketDetailPage.tsx](../frontend/src/features/tickets/pages/TicketDetailPage.tsx), [TicketAiTroubleshooting.tsx](../frontend/src/features/tickets/components/TicketAiTroubleshooting.tsx) | [TicketController.php](../backend/app/Domains/Tickets/Http/Controllers/TicketController.php) (`reportNotFixed`), [ReportTicketNotFixed.php](../backend/app/Domains/Tickets/Actions/ReportTicketNotFixed.php), [TicketEscalatedByReporter.php](../backend/app/Domains/Tickets/Events/TicketEscalatedByReporter.php), [ReporterOutcome.php](../backend/app/Domains/Tickets/Services/ReporterOutcome.php) | [TicketEscalationTest.php](../backend/tests/Feature/Tickets/TicketEscalationTest.php), [TicketReporterOutcomeTest.php](../backend/tests/Feature/Tickets/TicketReporterOutcomeTest.php) |
| Escalation: SLA | — | [DetectSlaBreaches.php](../backend/app/Domains/Tickets/Console/DetectSlaBreaches.php), [SlaCalculator.php](../backend/app/Domains/Tickets/Services/SlaCalculator.php), [TicketSlaThresholdCrossed.php](../backend/app/Domains/Tickets/Events/TicketSlaThresholdCrossed.php) | [SlaDetectionTest.php](../backend/tests/Feature/Notifications/SlaDetectionTest.php) |
| Auto-close resolved tickets | — | [CloseStaleResolvedTickets.php](../backend/app/Domains/Tickets/Console/CloseStaleResolvedTickets.php) (scheduled in [backend/routes/console.php](../backend/routes/console.php)) | — |

Key URLs: `POST /api/tickets`, `GET /api/tickets/options`,
`POST /api/tickets/{ticket}/attachments` (throttled), `GET /api/lookups/rooms`,
`GET /api/lookups/equipment?room=` (throttled), `GET /api/tickets/feed`, `POST /api/tickets/{ticket}/vote`,
`GET|POST /api/tickets/{ticket}/comments`, `PUT /api/tickets/{ticket}/status`,
`POST /api/tickets/{ticket}/not-fixed`, `/api/tickets/assigned/...` — all in
[backend/routes/api.php](../backend/routes/api.php). Models: [backend/app/Models/Ticket.php](../backend/app/Models/Ticket.php),
[TicketComment.php](../backend/app/Models/TicketComment.php), [TicketStatusHistory.php](../backend/app/Models/TicketStatusHistory.php), [TechnicianAssignment.php](../backend/app/Models/TechnicianAssignment.php).

### 3.4 Assets, PC units, locations, floor plan

| Feature | Frontend | Backend | Tests |
|---|---|---|---|
| Asset register | [AssetsPage.tsx](../frontend/src/features/assets/pages/AssetsPage.tsx), [AssetDetailPage.tsx](../frontend/src/features/assets/pages/AssetDetailPage.tsx), [AssetDashboardPage.tsx](../frontend/src/features/assets/pages/AssetDashboardPage.tsx), `F/assets/components/` | [AssetController.php](../backend/app/Domains/Assets/Http/Controllers/Admin/AssetController.php), [AssetActionController.php](../backend/app/Domains/Assets/Http/Controllers/Admin/AssetActionController.php), [AssetLifecycle.php](../backend/app/Domains/Assets/Services/AssetLifecycle.php), [AssetPolicy.php](../backend/app/Domains/Assets/Policies/AssetPolicy.php), [backend/app/Models/Asset.php](../backend/app/Models/Asset.php) | [AssetCrudTest.php](../backend/tests/Feature/Assets/AssetCrudTest.php), [AssetLifecycleTest.php](../backend/tests/Feature/Assets/AssetLifecycleTest.php), [AssetAuthorizationTest.php](../backend/tests/Feature/Assets/AssetAuthorizationTest.php) |
| PC units and specs | [PcUnitDetailPage.tsx](../frontend/src/features/assets/pages/PcUnitDetailPage.tsx), [PcUnitsTable.tsx](../frontend/src/features/assets/components/PcUnitsTable.tsx), [PcUnitFormDrawer.tsx](../frontend/src/features/assets/components/PcUnitFormDrawer.tsx), [SpecificationEditor.tsx](../frontend/src/features/assets/components/SpecificationEditor.tsx) | [PcUnitController.php](../backend/app/Domains/Assets/Http/Controllers/Admin/PcUnitController.php), [CreatePcUnit.php](../backend/app/Domains/Assets/Actions/CreatePcUnit.php), [UpsertPcSpecification.php](../backend/app/Domains/Assets/Actions/UpsertPcSpecification.php), [PcUnitPolicy.php](../backend/app/Domains/Assets/Policies/PcUnitPolicy.php), [backend/app/Models/PcUnit.php](../backend/app/Models/PcUnit.php), [backend/app/Enums/PcStatus.php](../backend/app/Enums/PcStatus.php) | [PcUnitTest.php](../backend/tests/Feature/Assets/PcUnitTest.php), [PcUnitMaintenanceTest.php](../backend/tests/Feature/Assets/PcUnitMaintenanceTest.php) |
| My assets (custodian) | [MyAssetsPage.tsx](../frontend/src/features/assets/pages/MyAssetsPage.tsx) | [AssetController.php](../backend/app/Domains/Assets/Http/Controllers/Admin/AssetController.php) (`mine`, `GET /api/assets`) | [CustodianAssetAccessTest.php](../backend/tests/Feature/Assets/CustodianAssetAccessTest.php) |
| Attachments, history | [AttachmentGallery.tsx](../frontend/src/features/assets/components/AttachmentGallery.tsx), [AssetHistoryTimeline.tsx](../frontend/src/features/assets/components/AssetHistoryTimeline.tsx) | [AssetAttachmentController.php](../backend/app/Domains/Assets/Http/Controllers/Admin/AssetAttachmentController.php), [AssetHistoryController.php](../backend/app/Domains/Assets/Http/Controllers/Admin/AssetHistoryController.php), [AssetHistory.php](../backend/app/Domains/Assets/Services/AssetHistory.php) (includes an asset's own tickets), [AssetGuard.php](../backend/app/Domains/Assets/Services/AssetGuard.php) (archive blockers: an asset's open tickets and its host PC's), [backend/app/Support/Attachments/AttachmentSecurity.php](../backend/app/Support/Attachments/AttachmentSecurity.php) | [AssetAttachmentTest.php](../backend/tests/Feature/Assets/AssetAttachmentTest.php), [AssetHistoryTest.php](../backend/tests/Feature/Assets/AssetHistoryTest.php), [TicketEquipmentLinkTest.php](../backend/tests/Feature/Tickets/TicketEquipmentLinkTest.php) |
| QR codes (admin) | [QrCodePanel.tsx](../frontend/src/features/assets/components/QrCodePanel.tsx) | [QrCodeController.php](../backend/app/Domains/Assets/Http/Controllers/Admin/QrCodeController.php), [QrService.php](../backend/app/Domains/Assets/Services/QrService.php) | [QrCodeTest.php](../backend/tests/Feature/Assets/QrCodeTest.php), [frontend/src/features/assets/components/QrCodePanel.test.tsx](../frontend/src/features/assets/components/QrCodePanel.test.tsx) |
| Locations (buildings, floors, rooms) | [LocationsPage.tsx](../frontend/src/features/locations/pages/LocationsPage.tsx), [BuildingDetailPage.tsx](../frontend/src/features/locations/pages/BuildingDetailPage.tsx), [RoomDetailPage.tsx](../frontend/src/features/locations/pages/RoomDetailPage.tsx) | [BuildingController.php](../backend/app/Domains/Locations/Http/Controllers/Admin/BuildingController.php), [FloorController.php](../backend/app/Domains/Locations/Http/Controllers/Admin/FloorController.php), [RoomController.php](../backend/app/Domains/Locations/Http/Controllers/Admin/RoomController.php), [LocationArchiver.php](../backend/app/Domains/Locations/Services/LocationArchiver.php) | `T/Locations/` |
| Floor plan | [FloorPlanPage.tsx](../frontend/src/features/floor-plan/pages/FloorPlanPage.tsx), [FloorPlanRoomPage.tsx](../frontend/src/features/floor-plan/pages/FloorPlanRoomPage.tsx), [FloorPlanCanvas.tsx](../frontend/src/features/floor-plan/components/FloorPlanCanvas.tsx), [CreateLayoutForm.tsx](../frontend/src/features/floor-plan/components/CreateLayoutForm.tsx), [useViewportStore.ts](../frontend/src/features/floor-plan/stores/useViewportStore.ts), [useFloorPlanChannel.ts](../frontend/src/features/floor-plan/hooks/useFloorPlanChannel.ts) | [FloorPlanController.php](../backend/app/Domains/FloorPlan/Http/Controllers/Admin/FloorPlanController.php), [RoomLayoutController.php](../backend/app/Domains/FloorPlan/Http/Controllers/Admin/RoomLayoutController.php), [FloorPlanPositionController.php](../backend/app/Domains/FloorPlan/Http/Controllers/Admin/FloorPlanPositionController.php), [FloorPlanService.php](../backend/app/Domains/FloorPlan/Services/FloorPlanService.php), [FloorPlan/README.md](../backend/app/Domains/FloorPlan/README.md) | `T/FloorPlan/`, `backend/tests/Unit/FloorPlan/`, `*.test.tsx` under `F/floor-plan/` |

### 3.5 QR verification, technician work, maintenance

| Feature | Frontend | Backend | Tests |
|---|---|---|---|
| QR scan → panel (`/qr/:code`) | [ScanLandingPage.tsx](../frontend/src/features/qr/pages/ScanLandingPage.tsx), [ScanPanelPage.tsx](../frontend/src/features/qr/pages/ScanPanelPage.tsx), [pendingScan.ts](../frontend/src/features/qr/lib/pendingScan.ts), [ScanRefusalNotice.tsx](../frontend/src/features/qr/components/ScanRefusalNotice.tsx) | [QrScanController.php](../backend/app/Domains/Assets/Http/Controllers/QrScanController.php), [RecordQrScan.php](../backend/app/Domains/Assets/Actions/RecordQrScan.php), [QrScanResolver.php](../backend/app/Domains/Assets/Services/QrScanResolver.php), [ScannedPcAccess.php](../backend/app/Domains/Assets/Services/ScannedPcAccess.php) | [QrScanTest.php](../backend/tests/Feature/Qr/QrScanTest.php), [QrPanelAuthorizationTest.php](../backend/tests/Feature/Qr/QrPanelAuthorizationTest.php), [QrRateLimitTest.php](../backend/tests/Feature/Qr/QrRateLimitTest.php) |
| Proof of work | [ProofOfWorkForm.tsx](../frontend/src/features/qr/components/ProofOfWorkForm.tsx) | [QrProofOfWorkController.php](../backend/app/Domains/Maintenance/Http/Controllers/QrProofOfWorkController.php), [SubmitProofOfWork.php](../backend/app/Domains/Maintenance/Actions/SubmitProofOfWork.php), [ScannedWorkTargets.php](../backend/app/Domains/Maintenance/Services/ScannedWorkTargets.php) | [ProofOfWorkTest.php](../backend/tests/Feature/Qr/ProofOfWorkTest.php), [ProofOfWorkIdempotencyTest.php](../backend/tests/Feature/Qr/ProofOfWorkIdempotencyTest.php), [QrTicketBindingAndFreshnessTest.php](../backend/tests/Feature/Qr/QrTicketBindingAndFreshnessTest.php) |
| Work-support requests | [MySubmissionsPage.tsx](../frontend/src/features/work-support/pages/MySubmissionsPage.tsx), [SupportRequestInboxPage.tsx](../frontend/src/features/work-support/pages/SupportRequestInboxPage.tsx), [RaiseSupportRequestForm.tsx](../frontend/src/features/work-support/components/RaiseSupportRequestForm.tsx) | [WorkSupportRequestController.php](../backend/app/Domains/WorkSupport/Http/Controllers/WorkSupportRequestController.php), [WorkSupportRequestDecisionController.php](../backend/app/Domains/WorkSupport/Http/Controllers/Admin/WorkSupportRequestDecisionController.php), [WorkSupportRequestLifecycle.php](../backend/app/Domains/WorkSupport/Services/WorkSupportRequestLifecycle.php) | `T/WorkSupport/` |
| Maintenance records | [MaintenanceManagementPage.tsx](../frontend/src/features/maintenance/pages/MaintenanceManagementPage.tsx), [MaintenanceDetailPage.tsx](../frontend/src/features/maintenance/pages/MaintenanceDetailPage.tsx), [CreateMaintenancePage.tsx](../frontend/src/features/maintenance/pages/CreateMaintenancePage.tsx), [ScheduledMaintenancePage.tsx](../frontend/src/features/maintenance/pages/ScheduledMaintenancePage.tsx), [MaintenanceHistoryPage.tsx](../frontend/src/features/maintenance/pages/MaintenanceHistoryPage.tsx) | [MaintenanceRecordController.php](../backend/app/Domains/Maintenance/Http/Controllers/MaintenanceRecordController.php), [MaintenanceLifecycle.php](../backend/app/Domains/Maintenance/Services/MaintenanceLifecycle.php), [MaintenanceVisibility.php](../backend/app/Domains/Maintenance/Services/MaintenanceVisibility.php), [MaintenanceRecordPolicy.php](../backend/app/Domains/Maintenance/Policies/MaintenanceRecordPolicy.php) | `T/Maintenance/` |
| Technician maintenance queue | [MaintenanceQueuePage.tsx](../frontend/src/features/maintenance/pages/MaintenanceQueuePage.tsx) | [MaintenanceQueueController.php](../backend/app/Domains/Maintenance/Http/Controllers/MaintenanceQueueController.php) | `T/Maintenance/` |
| Checklists, evidence, parts | [ChecklistPanel.tsx](../frontend/src/features/maintenance/components/ChecklistPanel.tsx), [RepairEvidenceGallery.tsx](../frontend/src/features/maintenance/components/RepairEvidenceGallery.tsx), [HardwareReplacementPanel.tsx](../frontend/src/features/maintenance/components/HardwareReplacementPanel.tsx) | [MaintenanceChecklistController.php](../backend/app/Domains/Maintenance/Http/Controllers/MaintenanceChecklistController.php), [MaintenanceEvidenceController.php](../backend/app/Domains/Maintenance/Http/Controllers/MaintenanceEvidenceController.php), [HardwareReplacementController.php](../backend/app/Domains/Maintenance/Http/Controllers/HardwareReplacementController.php) | [TechnicianRepairRecordTest.php](../backend/tests/Feature/Maintenance/TechnicianRepairRecordTest.php) |
| Preventive maintenance due | — | [DetectDuePreventiveMaintenance.php](../backend/app/Domains/Maintenance/Console/DetectDuePreventiveMaintenance.php) (scheduled in [backend/routes/console.php](../backend/routes/console.php)) | [NotificationTriggerTest.php](../backend/tests/Feature/Notifications/NotificationTriggerTest.php), [MaintenanceWorkflowTest.php](../backend/tests/Feature/Maintenance/MaintenanceWorkflowTest.php) |

### 3.6 AI (Gemini)

| Feature | Frontend | Backend | Tests (`T/AI/`) |
|---|---|---|---|
| Assistant chat | [AssistantLauncher.tsx](../frontend/src/features/assistant/components/AssistantLauncher.tsx), [AssistantPanel.tsx](../frontend/src/features/assistant/components/AssistantPanel.tsx), [assistant.ts](../frontend/src/features/assistant/hooks/assistant.ts), [assistantApi.ts](../frontend/src/features/assistant/api/assistantApi.ts) | [AssistantController.php](../backend/app/Domains/KnowledgeBase/Http/Controllers/AssistantController.php), [AssistantService.php](../backend/app/Domains/KnowledgeBase/Services/AssistantService.php), [AssistantAgent.php](../backend/app/Domains/KnowledgeBase/Agents/AssistantAgent.php) (instructions) | [AssistantTest.php](../backend/tests/Feature/AI/AssistantTest.php), [frontend/src/features/assistant/components/AssistantLauncher.test.tsx](../frontend/src/features/assistant/components/AssistantLauncher.test.tsx) |
| What the assistant may read | — | [AssistantContext.php](../backend/app/Domains/KnowledgeBase/Services/AssistantContext.php), [AssistantToolbox.php](../backend/app/Domains/KnowledgeBase/Services/AssistantToolbox.php), `B/KnowledgeBase/Assistant/Tools/` (one read-only tool per data source), [ReadOnlyAssistantTool.php](../backend/app/Domains/KnowledgeBase/Assistant/ReadOnlyAssistantTool.php) | [AssistantToolsTest.php](../backend/tests/Feature/AI/AssistantToolsTest.php) |
| Context, redaction, safety | — | [PromptRedactor.php](../backend/app/Domains/KnowledgeBase/Services/PromptRedactor.php), [PromptGuard.php](../backend/app/Domains/KnowledgeBase/Services/PromptGuard.php), [SafeAgentInvoker.php](../backend/app/Domains/KnowledgeBase/Services/SafeAgentInvoker.php) | [AssistantDataSafetyTest.php](../backend/tests/Feature/AI/AssistantDataSafetyTest.php), [PromptGuardTest.php](../backend/tests/Feature/AI/PromptGuardTest.php), [SafeAgentInvokerTest.php](../backend/tests/Feature/AI/SafeAgentInvokerTest.php), [backend/tests/Unit/PromptRedactorTest.php](../backend/tests/Unit/PromptRedactorTest.php) |
| Gemini provider, key, rate limits | — | [backend/config/ai.php](../backend/config/ai.php), [GeminiCredential.php](../backend/app/Domains/KnowledgeBase/Services/GeminiCredential.php) (reads `/run/secrets/gemini_api_key`, mounted from `secrets/gemini_api_key.txt` by [compose.prod.yaml](../compose.prod.yaml)), [GeminiGovernor.php](../backend/app/Domains/KnowledgeBase/Services/GeminiGovernor.php), [AiServiceProvider.php](../backend/app/Domains/KnowledgeBase/Providers/AiServiceProvider.php) | [GeminiCredentialTest.php](../backend/tests/Feature/AI/GeminiCredentialTest.php), [GeminiConcurrencyTest.php](../backend/tests/Feature/AI/GeminiConcurrencyTest.php), [CheckAiConnectionTest.php](../backend/tests/Feature/AI/CheckAiConnectionTest.php) |
| AI settings (admin) | [AiSettingsPage.tsx](../frontend/src/features/knowledge-base/pages/AiSettingsPage.tsx), [aiSettingsApi.ts](../frontend/src/features/knowledge-base/api/aiSettingsApi.ts) | [AiSettingsController.php](../backend/app/Domains/KnowledgeBase/Http/Controllers/Admin/AiSettingsController.php), [AiSettings.php](../backend/app/Domains/KnowledgeBase/Services/AiSettings.php) | [AiSettingsTest.php](../backend/tests/Feature/AI/AiSettingsTest.php), [AiSettingsAdministrationTest.php](../backend/tests/Feature/AI/AiSettingsAdministrationTest.php) |
| Ticket pre-screening, priority | [TicketAiTroubleshooting.tsx](../frontend/src/features/tickets/components/TicketAiTroubleshooting.tsx) | [TicketPreScreeningAgent.php](../backend/app/Domains/KnowledgeBase/Agents/TicketPreScreeningAgent.php), [AnalyzeTicketJob.php](../backend/app/Domains/KnowledgeBase/Jobs/AnalyzeTicketJob.php), [PriorityRecommendationPolicy.php](../backend/app/Domains/KnowledgeBase/Services/PriorityRecommendationPolicy.php), [TicketAiAnalysisController.php](../backend/app/Domains/Tickets/Http/Controllers/TicketAiAnalysisController.php) | [AnalyzeTicketJobTest.php](../backend/tests/Feature/AI/AnalyzeTicketJobTest.php), [AiPriorityRecommendationTest.php](../backend/tests/Feature/AI/AiPriorityRecommendationTest.php), [TicketAiAnalysisEndpointTest.php](../backend/tests/Feature/Tickets/TicketAiAnalysisEndpointTest.php) |
| Knowledge base (articles, embeddings) | no dedicated page — API under `/api/knowledge` | [KnowledgeArticleController.php](../backend/app/Domains/KnowledgeBase/Http/Controllers/KnowledgeArticleController.php), [KnowledgeIndexer.php](../backend/app/Domains/KnowledgeBase/Services/KnowledgeIndexer.php), [KnowledgeRetriever.php](../backend/app/Domains/KnowledgeBase/Services/KnowledgeRetriever.php), [EmbeddingGenerator.php](../backend/app/Domains/KnowledgeBase/Services/EmbeddingGenerator.php), [IndexKnowledgeArticles.php](../backend/app/Domains/KnowledgeBase/Console/IndexKnowledgeArticles.php), [KnowledgeBase/README.md](../backend/app/Domains/KnowledgeBase/README.md) | [KnowledgeArticleApiTest.php](../backend/tests/Feature/AI/KnowledgeArticleApiTest.php), [KnowledgeRetrievalTest.php](../backend/tests/Feature/AI/KnowledgeRetrievalTest.php), [backend/tests/Unit/KnowledgeChunkerTest.php](../backend/tests/Unit/KnowledgeChunkerTest.php) |
| Predictive maintenance | [PredictionsListPage.tsx](../frontend/src/features/predictions/pages/PredictionsListPage.tsx), [PredictionDetailPage.tsx](../frontend/src/features/predictions/pages/PredictionDetailPage.tsx) | [PcFailurePredictionAgent.php](../backend/app/Domains/KnowledgeBase/Agents/PcFailurePredictionAgent.php), [AnalyzePcHistoryJob.php](../backend/app/Domains/KnowledgeBase/Jobs/AnalyzePcHistoryJob.php), [PcRiskAssessor.php](../backend/app/Domains/KnowledgeBase/Services/PcRiskAssessor.php), [PcPredictionDecision.php](../backend/app/Domains/KnowledgeBase/Services/PcPredictionDecision.php), [AiPredictionController.php](../backend/app/Domains/KnowledgeBase/Http/Controllers/Admin/AiPredictionController.php) | [PcPredictionTest.php](../backend/tests/Feature/AI/PcPredictionTest.php), [PcPredictionReviewTest.php](../backend/tests/Feature/AI/PcPredictionReviewTest.php), [PcPredictionAuthorizationTest.php](../backend/tests/Feature/AI/PcPredictionAuthorizationTest.php) |

AI jobs run on the `ai` queue (`queue_ai_prod`, see [compose.prod.yaml](../compose.prod.yaml)); the
Gemini key is never in `.env` or a config cache.

### 3.7 Reports, charts, exports, imports

| Feature | Frontend | Backend | Tests |
|---|---|---|---|
| Dashboard charts and KPIs | [KpiRow.tsx](../frontend/src/features/dashboard/components/KpiRow.tsx), [DistributionMeter.tsx](../frontend/src/features/dashboard/components/DistributionMeter.tsx), [WidgetCard.tsx](../frontend/src/features/dashboard/components/WidgetCard.tsx), [TicketMetricsCards.tsx](../frontend/src/features/tickets/components/TicketMetricsCards.tsx), [AssetMetricsCards.tsx](../frontend/src/features/assets/components/AssetMetricsCards.tsx) | [DashboardService.php](../backend/app/Domains/Analytics/Services/DashboardService.php), [TicketMetrics.php](../backend/app/Domains/Analytics/Services/TicketMetrics.php), [MaintenanceMetrics.php](../backend/app/Domains/Maintenance/Services/MaintenanceMetrics.php), [UserMetrics.php](../backend/app/Domains/Identity/Services/UserMetrics.php) | [DashboardMetricsTest.php](../backend/tests/Feature/Dashboard/DashboardMetricsTest.php) |
| User export | [UsersDashboardPage.tsx](../frontend/src/features/users/pages/UsersDashboardPage.tsx), [usersApi.ts](../frontend/src/features/users/api/usersApi.ts) | [UserExportController.php](../backend/app/Domains/Identity/Http/Controllers/Admin/UserExportController.php) (`GET /api/admin/users/export`), [UserExporter.php](../backend/app/Domains/Identity/Services/UserExporter.php), [UsersExport.php](../backend/app/Domains/Identity/Exports/UsersExport.php) | [UserExportAndDashboardTest.php](../backend/tests/Feature/Users/UserExportAndDashboardTest.php) |

**Not implemented (verified):** no charting library is installed — charts are
the custom components above; there is no asset import, no reports module, and
the user export is the only export. A new export should copy the user-export
pattern (controller → service → export class → a call in `F/<f>/api/`).

### 3.8 Authentication, roles, notifications, realtime

| Feature | Frontend | Backend | Tests |
|---|---|---|---|
| Sign-in, session | [SignInPage.tsx](../frontend/src/features/auth/pages/SignInPage.tsx), [AuthProvider.tsx](../frontend/src/features/auth/AuthProvider.tsx), [useAuth.ts](../frontend/src/features/auth/hooks/useAuth.ts), [authApi.ts](../frontend/src/features/auth/api/authApi.ts) | [AuthController.php](../backend/app/Domains/Identity/Http/Controllers/AuthController.php), [AuthService.php](../backend/app/Domains/Identity/Services/AuthService.php), [LoginThrottle.php](../backend/app/Domains/Identity/Services/LoginThrottle.php), [backend/app/Http/Middleware/AuthenticateSession.php](../backend/app/Http/Middleware/AuthenticateSession.php) | [AuthenticationTest.php](../backend/tests/Feature/Auth/AuthenticationTest.php), [LockoutTest.php](../backend/tests/Feature/Auth/LockoutTest.php) |
| Registration and approval | [RegisterPage.tsx](../frontend/src/features/auth/pages/RegisterPage.tsx), [RegistrationsPage.tsx](../frontend/src/features/users/pages/RegistrationsPage.tsx) | [RegistrationController.php](../backend/app/Domains/Identity/Http/Controllers/RegistrationController.php), [RegistrationReviewController.php](../backend/app/Domains/Identity/Http/Controllers/Admin/RegistrationReviewController.php) | [RegistrationTest.php](../backend/tests/Feature/Auth/RegistrationTest.php), [RegistrationApprovalTest.php](../backend/tests/Feature/Auth/RegistrationApprovalTest.php) |
| Passwords, forced change | [ForgotPasswordPage.tsx](../frontend/src/features/auth/pages/ForgotPasswordPage.tsx), [ForcePasswordChangePage.tsx](../frontend/src/features/auth/pages/ForcePasswordChangePage.tsx), [ChangePasswordFields.tsx](../frontend/src/features/auth/components/ChangePasswordFields.tsx) | [PasswordController.php](../backend/app/Domains/Identity/Http/Controllers/PasswordController.php), [PasswordPolicy.php](../backend/app/Domains/Identity/Rules/PasswordPolicy.php), [backend/app/Http/Middleware/EnsurePasswordIsCurrent.php](../backend/app/Http/Middleware/EnsurePasswordIsCurrent.php) | [PasswordManagementTest.php](../backend/tests/Feature/Auth/PasswordManagementTest.php), [ForcedPasswordResetTechnicianTest.php](../backend/tests/Feature/Auth/ForcedPasswordResetTechnicianTest.php) |
| Roles and permissions | [RequireRole.tsx](../frontend/src/features/auth/guards/RequireRole.tsx), [RequirePermission.tsx](../frontend/src/features/auth/guards/RequirePermission.tsx), [PermissionMatrix.tsx](../frontend/src/features/users/components/PermissionMatrix.tsx) | [backend/database/seeders/RoleSeeder.php](../backend/database/seeders/RoleSeeder.php), [backend/database/seeders/PermissionSeeder.php](../backend/database/seeders/PermissionSeeder.php), [PermissionResolver.php](../backend/app/Domains/Identity/Services/PermissionResolver.php), `can:` middleware in [backend/routes/api.php](../backend/routes/api.php), each `B/<Domain>/Policies/` | [AuthorizationTest.php](../backend/tests/Feature/Auth/AuthorizationTest.php), [UserRoleAndPermissionsTest.php](../backend/tests/Feature/Users/UserRoleAndPermissionsTest.php) |
| User management | [UsersDashboardPage.tsx](../frontend/src/features/users/pages/UsersDashboardPage.tsx), [UserDetailPage.tsx](../frontend/src/features/users/pages/UserDetailPage.tsx) | [UserController.php](../backend/app/Domains/Identity/Http/Controllers/Admin/UserController.php), [UserLifecycleController.php](../backend/app/Domains/Identity/Http/Controllers/Admin/UserLifecycleController.php), [UserPolicy.php](../backend/app/Domains/Identity/Policies/UserPolicy.php) | `T/Users/` |
| Notifications | [NotificationBell.tsx](../frontend/src/features/notifications/components/NotificationBell.tsx), [NotificationsPage.tsx](../frontend/src/features/notifications/pages/NotificationsPage.tsx), [PreferenceMatrix.tsx](../frontend/src/features/notifications/components/PreferenceMatrix.tsx), [useUserChannel.ts](../frontend/src/features/notifications/hooks/useUserChannel.ts) | [NotificationController.php](../backend/app/Domains/Administration/Http/Controllers/NotificationController.php), [NotificationDispatcher.php](../backend/app/Domains/Administration/Services/NotificationDispatcher.php), `B/Administration/Listeners/` (one listener per trigger), [SendDailyDigest.php](../backend/app/Domains/Administration/Console/SendDailyDigest.php) | `T/Notifications/` |
| Announcements | [AnnouncementsPage.tsx](../frontend/src/features/announcements/pages/AnnouncementsPage.tsx), [AnnouncementManagementPage.tsx](../frontend/src/features/announcements/pages/AnnouncementManagementPage.tsx) | [AnnouncementController.php](../backend/app/Domains/Administration/Http/Controllers/AnnouncementController.php), [ManageAnnouncement.php](../backend/app/Domains/Administration/Actions/ManageAnnouncement.php) | `T/Announcements/` |
| Realtime | [frontend/src/services/echo.ts](../frontend/src/services/echo.ts), [frontend/src/hooks/usePrivateChannel.ts](../frontend/src/hooks/usePrivateChannel.ts) | [backend/routes/channels.php](../backend/routes/channels.php), [backend/app/Http/Controllers/BroadcastingConfigController.php](../backend/app/Http/Controllers/BroadcastingConfigController.php), [backend/config/reverb.php](../backend/config/reverb.php), [backend/config/broadcasting.php](../backend/config/broadcasting.php) | [BroadcastingAuthorizationTest.php](../backend/tests/Feature/Broadcasting/BroadcastingAuthorizationTest.php), [frontend/src/services/echo.test.ts](../frontend/src/services/echo.test.ts) |

When a role's permissions change, [CLAUDE.md](../CLAUDE.md) §7 also requires updating the SRS
§8.4 permission matrix.

### 3.9 Backend plumbing

| Concern | Files |
|---|---|
| API routes (one file, grouped by domain, `can:` gates) | [backend/routes/api.php](../backend/routes/api.php) |
| Broadcast channel auth / schedule / web | [backend/routes/channels.php](../backend/routes/channels.php), [backend/routes/console.php](../backend/routes/console.php) (`tickets:close-stale` 02:15, `knowledge:index` 03:10, `maintenance:detect-due` 06:30), [backend/routes/web.php](../backend/routes/web.php) |
| Middleware and aliases | [backend/bootstrap/app.php](../backend/bootstrap/app.php) (`active`, `password.current`), `backend/app/Http/Middleware/` |
| Health endpoint (`/api/health`) | [backend/app/Http/Controllers/HealthController.php](../backend/app/Http/Controllers/HealthController.php) |
| Models and enums | `backend/app/Models/` (flat), `backend/app/Enums/` (varchar + CHECK, not PG enums) |
| Schema and data | `backend/database/migrations/` (46 applied on `:8081` as of the 2026-10-16 release), `backend/database/seeders/`, `backend/database/factories/` |
| Configuration | `backend/config/` ([ai.php](../backend/config/ai.php), [security.php](../backend/config/security.php), [queue.php](../backend/config/queue.php), [reverb.php](../backend/config/reverb.php), …) |
| Test harness | [backend/tests/Pest.php](../backend/tests/Pest.php), [backend/tests/TestCase.php](../backend/tests/TestCase.php), `backend/tests/Support/`, [backend/phpunit.xml](../backend/phpunit.xml) |

### 3.10 Docker, production web and operations

| Concern | Files |
|---|---|
| Stack definition | [compose.prod.yaml](../compose.prod.yaml) |
| PHP image (app, queue, AI, scheduler, reverb) | [docker/php/Dockerfile](../docker/php/Dockerfile) (`production` target), [docker/php/prod-entrypoint.sh](../docker/php/prod-entrypoint.sh), [docker/php/php.prod.ini](../docker/php/php.prod.ini), [docker/php/www.conf](../docker/php/www.conf), [docker/php/fpm-healthcheck.sh](../docker/php/fpm-healthcheck.sh) |
| Web image (built SPA + proxy) | [docker/nginx/Dockerfile.prod](../docker/nginx/Dockerfile.prod), [docker/nginx/prod.conf](../docker/nginx/prod.conf), [docker/nginx/security-headers.prod.conf](../docker/nginx/security-headers.prod.conf) |
| Database first-run | [docker/postgres/init/01-enable-extensions.sql](../docker/postgres/init/01-enable-extensions.sql), [docker/postgres/init/02-create-test-db.sql](../docker/postgres/init/02-create-test-db.sql) |
| Build context filter | [.dockerignore](../.dockerignore) |
| Gate images | [docker/gate/Dockerfile.backend](../docker/gate/Dockerfile.backend), [docker/gate/Dockerfile.frontend](../docker/gate/Dockerfile.frontend), [scripts/lib/isolated-gate.sh](../scripts/lib/isolated-gate.sh), [frontend/scripts/scan-csp.mjs](../frontend/scripts/scan-csp.mjs) (CSP scan of the build) |
| Dependency (lock-file) changes | [scripts/lock-update.sh](../scripts/lock-update.sh) — see [docs/DEVELOPMENT.md](DEVELOPMENT.md#dependencies) |
| Operations | [scripts/deploy-prod.sh](../scripts/deploy-prod.sh), [scripts/rollback-prod.sh](../scripts/rollback-prod.sh), [scripts/releases.sh](../scripts/releases.sh), [scripts/smoke.sh](../scripts/smoke.sh), [scripts/gates.sh](../scripts/gates.sh), [scripts/backup.sh](../scripts/backup.sh), [scripts/verify-backup.sh](../scripts/verify-backup.sh), [scripts/restore.sh](../scripts/restore.sh), [scripts/lib/stack.sh](../scripts/lib/stack.sh), [scripts/lib/snapshot.sh](../scripts/lib/snapshot.sh) — see [docs/OPERATIONS.md](OPERATIONS.md) |

---

## 4. Recipes

- **Add a field to tickets:** a new file in `backend/database/migrations/` →
  [backend/app/Models/Ticket.php](../backend/app/Models/Ticket.php) → [StoreTicketRequest.php](../backend/app/Domains/Tickets/Http/Requests/StoreTicketRequest.php)
  and [UpdateTicketRequest.php](../backend/app/Domains/Tickets/Http/Requests/UpdateTicketRequest.php) → [CreateTicket.php](../backend/app/Domains/Tickets/Actions/CreateTicket.php) /
  [UpdateTicket.php](../backend/app/Domains/Tickets/Actions/UpdateTicket.php) — in `UpdateTicket`, put the field into `$attributes`
  (and `$changes`): an edit whose `$attributes` stay empty returns without
  saving, so a field handled only in validation is silently dropped →
  [TicketDetailResource.php](../backend/app/Domains/Tickets/Http/Resources/TicketDetailResource.php) and the staff-only
  [TicketListResource.php](../backend/app/Domains/Tickets/Http/Resources/TicketListResource.php) (and
  [TicketFeedResource.php](../backend/app/Domains/Tickets/Http/Resources/TicketFeedResource.php) only if the community may see it — the feed is
  privacy-filtered, [TicketCommunityPrivacyTest.php](../backend/tests/Feature/Tickets/TicketCommunityPrivacyTest.php)) →
  [types.ts](../frontend/src/features/tickets/types.ts) and [schemas.ts](../frontend/src/features/tickets/schemas.ts) → the form/page
  ([CreateTicketPage.tsx](../frontend/src/features/tickets/pages/CreateTicketPage.tsx) to report,
  [TicketEditDrawer.tsx](../frontend/src/features/tickets/components/TicketEditDrawer.tsx) to edit) → tests.
- **Add an endpoint:** route inside the matching `can:` group in
  [backend/routes/api.php](../backend/routes/api.php) → controller method → FormRequest → Policy ability →
  Action/Service → Resource → `F/<f>/api/` + `F/<f>/hooks/` → an authorization test
  with the 403/404 cases per role.
- **Add a page:** `F/<f>/pages/NewPage.tsx` → lazy import + `<Route>` with its
  guard in [frontend/src/App.tsx](../frontend/src/App.tsx) → menu entry in
  [frontend/src/layouts/navConfig.ts](../frontend/src/layouts/navConfig.ts) → a `*.test.tsx`.
- **Change what the assistant says or sees:** instructions in
  [AssistantAgent.php](../backend/app/Domains/KnowledgeBase/Agents/AssistantAgent.php), context in
  [AssistantContext.php](../backend/app/Domains/KnowledgeBase/Services/AssistantContext.php), readable data in
  `B/KnowledgeBase/Assistant/Tools/`, scrubbing in
  [PromptRedactor.php](../backend/app/Domains/KnowledgeBase/Services/PromptRedactor.php); run the `T/AI/` tests (they
  never call Gemini — `backend/tests/Support/` provides fakes).
- **Fix a UI bug:** find the URL in [frontend/src/layouts/navConfig.ts](../frontend/src/layouts/navConfig.ts) or
  [frontend/src/App.tsx](../frontend/src/App.tsx) → its page → its components → the shared kit in
  [frontend/src/components/ui/](../frontend/src/components/ui/index.ts).

Then: [`sh scripts/gates.sh`](../scripts/gates.sh) → [`sh scripts/deploy-prod.sh`](../scripts/deploy-prod.sh).

---

## 5. Using Cursor safely

- Open the repository root. Edit only the source rows of §0; leave generated,
  dependency, data and secret paths alone. Never edit inside a container.
- Keep secrets out of AI context: `.env`, `backend/.env.production`,
  `secrets/`, `backups/`. This repository has no `.cursorignore` yet — adding one
  with those paths is a sensible first step.
- Do not let an AI agent run, on its own: [`sh scripts/deploy-prod.sh`](../scripts/deploy-prod.sh),
  [scripts/restore.sh](../scripts/restore.sh) without `--dry-run`, `docker compose ... down -v`,
  `php artisan migrate:fresh`, `git push`. A bare `docker compose up` no longer
  recreates the retired dev stack (its services are profile-gated), but naming
  a service explicitly still would.
- Run scripts in Cursor's terminal with **Git Bash** (they are POSIX `sh`).
  `make` is not installed on this Windows host; the [Makefile](../Makefile) targets are thin
  wrappers around the same scripts.
- Dependencies: production installs from [backend/composer.lock](../backend/composer.lock) and
  [frontend/package-lock.json](../frontend/package-lock.json) at image build. `npm ci` in
  `frontend/` is fine — it installs the lock file exactly and never edits it.
  Do not run `npm install <package>`, `composer require` or `composer update`
  directly: change dependencies only through
  [`sh scripts/lock-update.sh`](../scripts/lock-update.sh) (owner decision D7),
  which regenerates the lock files in a disposable container, then run the gate.
- Line endings: Git runs with `core.autocrlf=true`; let your editor keep each
  file's existing endings.

Related: [OPERATIONS.md](OPERATIONS.md) · [DEVELOPMENT.md](DEVELOPMENT.md) ·
[TESTING.md](TESTING.md) · [DOCKER.md](DOCKER.md) · [ENVIRONMENT.md](ENVIRONMENT.md)
