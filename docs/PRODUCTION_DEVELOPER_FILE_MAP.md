# Production Developer File Map

Where to edit what in SccIT, how the layers connect, and how a change reaches
`http://localhost:8081`. Written for working by hand in an editor such as
Cursor.

> **Scope.** Since 2026-10-10 the **production stack** (`compose.prod.yaml`,
> `-p sccit_prod`, `:8081`) is the only stack; the development stack was
> retired. Every path below was checked against the repository on 2026-10-10
> (commit `0e707ef` plus the working tree). Paths are relative to the
> repository root.

---

## 0. What you can edit — and what you must not

| Kind | Paths | Rule |
|---|---|---|
| **Backend source** | `backend/app/`, `backend/routes/`, `backend/database/`, `backend/config/`, `backend/tests/`, `backend/bootstrap/app.php` | Edit freely; ships on the next deploy |
| **Frontend source** | `frontend/src/`, `frontend/index.html`, `frontend/public/` | Edit freely; ships on the next deploy |
| **Build / tool config** | `frontend/vite.config.ts`, `frontend/tsconfig.app.json`, `frontend/eslint.config.js`, `frontend/.prettierrc.json`, `frontend/package.json`, `backend/composer.json`, `backend/phpstan.neon`, `backend/pint.json`, `backend/phpunit.xml` | Edit with care; dependency changes also need the lock files (§5) |
| **Infrastructure** | `compose.prod.yaml`, `docker/`, `scripts/`, `.github/workflows/ci.yml`, `Makefile` | Changes affect builds and deploys |
| **Docs** | `docs/`, `README.md`, `CLAUDE.md` | SRS/SDD/SPMP are frozen at v1.0 — see `CLAUDE.md` §7 |
| **Generated — do not edit** | `frontend/dist/`, `backend/bootstrap/cache/`, `backend/storage/`, `graphify-out/`, `deliverables/word/`, `docs/_publish/`, `frontend/test-results/`, `frontend/tmp-ally/` | Rebuilt by tools; `frontend/dist/` is a local build, production builds its own inside the image |
| **Dependencies — do not edit** | `backend/vendor/`, `frontend/node_modules/` | Present on the host for editor IntelliSense only |
| **Local data — never commit** | `backups/` | Real rows (password hashes) and uploads — gitignored |
| **Deployment records — commit deliberately** | `releases/` | Not gitignored: `releases/current` and `releases/2df17db/manifest.json` are tracked, later manifests are not — decide case by case (`docs/OPERATIONS.md` §3) |
| **Secrets — never commit or paste** | `.env`, `backend/.env.production`, `secrets/gemini_api_key.txt` | Gitignored; templates: `.env.example`, `backend/.env.production.example` |
| **Retired** | `compose.yaml`, `docker/node/`, `docker/nginx/default.conf`, `docker/nginx/security-headers.dev.conf`, `backend/.env` | Dev stack only; kept for reference and CI's opt-in browser job |

**Inside the containers, not on disk:** `sccit_prod_app` runs a *copy* of
`backend/` baked into the image at `/var/www/html`; `sccit_prod_nginx` serves a
*built* SPA from `/usr/share/nginx/html`; config/route/event caches are built
when the container starts (`docker/php/prod-entrypoint.sh`); the database lives
in `sccit_prod_pgdata`, public files in the `sccit_prod_storage` volume
(`/var/www/html/storage/app/public`) and private attachments in the
`sccit_prod_private` volume (`/var/www/html/storage/app/private`). Editing a
file inside a container is lost on the next deploy — always edit the source.

> **Private attachments.** Ticket attachments, repair photos, work-support and
> asset files are stored on the `local` disk (e.g.
> `backend/app/Domains/Tickets/Actions/AttachTicketFile.php`), which since
> 2026-10-11 is the `sccit_prod_private` volume — mounted on `app_prod`,
> `queue_prod`, `queue_ai_prod` and `scheduler_prod`, never on `nginx_prod`, so
> the files are reachable only through the authorizing controllers. They
> survive deploys and rollbacks and are in every snapshot (`private.tar.gz`).
> Before that date the disk was each container's own layer; no file had been
> uploaded, so nothing was lost.

---

## 1. How a change reaches `http://localhost:8081`

There is no hot reload. Production is rebuilt from your working tree.

1. **Edit** source (§3 tells you where).
2. **Gate** — `sh scripts/gates.sh` runs Pint, PHPStan, Pest, tsc, ESLint,
   Prettier, Vitest and the production build in a disposable environment with
   its own PostgreSQL and Redis. `--only backend` / `--only frontend` to narrow.
3. **Deploy** — `sh scripts/deploy-prod.sh`: gates → restore-verified snapshot →
   build and tag images → recreate services → `migrate --force` → smoke test. It
   asks before changing anything and refuses a dirty tracked tree unless you
   pass `--allow-dirty`; the image contains your **uncommitted** changes too.
4. **Verify** with the read-only commands below; roll back with
   `sh scripts/rollback-prod.sh <sha>` (`docs/OPERATIONS.md` §3).

**Safe, read-only checks** (always `-p sccit_prod -f compose.prod.yaml`):

```bash
docker compose -p sccit_prod -f compose.prod.yaml ps
curl -s http://localhost:8081/api/health        # {"status":"healthy","checks":{"app":"ok","database":"ok","redis":"ok"},...}
docker compose -p sccit_prod -f compose.prod.yaml logs --tail=100 app_prod
docker compose -p sccit_prod -f compose.prod.yaml exec -T app_prod php artisan migrate:status
docker compose -p sccit_prod -f compose.prod.yaml exec -T app_prod php artisan route:list --path=api/tickets
sh scripts/smoke.sh --stack prod                # HTTP smoke; makes one deliberately failed login
```

**Changes data or services — only on purpose:** `scripts/deploy-prod.sh`,
`scripts/rollback-prod.sh`, `scripts/restore.sh` (without `--dry-run`),
`make prod-setup` (migrate + seed), `scripts/verify-ticket-roles.sh` and
`scripts/verify-maintenance-roles.sh` (create records through the API), and any
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

`Domains/<D>` means `backend/app/Domains/<Domain>/`. Realtime adds one more
path: a backend **event** → `backend/routes/channels.php` (who may listen) →
Reverb (`reverb_prod`, proxied at `/reverb/app/`) → `frontend/src/services/echo.ts`
→ a feature's channel hook.

**Which layers a change touches:**

| Change | Touch |
|---|---|
| New column shown in the UI | migration → model (`$fillable`/casts) → FormRequest → Action/Service → Resource → `types.ts`/`schemas.ts` → component → tests |
| New endpoint | route (in the right `can:` group) → controller → FormRequest → Policy → Action/Service → Resource → `F/<f>/api/` + `F/<f>/hooks/` → an authorization test (negative cases per role, `CLAUDE.md` §6) |
| New page | `F/<f>/pages/` component → lazy route + guard in `frontend/src/App.tsx` → menu entry in `frontend/src/layouts/navConfig.ts` → test |
| Who may do something | route `can:` middleware → Policy → `RequirePermission`/`RequireRole` in `App.tsx` → `navConfig.ts` → authorization tests |
| Pure UI fix | the page/component only (+ its `*.test.tsx`) |

---

## 3. Feature map

`F` = `frontend/src/features/`, `B` = `backend/app/Domains/`, `T` = `backend/tests/Feature/`.
Full paths are given the first time; tables use these prefixes.

### 3.1 App shell and shared frontend

| Concern | Files |
|---|---|
| Entry | `frontend/index.html`, `frontend/src/main.tsx` |
| Routing (every URL → page, with guards) | `frontend/src/App.tsx` |
| Layouts | `frontend/src/layouts/AppLayout.tsx` (signed-in shell), `frontend/src/layouts/AuthLayout.tsx`, `frontend/src/layouts/PublicLayout.tsx` |
| Navigation per role | `frontend/src/layouts/navConfig.ts` |
| Route guards | `frontend/src/features/auth/guards/` (`ProtectedRoute`, `GuestRoute`, `RequireRole`, `RequirePermission`); `frontend/src/features/floor-plan/guards/RequireFloorPlan.tsx`; `frontend/src/features/predictions/guards/RequirePredictions.tsx` |
| UI kit | `frontend/src/components/ui/` (Button, Table, Drawer, Modal, StatCard, Tabs, …; barrel `index.ts`) |
| Styling and theme | `frontend/src/index.css` (Tailwind 4), `frontend/src/lib/cn.ts`, `frontend/src/lib/theme.ts`, `frontend/src/stores/useThemeStore.ts`; design tokens `.impeccable/design.json`, `DESIGN.md` |
| API client | `frontend/src/services/api.ts`, `frontend/src/services/queryClient.ts`, `frontend/src/services/lookups.ts` |
| Realtime client | `frontend/src/services/echo.ts`, `frontend/src/hooks/usePrivateChannel.ts`, `frontend/src/stores/useRealtimeStore.ts` |
| Public site | `frontend/src/pages/LandingPage.tsx`, `frontend/src/components/marketing/`, `frontend/src/pages/SystemStatusPage.tsx` |
| Test setup | `frontend/src/test/setup.ts`; Vitest is configured in `frontend/vite.config.ts` |

### 3.2 Dashboards, navigation and accounts

| Feature | Frontend (`F`) | Backend (`B`) | Tests |
|---|---|---|---|
| Role switch | `F/dashboard/pages/DashboardPage.tsx` | `B/Analytics/Http/Controllers/DashboardController.php` | `T/Dashboard/DashboardRoleTest.php` |
| Admin dashboard | `F/dashboard/pages/AdminDashboard.tsx`, `F/dashboard/components/` (KpiRow, DistributionMeter, AdminTicketQueue, LiveStatus), `F/dashboard/hooks/useAdminDashboardChannel.ts` | `B/Analytics/Services/DashboardService.php`, `B/Analytics/Services/TicketMetrics.php`, `B/Analytics/Services/AssetMetrics.php`, `B/Analytics/Events/AdminDashboardChanged.php` | `T/Dashboard/DashboardMetricsTest.php`, `T/Dashboard/AdminDashboardRealtimeTest.php`, `frontend/src/features/dashboard/components/DistributionMeter.test.tsx` |
| Teacher dashboard | `F/dashboard/pages/TeacherDashboard.tsx` | same service, role-shaped payload | `T/Dashboard/DashboardRoleTest.php` |
| Technician dashboard | `F/dashboard/pages/TechnicianDashboard.tsx`, `F/dashboard/components/ScanGuide.tsx` | same | `T/Dashboard/DashboardRoleTest.php` |
| Technician monitoring (admin) | `F/technicians/pages/TechniciansPage.tsx`, `F/technicians/pages/TechnicianDetailPage.tsx` | `B/Analytics/Http/Controllers/Admin/TechnicianMonitoringController.php`, `B/Analytics/Services/TechnicianMonitoring.php` | `T/Dashboard/TechnicianMonitoringTest.php` |
| Account page (`/app/account`) | `F/auth/pages/WorkspaceHomePage.tsx` | `B/Identity/Http/Controllers/ProfileController.php` | `T/Auth/ProfileTest.php` |

Menu entries per role (teacher / technician / administrator) are all in
`frontend/src/layouts/navConfig.ts`.

### 3.3 Tickets

| Feature | Frontend (`F/tickets/`) | Backend (`B/Tickets/`) | Tests (`T/Tickets/`) |
|---|---|---|---|
| Report a fault | `F/tickets/pages/CreateTicketPage.tsx`, `F/tickets/components/DuplicateSuggestions.tsx`, `F/tickets/components/PcAlreadyReported.tsx`, `F/tickets/schemas.ts` | `B/Tickets/Http/Controllers/TicketController.php`, `B/Tickets/Http/Requests/StoreTicketRequest.php`, `B/Tickets/Actions/CreateTicket.php`, `B/Tickets/Services/DuplicateFinder.php` | `T/Tickets/TicketWorkflowTest.php`, `T/Tickets/TicketLocationPriorityTest.php` |
| My tickets, detail, history tab | `F/tickets/pages/MyTicketsPage.tsx`, `F/tickets/pages/TicketDetailPage.tsx`, `F/tickets/components/TicketTimeline.tsx` | `TicketController.php`, `B/Tickets/Services/TicketVisibility.php` (row scoping), `B/Tickets/Http/Controllers/TicketTimelineController.php` | `T/Tickets/TicketVisibilityTest.php`, `T/Tickets/TicketApiAuthorizationTest.php` |
| Community feed | `F/tickets/pages/TicketFeedPage.tsx`, `F/tickets/components/CommunityFeedPanel.tsx` | `B/Tickets/Http/Controllers/TicketFeedController.php`, `B/Tickets/Http/Resources/TicketFeedResource.php` | `T/Tickets/TicketCommunityPrivacyTest.php` |
| Upvotes | `F/tickets/components/VoteButton.tsx` | `B/Tickets/Http/Controllers/TicketParticipationController.php` (`vote`), `B/Tickets/Actions/ToggleTicketVote.php`, `backend/app/Models/TicketVote.php` | `T/Tickets/TicketWorkflowTest.php` |
| Comments | `F/tickets/components/TicketComments.tsx` | `TicketParticipationController.php`, `B/Tickets/Actions/ManageTicketComment.php`, `B/Tickets/Policies/TicketCommentPolicy.php`, `B/Tickets/Http/Resources/TicketCommentResource.php` | `T/Tickets/TicketApiAuthorizationTest.php`, `T/Tickets/TicketWorkflowTest.php` |
| Assignment (admin) | `F/tickets/pages/TicketManagementPage.tsx`, `F/tickets/pages/TicketAdminDetailPage.tsx`, `F/tickets/components/AdminTicketActions.tsx` | `B/Tickets/Http/Controllers/Admin/TicketAssignmentController.php`, `B/Tickets/Actions/AssignTicket.php`, `B/Tickets/Policies/TicketAssignmentPolicy.php`, `B/Tickets/Http/Controllers/Admin/TicketDirectoryController.php` | `T/Tickets/TicketAssignmentRaceTest.php` |
| Technician queue (accept, decline, start, hold, complete) | `F/tickets/pages/AssignedTicketsPage.tsx`, `F/tickets/pages/AssignedTicketDetailPage.tsx`, `F/tickets/pages/AssignmentHistoryPage.tsx`, `F/tickets/components/AssignmentActions.tsx` | `B/Tickets/Http/Controllers/TechnicianQueueController.php`, `B/Tickets/Actions/RespondToAssignment.php` | `T/Tickets/TicketWorkflowTest.php` |
| Status workflow | `F/tickets/components/TicketStatusActions.tsx`, `F/tickets/components/TicketBadges.tsx` | `B/Tickets/Actions/ChangeTicketStatus.php`, `B/Tickets/Services/TicketLifecycle.php` | `T/Tickets/TicketLifecycleTest.php` |
| Escalation: "not fixed" | `F/tickets/pages/TicketDetailPage.tsx`, `F/tickets/components/TicketAiTroubleshooting.tsx` | `TicketController.php` (`reportNotFixed`), `B/Tickets/Actions/ReportTicketNotFixed.php`, `B/Tickets/Events/TicketEscalatedByReporter.php`, `B/Tickets/Services/ReporterOutcome.php` | `T/Tickets/TicketEscalationTest.php`, `T/Tickets/TicketReporterOutcomeTest.php` |
| Escalation: SLA | — | `B/Tickets/Console/DetectSlaBreaches.php`, `B/Tickets/Services/SlaCalculator.php`, `B/Tickets/Events/TicketSlaThresholdCrossed.php` | `T/Notifications/SlaDetectionTest.php` |
| Auto-close resolved tickets | — | `B/Tickets/Console/CloseStaleResolvedTickets.php` (scheduled in `backend/routes/console.php`) | — |

Key URLs: `GET /api/tickets/feed`, `POST /api/tickets/{ticket}/vote`,
`GET|POST /api/tickets/{ticket}/comments`, `PUT /api/tickets/{ticket}/status`,
`POST /api/tickets/{ticket}/not-fixed`, `/api/tickets/assigned/...` — all in
`backend/routes/api.php`. Models: `backend/app/Models/Ticket.php`,
`TicketComment.php`, `TicketStatusHistory.php`, `TechnicianAssignment.php`.

### 3.4 Assets, PC units, locations, floor plan

| Feature | Frontend | Backend | Tests |
|---|---|---|---|
| Asset register | `F/assets/pages/AssetsPage.tsx`, `F/assets/pages/AssetDetailPage.tsx`, `F/assets/pages/AssetDashboardPage.tsx`, `F/assets/components/` | `B/Assets/Http/Controllers/Admin/AssetController.php`, `B/Assets/Http/Controllers/Admin/AssetActionController.php`, `B/Assets/Services/AssetLifecycle.php`, `B/Assets/Policies/AssetPolicy.php`, `backend/app/Models/Asset.php` | `T/Assets/AssetCrudTest.php`, `T/Assets/AssetLifecycleTest.php`, `T/Assets/AssetAuthorizationTest.php` |
| PC units and specs | `F/assets/pages/PcUnitDetailPage.tsx`, `F/assets/components/PcUnitsTable.tsx`, `F/assets/components/PcUnitFormDrawer.tsx`, `F/assets/components/SpecificationEditor.tsx` | `B/Assets/Http/Controllers/Admin/PcUnitController.php`, `B/Assets/Actions/CreatePcUnit.php`, `B/Assets/Actions/UpsertPcSpecification.php`, `B/Assets/Policies/PcUnitPolicy.php`, `backend/app/Models/PcUnit.php`, `backend/app/Enums/PcStatus.php` | `T/Assets/PcUnitTest.php`, `T/Assets/PcUnitMaintenanceTest.php` |
| My assets (custodian) | `F/assets/pages/MyAssetsPage.tsx` | `AssetController.php` (`mine`, `GET /api/assets`) | `T/Assets/CustodianAssetAccessTest.php` |
| Attachments, history | `F/assets/components/AttachmentGallery.tsx`, `F/assets/components/AssetHistoryTimeline.tsx` | `B/Assets/Http/Controllers/Admin/AssetAttachmentController.php`, `B/Assets/Http/Controllers/Admin/AssetHistoryController.php`, `backend/app/Support/Attachments/AttachmentSecurity.php` | `T/Assets/AssetAttachmentTest.php`, `T/Assets/AssetHistoryTest.php` |
| QR codes (admin) | `F/assets/components/QrCodePanel.tsx` | `B/Assets/Http/Controllers/Admin/QrCodeController.php`, `B/Assets/Services/QrService.php` | `T/Assets/QrCodeTest.php`, `frontend/src/features/assets/components/QrCodePanel.test.tsx` |
| Locations (buildings, floors, rooms) | `F/locations/pages/LocationsPage.tsx`, `F/locations/pages/BuildingDetailPage.tsx`, `F/locations/pages/RoomDetailPage.tsx` | `B/Locations/Http/Controllers/Admin/BuildingController.php`, `B/Locations/Http/Controllers/Admin/FloorController.php`, `B/Locations/Http/Controllers/Admin/RoomController.php`, `B/Locations/Services/LocationArchiver.php` | `T/Locations/` |
| Floor plan | `F/floor-plan/pages/FloorPlanPage.tsx`, `F/floor-plan/pages/FloorPlanRoomPage.tsx`, `F/floor-plan/components/FloorPlanCanvas.tsx`, `F/floor-plan/components/CreateLayoutForm.tsx`, `F/floor-plan/stores/useViewportStore.ts`, `F/floor-plan/hooks/useFloorPlanChannel.ts` | `B/FloorPlan/Http/Controllers/Admin/FloorPlanController.php`, `B/FloorPlan/Http/Controllers/Admin/RoomLayoutController.php`, `B/FloorPlan/Http/Controllers/Admin/FloorPlanPositionController.php`, `B/FloorPlan/Services/FloorPlanService.php`, `B/FloorPlan/README.md` | `T/FloorPlan/`, `backend/tests/Unit/FloorPlan/`, `*.test.tsx` under `F/floor-plan/` |

### 3.5 QR verification, technician work, maintenance

| Feature | Frontend | Backend | Tests |
|---|---|---|---|
| QR scan → panel (`/qr/:code`) | `F/qr/pages/ScanLandingPage.tsx`, `F/qr/pages/ScanPanelPage.tsx`, `F/qr/lib/pendingScan.ts`, `F/qr/components/ScanRefusalNotice.tsx` | `B/Assets/Http/Controllers/QrScanController.php`, `B/Assets/Actions/RecordQrScan.php`, `B/Assets/Services/QrScanResolver.php`, `B/Assets/Services/ScannedPcAccess.php` | `T/Qr/QrScanTest.php`, `T/Qr/QrPanelAuthorizationTest.php`, `T/Qr/QrRateLimitTest.php` |
| Proof of work | `F/qr/components/ProofOfWorkForm.tsx` | `B/Maintenance/Http/Controllers/QrProofOfWorkController.php`, `B/Maintenance/Actions/SubmitProofOfWork.php`, `B/Maintenance/Services/ScannedWorkTargets.php` | `T/Qr/ProofOfWorkTest.php`, `T/Qr/ProofOfWorkIdempotencyTest.php`, `T/Qr/QrTicketBindingAndFreshnessTest.php` |
| Work-support requests | `F/work-support/pages/MySubmissionsPage.tsx`, `F/work-support/pages/SupportRequestInboxPage.tsx`, `F/work-support/components/RaiseSupportRequestForm.tsx` | `B/WorkSupport/Http/Controllers/WorkSupportRequestController.php`, `B/WorkSupport/Http/Controllers/Admin/WorkSupportRequestDecisionController.php`, `B/WorkSupport/Services/WorkSupportRequestLifecycle.php` | `T/WorkSupport/` |
| Maintenance records | `F/maintenance/pages/MaintenanceManagementPage.tsx`, `F/maintenance/pages/MaintenanceDetailPage.tsx`, `F/maintenance/pages/CreateMaintenancePage.tsx`, `F/maintenance/pages/ScheduledMaintenancePage.tsx`, `F/maintenance/pages/MaintenanceHistoryPage.tsx` | `B/Maintenance/Http/Controllers/MaintenanceRecordController.php`, `B/Maintenance/Services/MaintenanceLifecycle.php`, `B/Maintenance/Services/MaintenanceVisibility.php`, `B/Maintenance/Policies/MaintenanceRecordPolicy.php` | `T/Maintenance/` |
| Technician maintenance queue | `F/maintenance/pages/MaintenanceQueuePage.tsx` | `B/Maintenance/Http/Controllers/MaintenanceQueueController.php` | `T/Maintenance/` |
| Checklists, evidence, parts | `F/maintenance/components/ChecklistPanel.tsx`, `F/maintenance/components/RepairEvidenceGallery.tsx`, `F/maintenance/components/HardwareReplacementPanel.tsx` | `B/Maintenance/Http/Controllers/MaintenanceChecklistController.php`, `B/Maintenance/Http/Controllers/MaintenanceEvidenceController.php`, `B/Maintenance/Http/Controllers/HardwareReplacementController.php` | `T/Maintenance/TechnicianRepairRecordTest.php` |
| Preventive maintenance due | — | `B/Maintenance/Console/DetectDuePreventiveMaintenance.php` (scheduled in `backend/routes/console.php`) | `T/Notifications/NotificationTriggerTest.php`, `T/Maintenance/MaintenanceWorkflowTest.php` |

### 3.6 AI (Gemini)

| Feature | Frontend | Backend | Tests (`T/AI/`) |
|---|---|---|---|
| Assistant chat | `F/assistant/components/AssistantLauncher.tsx`, `F/assistant/components/AssistantPanel.tsx`, `F/assistant/hooks/assistant.ts`, `F/assistant/api/assistantApi.ts` | `B/KnowledgeBase/Http/Controllers/AssistantController.php`, `B/KnowledgeBase/Services/AssistantService.php`, `B/KnowledgeBase/Agents/AssistantAgent.php` (instructions) | `T/AI/AssistantTest.php`, `frontend/src/features/assistant/components/AssistantLauncher.test.tsx` |
| What the assistant may read | — | `B/KnowledgeBase/Services/AssistantContext.php`, `B/KnowledgeBase/Services/AssistantToolbox.php`, `B/KnowledgeBase/Assistant/Tools/` (one read-only tool per data source), `B/KnowledgeBase/Assistant/ReadOnlyAssistantTool.php` | `T/AI/AssistantToolsTest.php` |
| Context, redaction, safety | — | `B/KnowledgeBase/Services/PromptRedactor.php`, `B/KnowledgeBase/Services/PromptGuard.php`, `B/KnowledgeBase/Services/SafeAgentInvoker.php` | `T/AI/AssistantDataSafetyTest.php`, `T/AI/PromptGuardTest.php`, `T/AI/SafeAgentInvokerTest.php`, `backend/tests/Unit/PromptRedactorTest.php` |
| Gemini provider, key, rate limits | — | `backend/config/ai.php`, `B/KnowledgeBase/Services/GeminiCredential.php` (reads `/run/secrets/gemini_api_key`, mounted from `secrets/gemini_api_key.txt` by `compose.prod.yaml`), `B/KnowledgeBase/Services/GeminiGovernor.php`, `B/KnowledgeBase/Providers/AiServiceProvider.php` | `T/AI/GeminiCredentialTest.php`, `T/AI/GeminiConcurrencyTest.php`, `T/AI/CheckAiConnectionTest.php` |
| AI settings (admin) | `F/knowledge-base/pages/AiSettingsPage.tsx`, `F/knowledge-base/api/aiSettingsApi.ts` | `B/KnowledgeBase/Http/Controllers/Admin/AiSettingsController.php`, `B/KnowledgeBase/Services/AiSettings.php` | `T/AI/AiSettingsTest.php`, `T/AI/AiSettingsAdministrationTest.php` |
| Ticket pre-screening, priority | `F/tickets/components/TicketAiTroubleshooting.tsx` | `B/KnowledgeBase/Agents/TicketPreScreeningAgent.php`, `B/KnowledgeBase/Jobs/AnalyzeTicketJob.php`, `B/KnowledgeBase/Services/PriorityRecommendationPolicy.php`, `B/Tickets/Http/Controllers/TicketAiAnalysisController.php` | `T/AI/AnalyzeTicketJobTest.php`, `T/AI/AiPriorityRecommendationTest.php`, `T/Tickets/TicketAiAnalysisEndpointTest.php` |
| Knowledge base (articles, embeddings) | no dedicated page — API under `/api/knowledge` | `B/KnowledgeBase/Http/Controllers/KnowledgeArticleController.php`, `B/KnowledgeBase/Services/KnowledgeIndexer.php`, `B/KnowledgeBase/Services/KnowledgeRetriever.php`, `B/KnowledgeBase/Services/EmbeddingGenerator.php`, `B/KnowledgeBase/Console/IndexKnowledgeArticles.php`, `B/KnowledgeBase/README.md` | `T/AI/KnowledgeArticleApiTest.php`, `T/AI/KnowledgeRetrievalTest.php`, `backend/tests/Unit/KnowledgeChunkerTest.php` |
| Predictive maintenance | `F/predictions/pages/PredictionsListPage.tsx`, `F/predictions/pages/PredictionDetailPage.tsx` | `B/KnowledgeBase/Agents/PcFailurePredictionAgent.php`, `B/KnowledgeBase/Jobs/AnalyzePcHistoryJob.php`, `B/KnowledgeBase/Services/PcRiskAssessor.php`, `B/KnowledgeBase/Services/PcPredictionDecision.php`, `B/KnowledgeBase/Http/Controllers/Admin/AiPredictionController.php` | `T/AI/PcPredictionTest.php`, `T/AI/PcPredictionReviewTest.php`, `T/AI/PcPredictionAuthorizationTest.php` |

AI jobs run on the `ai` queue (`queue_ai_prod`, see `compose.prod.yaml`); the
Gemini key is never in `.env` or a config cache.

### 3.7 Reports, charts, exports, imports

| Feature | Frontend | Backend | Tests |
|---|---|---|---|
| Dashboard charts and KPIs | `F/dashboard/components/KpiRow.tsx`, `F/dashboard/components/DistributionMeter.tsx`, `F/dashboard/components/WidgetCard.tsx`, `F/tickets/components/TicketMetricsCards.tsx`, `F/assets/components/AssetMetricsCards.tsx` | `B/Analytics/Services/DashboardService.php`, `B/Analytics/Services/TicketMetrics.php`, `B/Maintenance/Services/MaintenanceMetrics.php`, `B/Identity/Services/UserMetrics.php` | `T/Dashboard/DashboardMetricsTest.php` |
| User export | `F/users/pages/UsersDashboardPage.tsx`, `F/users/api/usersApi.ts` | `B/Identity/Http/Controllers/Admin/UserExportController.php` (`GET /api/admin/users/export`), `B/Identity/Services/UserExporter.php`, `B/Identity/Exports/UsersExport.php` | `T/Users/UserExportAndDashboardTest.php` |

**Not implemented (verified):** no charting library is installed — charts are
the custom components above; there is no asset import, no reports module, and
the user export is the only export. A new export should copy the user-export
pattern (controller → service → export class → a call in `F/<f>/api/`).

### 3.8 Authentication, roles, notifications, realtime

| Feature | Frontend | Backend | Tests |
|---|---|---|---|
| Sign-in, session | `F/auth/pages/SignInPage.tsx`, `F/auth/AuthProvider.tsx`, `F/auth/hooks/useAuth.ts`, `F/auth/api/authApi.ts` | `B/Identity/Http/Controllers/AuthController.php`, `B/Identity/Services/AuthService.php`, `B/Identity/Services/LoginThrottle.php`, `backend/app/Http/Middleware/AuthenticateSession.php` | `T/Auth/AuthenticationTest.php`, `T/Auth/LockoutTest.php` |
| Registration and approval | `F/auth/pages/RegisterPage.tsx`, `F/users/pages/RegistrationsPage.tsx` | `B/Identity/Http/Controllers/RegistrationController.php`, `B/Identity/Http/Controllers/Admin/RegistrationReviewController.php` | `T/Auth/RegistrationTest.php`, `T/Auth/RegistrationApprovalTest.php` |
| Passwords, forced change | `F/auth/pages/ForgotPasswordPage.tsx`, `F/auth/pages/ForcePasswordChangePage.tsx`, `F/auth/components/ChangePasswordFields.tsx` | `B/Identity/Http/Controllers/PasswordController.php`, `B/Identity/Rules/PasswordPolicy.php`, `backend/app/Http/Middleware/EnsurePasswordIsCurrent.php` | `T/Auth/PasswordManagementTest.php`, `T/Auth/ForcedPasswordResetTechnicianTest.php` |
| Roles and permissions | `F/auth/guards/RequireRole.tsx`, `F/auth/guards/RequirePermission.tsx`, `F/users/components/PermissionMatrix.tsx` | `backend/database/seeders/RoleSeeder.php`, `backend/database/seeders/PermissionSeeder.php`, `B/Identity/Services/PermissionResolver.php`, `can:` middleware in `backend/routes/api.php`, each `B/<Domain>/Policies/` | `T/Auth/AuthorizationTest.php`, `T/Users/UserRoleAndPermissionsTest.php` |
| User management | `F/users/pages/UsersDashboardPage.tsx`, `F/users/pages/UserDetailPage.tsx` | `B/Identity/Http/Controllers/Admin/UserController.php`, `B/Identity/Http/Controllers/Admin/UserLifecycleController.php`, `B/Identity/Policies/UserPolicy.php` | `T/Users/` |
| Notifications | `F/notifications/components/NotificationBell.tsx`, `F/notifications/pages/NotificationsPage.tsx`, `F/notifications/components/PreferenceMatrix.tsx`, `F/notifications/hooks/useUserChannel.ts` | `B/Administration/Http/Controllers/NotificationController.php`, `B/Administration/Services/NotificationDispatcher.php`, `B/Administration/Listeners/` (one listener per trigger), `B/Administration/Console/SendDailyDigest.php` | `T/Notifications/` |
| Announcements | `F/announcements/pages/AnnouncementsPage.tsx`, `F/announcements/pages/AnnouncementManagementPage.tsx` | `B/Administration/Http/Controllers/AnnouncementController.php`, `B/Administration/Actions/ManageAnnouncement.php` | `T/Announcements/` |
| Realtime | `frontend/src/services/echo.ts`, `frontend/src/hooks/usePrivateChannel.ts` | `backend/routes/channels.php`, `backend/app/Http/Controllers/BroadcastingConfigController.php`, `backend/config/reverb.php`, `backend/config/broadcasting.php` | `T/Broadcasting/BroadcastingAuthorizationTest.php`, `frontend/src/services/echo.test.ts` |

When a role's permissions change, `CLAUDE.md` §7 also requires updating the SRS
§8.4 permission matrix.

### 3.9 Backend plumbing

| Concern | Files |
|---|---|
| API routes (one file, grouped by domain, `can:` gates) | `backend/routes/api.php` |
| Broadcast channel auth / schedule / web | `backend/routes/channels.php`, `backend/routes/console.php` (`tickets:close-stale` 02:15, `knowledge:index` 03:10, `maintenance:detect-due` 06:30), `backend/routes/web.php` |
| Middleware and aliases | `backend/bootstrap/app.php` (`active`, `password.current`), `backend/app/Http/Middleware/` |
| Health endpoint (`/api/health`) | `backend/app/Http/Controllers/HealthController.php` |
| Models and enums | `backend/app/Models/` (flat), `backend/app/Enums/` (varchar + CHECK, not PG enums) |
| Schema and data | `backend/database/migrations/` (38 applied on `:8081`), `backend/database/seeders/`, `backend/database/factories/` |
| Configuration | `backend/config/` (`ai.php`, `security.php`, `queue.php`, `reverb.php`, …) |
| Test harness | `backend/tests/Pest.php`, `backend/tests/TestCase.php`, `backend/tests/Support/`, `backend/phpunit.xml` |

### 3.10 Docker, production web and operations

| Concern | Files |
|---|---|
| Stack definition | `compose.prod.yaml` |
| PHP image (app, queue, AI, scheduler, reverb) | `docker/php/Dockerfile` (`production` target), `docker/php/prod-entrypoint.sh`, `docker/php/php.prod.ini`, `docker/php/www.conf`, `docker/php/fpm-healthcheck.sh` |
| Web image (built SPA + proxy) | `docker/nginx/Dockerfile.prod`, `docker/nginx/prod.conf`, `docker/nginx/security-headers.prod.conf` |
| Database first-run | `docker/postgres/init/01-enable-extensions.sql`, `docker/postgres/init/02-create-test-db.sql` |
| Build context filter | `.dockerignore` |
| Gate images | `docker/gate/Dockerfile.backend`, `docker/gate/Dockerfile.frontend`, `scripts/lib/isolated-gate.sh` |
| Operations | `scripts/deploy-prod.sh`, `scripts/rollback-prod.sh`, `scripts/releases.sh`, `scripts/smoke.sh`, `scripts/gates.sh`, `scripts/backup.sh`, `scripts/verify-backup.sh`, `scripts/restore.sh`, `scripts/lib/stack.sh`, `scripts/lib/snapshot.sh` — see `docs/OPERATIONS.md` |

---

## 4. Recipes

- **Add a field to tickets:** a new file in `backend/database/migrations/` →
  `backend/app/Models/Ticket.php` → `B/Tickets/Http/Requests/StoreTicketRequest.php`
  and `UpdateTicketRequest.php` → `B/Tickets/Actions/CreateTicket.php` /
  `UpdateTicket.php` → `B/Tickets/Http/Resources/TicketDetailResource.php` (and
  `TicketFeedResource.php` only if the community may see it — the feed is
  privacy-filtered, `T/Tickets/TicketCommunityPrivacyTest.php`) →
  `F/tickets/types.ts` and `F/tickets/schemas.ts` → the form/page → tests.
- **Add an endpoint:** route inside the matching `can:` group in
  `backend/routes/api.php` → controller method → FormRequest → Policy ability →
  Action/Service → Resource → `F/<f>/api/` + `F/<f>/hooks/` → an authorization test
  with the 403/404 cases per role.
- **Add a page:** `F/<f>/pages/NewPage.tsx` → lazy import + `<Route>` with its
  guard in `frontend/src/App.tsx` → menu entry in
  `frontend/src/layouts/navConfig.ts` → a `*.test.tsx`.
- **Change what the assistant says or sees:** instructions in
  `B/KnowledgeBase/Agents/AssistantAgent.php`, context in
  `B/KnowledgeBase/Services/AssistantContext.php`, readable data in
  `B/KnowledgeBase/Assistant/Tools/`, scrubbing in
  `B/KnowledgeBase/Services/PromptRedactor.php`; run the `T/AI/` tests (they
  never call Gemini — `backend/tests/Support/` provides fakes).
- **Fix a UI bug:** find the URL in `frontend/src/layouts/navConfig.ts` or
  `frontend/src/App.tsx` → its page → its components → the shared kit in
  `frontend/src/components/ui/`.

Then: `sh scripts/gates.sh` → `sh scripts/deploy-prod.sh`.

---

## 5. Using Cursor safely

- Open the repository root. Edit only the source rows of §0; leave generated,
  dependency, data and secret paths alone. Never edit inside a container.
- Keep secrets out of AI context: `.env`, `backend/.env.production`,
  `secrets/`, `backups/`. This repository has no `.cursorignore` yet — adding one
  with those paths is a sensible first step.
- Do not let an AI agent run, on its own: `sh scripts/deploy-prod.sh`,
  `scripts/restore.sh` without `--dry-run`, `docker compose ... down -v`,
  `php artisan migrate:fresh`, `git push`. A bare `docker compose up` no longer
  recreates the retired dev stack (its services are profile-gated), but naming
  a service explicitly still would.
- Run scripts in Cursor's terminal with **Git Bash** (they are POSIX `sh`).
  `make` is not installed on this Windows host; the `Makefile` targets are thin
  wrappers around the same scripts.
- Dependencies: production installs from `backend/composer.lock` and
  `frontend/package-lock.json` at image build. Do not run `composer`/`npm
  install` into the repo to "fix" an editor warning — there is currently no
  sanctioned way to change dependencies without the retired dev stack (owner
  decision pending).
- Line endings: Git runs with `core.autocrlf=true`; let your editor keep each
  file's existing endings.

Related: [OPERATIONS.md](OPERATIONS.md) · [DEVELOPMENT.md](DEVELOPMENT.md) ·
[TESTING.md](TESTING.md) · [DOCKER.md](DOCKER.md) · [ENVIRONMENT.md](ENVIRONMENT.md)
