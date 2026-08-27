---
title: Software Project Management Plan (SPMP)
system: AI-Powered IT Asset & Service Management System (SccIT)
doc_id: SCCIT-SPMP
version: 1.0
status: Waiting for Client Approval
date: 2026-07-17
author: Engineering (Beemo)
classification: Internal — Confidential
standard: Aligned with ISO/IEC/IEEE 16326:2019 (Project Management) — successor to IEEE Std 1058
governs: How SccIT is managed across its lifecycle
depends_on: SCCIT-SRS v1.0, SCCIT-SDD v1.0
owner: Client / Product Owner (project owner · system owner · primary decision-maker)
---

# Software Project Management Plan

**AI-Powered IT Asset & Service Management System — codename “SccIT”**

> **Management authority.** This SPMP governs *how the project is planned, executed, controlled, and closed*. The **Software Requirements Specification** (SCCIT-SRS v1.0, approved) defines *what* is built; the **Software Design Description** (SCCIT-SDD v1.0, approved) defines *how it is designed*. This plan references those documents by ID and **does not restate requirements or design**. Where a project detail is not yet fixed, it is recorded as a labelled **Assumption (`SA-nn`)** to be confirmed at plan approval.

---

## Document Control

| Field | Value |
|---|---|
| Document ID | SCCIT-SPMP |
| Version | 1.0 |
| Date | 2026-07-17 |
| Status | **Waiting for Client Approval** |
| Standard | ISO/IEC/IEEE 16326:2019 (and IEEE 1058 heritage) |
| Prepared by | Engineering |
| Owner / decision authority | Client / Product Owner — project owner, system owner, and primary decision-maker |
| Primary inputs | SCCIT-SRS v1.0, SCCIT-SDD v1.0, current repository |
| Related artifacts | `PRODUCT.md`, `DESIGN.md`, `docs/` (DEVELOPMENT, ENVIRONMENT, DOCKER, INSTALLATION, PROJECT_STRUCTURE), `.github/workflows/ci.yml`, `Makefile`, `scripts/`, `compose.yaml` |

### Revision History

| Version | Date | Author | Summary |
|---|---|---|---|
| 1.0 | 2026-07-17 | Engineering | **Version 1.0 baseline.** Consolidated project management plan: purpose and scope, current state, management objectives, deliverables, team roles and responsibilities, lifecycle model, work breakdown structure and work packages, phase plan (**P2** Core · **P3** AI & Knowledge Base · **P4** Interactive Floor Plan & Real-time), milestones, estimation, risk management, quality assurance and verification, configuration and change management, issue management, acceptance, and Appendices A–B. Aligned to **SCCIT-SRS v1.0** and **SCCIT-SDD v1.0**. Status: **Waiting for Client Approval**. |
| 1.0 | 2026-08-26 | Engineering | **Phase 2.5 — Asset Management** delivered against the Version 1.0 baseline (label unchanged). **WP-2.3** and **WP-2.4** marked ✅ done: PC units with the specification editor, serialized assets, the hardware catalog, audited lifecycle transitions, transfers, custodianship, attachments, the unified asset history, QR generate/regenerate/revoke/print, the module dashboard and the enterprise directory. Scope carried forward as **WP-2.4b** (consumables, stock ledger, procurement, disposal, import/export, bulk actions) and **WP-2.4c** (QR scan verification), so the remaining FR-AST/FR-QR requirements stay tracked rather than being absorbed silently. |

### Conventions

- **Identifiers:** deliverables `DL-nn`; milestones `M0..Mn`; work packages `WP-<phase>.<n>`; project/management risks `PR-nn` (product/technical risks are inherited from **[SRS §32](#) `RSK-01..10`**); assumptions `SA-nn`; roles `R-xxx`.
- **Phasing** matches the SRS/SDD exactly: **P2** Core · **P3** AI & Knowledge Base · **P4** Interactive Floor Plan & Real-time · **Future**.
- **Effort sizing** uses relative T-shirt sizes (S ≤ 3 dev-days · M ≤ 1 sprint · L ≈ 1–2 sprints · XL > 2 sprints). Calendar figures are **indicative** and depend on confirmed team capacity ([SA-01](#appendix-b--assumption-register)).
- **Reference markers** `FR-*`, `NFR-*`, `DD-*`, `RES-*`, `RSK-*` point to the identically-named items in the SRS/SDD.

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Project Overview](#2-project-overview)
3. [Objectives](#3-objectives)
4. [Scope](#4-scope)
5. [Deliverables](#5-deliverables)
6. [Stakeholders](#6-stakeholders)
7. [Team Roles and Responsibilities](#7-team-roles-and-responsibilities)
8. [Development Methodology](#8-development-methodology)
9. [Project Organization](#9-project-organization)
10. [Work Breakdown Structure](#10-work-breakdown-structure)
11. [Phase Plan](#11-phase-plan)
12. [Milestones](#12-milestones)
13. [Development Workflow](#13-development-workflow)
14. [Git Workflow](#14-git-workflow)
15. [Branching Strategy](#15-branching-strategy)
16. [Code Review Process](#16-code-review-process)
17. [Documentation Strategy](#17-documentation-strategy)
18. [Testing Strategy](#18-testing-strategy)
19. [Quality Assurance Plan](#19-quality-assurance-plan)
20. [Configuration Management](#20-configuration-management)
21. [Release Management](#21-release-management)
22. [Risk Management](#22-risk-management)
23. [Issue Management](#23-issue-management)
24. [Communication Plan](#24-communication-plan)
25. [Change Management](#25-change-management)
26. [Deployment Strategy](#26-deployment-strategy)
27. [Backup and Recovery Strategy](#27-backup-and-recovery-strategy)
28. [Monitoring Strategy](#28-monitoring-strategy)
29. [Security Management](#29-security-management)
30. [Acceptance Process](#30-acceptance-process)
31. [Maintenance Plan](#31-maintenance-plan)
32. [Future Roadmap](#32-future-roadmap)
33. [Appendix A — Tooling & Command Reference](#appendix-a--tooling--command-reference)
34. [Appendix B — Assumption Register](#appendix-b--assumption-register)
35. [Appendix C — Consistency Review Log](#appendix-c--consistency-review-log)

---

## 1. Executive Summary

SccIT is a production-intended, vertical-agnostic ITSM/ITAM platform. Its **foundation is already built and verified**: a fully Dockerized Laravel 13 + React 19 stack, a production-grade PostgreSQL 17 data layer (66 models, integrity enforced in the database), and a green CI pipeline (phases 0–7). The **requirements (SRS) and design (SDD) are complete and are Waiting for Client Approval.** This SPMP governs the remaining lifecycle: building the application layer across three phases — **P2 Core**, **P3 AI & Knowledge Base**, **P4 Interactive Floor Plan & Real-time** — followed by steady-state maintenance.

The project runs an **iterative, phase-gated** model that matches the established working agreement: work proceeds in short sprints inside each phase, and **each phase ends at a client approval gate** before the next begins. Quality is non-negotiable and largely automated — every change passes the same gates CI already enforces (Pint, Larastan level 6, Pest; ESLint, Prettier, TypeScript, Vitest, build) — and correctness is backstopped by database constraints, so the “done” bar is objective and repeatable.

Management is deliberately lightweight but disciplined: trunk-based Git with short-lived reviewed branches, a WBS decomposed by the SDD’s domains, milestones defined by *acceptance* rather than dates, and a risk register that inherits the SRS’s technical risks and adds project-level ones (schedule, bus-factor, AI-provider dependency, client-gate availability). Because the team size and cadence are not yet fixed, all calendar figures are presented as clearly-labelled assumptions to be confirmed at approval; the *structure* of the plan (sequencing, gates, quality bar, deliverables) is firm.

---

## 2. Project Overview

### 2.1 Context
SccIT replaces manual, fragmented IT operations with a single platform for asset management, service ticketing, preventive maintenance, QR verification, AI-assisted troubleshooting, and analytics (see [SRS §1–§6](#)). It is single-tenant, deployed first for an educational institution, and re-brandable to any organization via settings ([BO-08](#)).

### 2.2 What this plan manages
The build-out of the application layer (P2 → P3 → P4), its verification and release to production, and its ongoing maintenance — governed by the SRS/SDD and executed with the tooling already in the repository.

---

## 3. Objectives

The project succeeds when the specified requirements are delivered to production at the stated quality bar. Management objectives (traceable to [SRS §5 `BO-01..08`](#)):

| ID | Management objective | Measure of success |
|---|---|---|
| PO-1 | Deliver P2 Core to production meeting all P2 acceptance gates. | [SRS §31 AC-G1..G6](#) pass; client sign-off at [M5](#12-milestones). |
| PO-2 | Deliver P3 AI and P4 Floor Plan behind their own gates. | P3/P4 acceptance pass; [M6](#12-milestones)/[M7](#12-milestones) sign-off. |
| PO-3 | Keep quality automated and continuous. | CI green on every merge; ≥ 80% domain test coverage ([NFR-MTN-005](#)). |
| PO-4 | Hold the security & accessibility bar as a release gate, not an afterthought. | Security review + WCAG 2.2 AA audit pass each phase ([SRS §12](#), [§16](#)). |
| PO-5 | Manage risk and change transparently against the current baselines. | Risk register reviewed each sprint; changes go through [§25](#25-change-management). |
| PO-6 | Preserve maintainability and knowledge for the long term. | Docs current in the vault; ADRs recorded; onboarding ≤ 1 day. |

---

## 4. Scope

### 4.1 Management scope of this plan
Planning, execution, monitoring/control, and closure of the application build (P2–P4) plus transition to maintenance. It covers process, people, schedule, quality, risk, configuration, release, and operations — **not** the product requirements (SRS) or the technical design (SDD).

### 4.2 Product scope (by reference)
In-scope and out-of-scope **product** capabilities are defined authoritatively in **[SRS §3](#)**. Summary: P2 delivers the core ITSM/ITAM platform; P3 adds AI/RAG; P4 adds the interactive floor plan and real-time. Explicitly out of scope: ERP/LMS/RMM functions, native mobile apps, and multi-tenancy ([SRS §3.3](#), [CON-08](#)).

### 4.3 Scope control
Scope is baselined by the SRS. Additions or changes follow [§25 Change Management](#25-change-management); the seven open product decisions ([SRS §33 `OI-01..07`](#)) are tracked as pending change items and must be resolved before they affect a phase they touch.

---

## 5. Deliverables

| ID | Deliverable | Phase | Acceptance basis |
|---|---|---|---|
| DL-01 | Software Requirements Specification (SRS) | Docs | Waiting for Client Approval |
| DL-02 | Software Design Description (SDD) | Docs | Waiting for Client Approval |
| DL-03 | Software Project Management Plan (SPMP, this document) | Docs | Waiting for Client Approval |
| DL-04 | P2 Core application (all P2 FRs) + API (OpenAPI) | P2 | [SRS §31](#) P2 gates |
| DL-05 | Design-system component library + accessible SPA shell | P2 | [NFR-USB](#), [NFR-ACC](#) |
| DL-06 | Automated test suites (Pest + Vitest) ≥ 80% domain coverage | P2+ | [NFR-MTN-005](#) |
| DL-07 | Production deployment (staging + prod) + runbooks | P2 | [§26](#26-deployment-strategy) |
| DL-08 | Backup/restore + monitoring operational | P2 | [§27](#27-backup-and-recovery-strategy), [§28](#28-monitoring-strategy) |
| DL-09 | Security review report + WCAG 2.2 AA audit report | each phase | [§29](#29-security-management), [§30](#30-acceptance-process) |
| DL-10 | P3 AI & Knowledge Base (RAG) | P3 | [SRS §17](#) gates |
| DL-11 | P4 Interactive Floor Plan + real-time | P4 | [SRS §19](#) gates |
| DL-12 | User & administrator documentation | P2+ | [§17](#17-documentation-strategy) |
| DL-13 | Maintenance & support handover pack | post-P2 | [§31](#31-maintenance-plan) |
| DL-14 | Updated ADRs and engineering docs in the vault | continuous | [§17](#17-documentation-strategy) |

---

## 6. Stakeholders

Stakeholders and their interests are defined in **[SRS §7](#)**. Management-relevant responsibilities:

| Stakeholder | Project responsibility |
|---|---|
| Client / sponsor | Funds the project; approves phase gates; owns the open product decisions ([SRS §33](#)); provides UAT participants. |
| Product owner (client-side) | Prioritizes backlog within defined scope; signs off acceptance. |
| Engineering team | Delivers, tests, documents, and operates the system ([§7](#7-team-roles-and-responsibilities)). |
| IT Administrators (end org) | UAT, operational readiness, production configuration/branding. |
| Data-protection / security owner | Reviews AI data governance ([SRS §17.5](#)) and security posture. |
| End users (technicians, teachers) | UAT feedback; usability validation. |

---

## 7. Team Roles and Responsibilities

> **[SA-02]** The delivery team is small; one person may hold several roles (the repository currently shows a single primary contributor). Roles below are **functions**, mapped to people at approval. AI pair-assistance (Claude Code) is used under human review — it never bypasses the code-review or acceptance gates.

| Role | Code | Responsibilities |
|---|---|---|
| Project Manager | R-PM | Plan, schedule, risk/issue tracking, client gate coordination, reporting. |
| Developer | R-DEV | Guards SRS/SDD conformance; reviews and owns design decisions and ADRs; final code-review authority. Laravel domains (Services/Actions/Models), migrations, API, jobs; React feature slices, design-system components, accessibility; CI/CD, environments, deployment, backup/monitoring, release cutovers; security reviews, secrets/dependency management, incident response; `DESIGN.md` conformance, usability, WCAG validation; tests for all of the above. |
| QA | R-QA | Test strategy, acceptance test execution, coverage, regression, a11y/perf verification. |

RACI (condensed): R-PM *accountable* for schedule/risk; R-DEV *accountable* for technical conformance; R-QA *accountable* for acceptance verification; client *accountable* for gate approvals. All roles *responsible* for tests and docs of their work.

---

## 8. Development Methodology

### 8.1 Model
**Iterative & incremental, phase-gated**, matching the established working agreement (analyze → approve → plan → approve → implement, verifying each increment; never leave the project broken). This is a Scrum-lite/Kanban hybrid:

- **Phases** (P2/P3/P4) are the major gates; each ends with client acceptance before the next begins.
- **Sprints** of **2 weeks** ([SA-03](#appendix-b--assumption-register)) inside each phase deliver vertical slices (a domain’s API + UI + tests together).
- **Kanban flow** for defects/small tasks between sprint boundaries.

### 8.2 Definition of Ready (a work item may start when)
Requirement ID(s) identified; acceptance criteria known ([SRS §31](#)); design approach clear (SDD reference); dependencies available; test approach agreed.

### 8.3 Definition of Done (a work item is done when)
Code + tests written; **CI green** (all gates); domain coverage target met; API documented (OpenAPI); accessibility checks pass for UI; security-sensitive paths reviewed; docs/ADR updated; peer-reviewed and merged; demonstrated against acceptance criteria.

### 8.4 Cadence
Sprint planning, sprint review/demo, and retrospective per sprint; short async daily status; **phase-gate review with the client** at each milestone ([§24](#24-communication-plan)).

---

## 9. Project Organization

- **Structure:** a single cross-functional delivery team building a modular monolith ([SDD DD-01](#)); no sub-teams needed at current scale.
- **Decision rights:** technical decisions → R-DEV (recorded as ADRs in `vault/08 ADR`, rationale in Claude-Mem per the project’s knowledge model); scope/priority → client product owner; process → R-PM.
- **Environments owned by R-DEV:** development (Docker on the workstation), staging (production-like), production.
- **Knowledge system** (per the project’s operating model): codebase graph = *what*; Claude-Mem = *why*; auto-memory = *how we work*; the Obsidian **vault** = human engineering docs (this plan, SRS, SDD, ADRs).

---

## 10. Work Breakdown Structure

Decomposed by SDD domain. Effort is relative ([SA-01](#appendix-b--assumption-register)). “New” domains are the P2 additions from [SDD RES-01/RES-02](#).

| WP | Work package | Key requirements (SRS) | Effort | Depends on |
|---|---|---|---|---|
| **WP-0** | Foundation (Docker, scaffolds, DB layer, CI) | — | ✅ done | — |
| **WP-1** | Documentation (SRS, SDD, SPMP; resolve `OI-01..07`) | SRS §33 | M (in progress) | — |
| **WP-2.0** | SPA shell + design system components + theming + a11y baseline | NFR-USB, NFR-ACC, DESIGN.md | L | WP-1 |
| **WP-2.1** | **Identity & Access** domain: Sanctum auth, sessions, lockout, RBAC engine, users | FR-AUTH-*, FR-USER-*, NFR-SEC-* | L | WP-2.0 |
| **WP-2.2** | **Locations** domain: buildings/floors/rooms CRUD | FR-LOC-* | ✅ done | WP-2.1 |
| **WP-2.3** | **Assets** pt.1: PC units, specifications, QR generate/print | FR-PC-*, FR-QR-001..004/007 | ✅ done | WP-2.2 |
| **WP-2.4** | **Assets** pt.2: catalog, serialized assets, lifecycle, transfers, custodianship, attachments, history, module dashboard + enterprise directory | FR-AST-001/002/005/006/011/012/013/014/015 | ✅ done | WP-2.3 |
| **WP-2.4b** | **Assets** pt.3 *(carried forward)*: consumables, stock ledger, procurement, disposal workflow, asset import/export, bulk actions | FR-AST-003/004/007/008/009/010 | L | WP-2.4 |
| **WP-2.4c** | **QR scan verification** *(carried forward)*: scan endpoint, deterministic result classification, `qr_scan_logs`, QR-initiated maintenance | FR-QR-005/006/008/009 | M | WP-2.4, WP-2.6 |
| **WP-2.5** | **Tickets** domain: lifecycle, comments, votes, attachments, tags, SLA, duplicates + technician assignment | FR-TKT-*, FR-ASN-* | XL | WP-2.1, WP-2.2 |
| **WP-2.6** | **Maintenance** domain: corrective + preventive, checklists, images, notes, hardware replacements, reminders | FR-MNT-* | L | WP-2.3, WP-2.5 |
| **WP-2.7** | **Administration** pt.1: notifications + preferences + announcements | FR-NOT-* | M | WP-2.1 |
| **WP-2.8** | **Administration** pt.2: system settings + branding | FR-CFG-* | M | WP-2.1 |
| **WP-2.9** | **Administration** pt.3: audit + activity log viewers | FR-AUD-* | S | WP-2.1 |
| **WP-2.10** | **Analytics** domain: dashboards + KPIs + on-demand reports + export | FR-DSH-*, FR-RPT-* | L (role dashboards ✅ done) | WP-2.5, WP-2.4 |
| **WP-2.11** | P2 hardening: security review, WCAG AA audit, perf/load test, backup ops, prod deploy | SRS §12–§16 | L | WP-2.2..2.10 |
| **WP-3.1** | AI provider + `ai_system_settings` + ticket triage (async) | FR-AI-001..003/010/014/015 | L | P2 GA |
| **WP-3.2** | RAG: embedding pipeline + knowledge base + retrieval | FR-AI-005/006/007 | L | WP-3.1 |
| **WP-3.3** | Conversational assistant + feedback + duplicate suggestion | FR-AI-004/008/009 | M | WP-3.2 |
| **WP-3.4** | Predictive maintenance (opt-in, default off) | FR-AI-011/012/013 | M | WP-3.2 |
| **WP-3.5** | P3 hardening: AI data-governance verification, security/a11y, GA | SRS §17.5 | M | WP-3.1..3.4 |
| **WP-4.1** | Reverb + broadcasting infrastructure | FR-FP-007, FR-NOT-007 | M | P2 GA |
| **WP-4.2** | Floor-plan editor: layouts, drag/snap positions, keyboard alt | FR-FP-001/003/009 | L | WP-4.1, WP-2.2/2.3 |
| **WP-4.3** | Live status/position + info panels; P4 hardening + GA | FR-FP-002/004/005/007/008 | L | WP-4.2 |
| **WP-5** | Transition to maintenance: handover, monitoring, restore drill | §31 | M | phase GAs |

---

## 11. Phase Plan

Calendar is **indicative** ([SA-01](#appendix-b--assumption-register)); sequencing and gates are firm. Baseline start: after SPMP approval (≈ 2026-07).

| Phase | Focus | Work packages | Indicative duration | Gate |
|---|---|---|---|---|
| **Docs** | SRS/SDD/SPMP + resolve `OI-01..07` | WP-1 | ~complete | [M0](#12-milestones) |
| **P2 Core** | Full ITSM/ITAM platform to production | WP-2.0 … WP-2.11 | ~10–14 sprints (5–7 mo) | [M2](#12-milestones)–[M5](#12-milestones) |
| **P3 AI/KB** | Gemini triage + RAG assistant + KB | WP-3.1 … WP-3.5 | ~4–6 sprints (2–3 mo) | [M6](#12-milestones) |
| **P4 Floor Plan** | Interactive floor plan + real-time | WP-4.1 … WP-4.3 | ~3–4 sprints (1.5–2 mo) | [M7](#12-milestones) |
| **Maintenance** | Steady-state support & evolution | WP-5 + ongoing | continuous | [M8](#12-milestones) |

**Intra-P2 sequencing:** shell + identity first (WP-2.0/2.1) because every screen needs auth, RBAC, and the design system; then the location foundation (WP-2.2); then value-generating domains (Assets, Tickets, Maintenance) in parallelizable slices; then cross-cutting admin/analytics; then hardening. Each domain slice ships API + UI + tests together (vertical slices), keeping the app demoable every sprint.

**Delivery to date (implementation phases).** The build has been delivered as numbered increments, each ending green and demoable: **2.1** SPA shell + public entry (WP-2.0) · **2.2** authentication & authorization (WP-2.1a) · **2.3** user management (WP-2.1b) · **2.4** Location Management **plus the role-dashboard slice of WP-2.10**, brought forward at the client's request so each role has a purposeful landing surface while the operational domains are built. Reporting and export stay in WP-2.10; per-user dashboard customization (FR-DSH-002/006) is deferred with them. Bringing the dashboards forward carried little risk because they only *read* existing tables — no new schema, and the panels grow richer as each later domain lands.

---

## 12. Milestones

Milestones are **acceptance-defined** (entry/exit criteria), not date-defined.

| ID | Milestone | Exit criteria |
|---|---|---|
| **M0** | Documentation baseline approved | SRS + SDD + SPMP signed off; `OI-01..07` decisions logged. |
| **M1** | App foundation | Auth + RBAC working; SPA shell + design system; a11y baseline; CI green. |
| **M2** | Core inventory | Locations, PC units, QR, assets, consumables, stock — CRUD + tests. |
| **M3** | Service delivery | Ticketing lifecycle + assignment + maintenance (incl. preventive) end-to-end. |
| **M4** | P2 feature-complete | Notifications, settings/branding, audit, dashboards, reporting/export complete. |
| **M5** | **P2 Core GA** | [SRS §31 AC-G1..G6](#) pass; security review + WCAG AA audit + load test pass; UAT sign-off; deployed to production. |
| **M6** | **P3 AI GA** | AI triage + RAG assistant + KB pass [SRS §17](#) acceptance incl. data-governance; graceful degradation verified. |
| **M7** | **P4 Floor Plan GA** | Editor + real-time + info panels pass [SRS §19](#); drag a11y alternative verified. |
| **M8** | Steady state | Handover pack delivered; restore drill passed; maintenance cadence active. |

---

## 13. Development Workflow

The workflow is **already established** in the repo (`docs/DEVELOPMENT.md`) and is adopted as-is:

1. **Environment:** everything runs in Docker; `make up/down/logs/ps`, HMR via Nginx on `:8080`, Mailpit on `:8025`.
2. **Backend feature (intended flow):** create classes under `app/Domains/<Domain>/…`, routes in `routes/api.php`, migrations in `database/migrations/`; run artisan/composer **inside the `app` container**.
3. **Frontend feature:** create a slice under `src/features/<feature>/`, call the API via `src/services/api.ts` (Sanctum cookies automatic).
4. **Verify locally:** `make lint && make test` (Pint + Larastan + ESLint; Pest + Vitest) before opening a PR.
5. **Branch → PR → CI → review → merge** ([§14](#14-git-workflow)–[§16](#16-code-review-process)).

*(Command reference: [Appendix A](#appendix-a--tooling--command-reference).)*

---

## 14. Git Workflow

- **Trunk-based** on `main` with short-lived branches; `main` is always releasable and CI-green.
- **Conventional Commits** (already used in history: `feat:`, `fix:`, `chore:`, `docs:`) for readable history and changelog generation.
- **Every change via Pull Request**; direct pushes to `main` are disallowed (branch protection — [SA-04](#appendix-b--assumption-register)).
- **CI is the merge gate:** the `backend` and `frontend` jobs in `.github/workflows/ci.yml` must pass.
- **Squash-merge** to keep `main` linear; PR title becomes the conventional-commit subject.
- **Release tags** `vX.Y.Z` mark phase GAs and patches ([§21](#21-release-management)).
- **LF line endings** enforced via `.gitattributes`; secrets never committed (`.env*` gitignored).

---

## 15. Branching Strategy

| Branch | Purpose | Lifetime | Merges to |
|---|---|---|---|
| `main` | Single source of truth; always releasable. | permanent | — |
| `feature/<slug>` | New capability (a WBS slice). | short (≤ a few days) | `main` via PR |
| `fix/<slug>` | Bug fix. | short | `main` via PR |
| `chore/<slug>` / `docs/<slug>` | Tooling/config/docs. | short | `main` via PR |
| `release/<x.y>` *(optional)* | Stabilization before a GA if a freeze is needed. | temporary | `main` + tag |
| `hotfix/<slug>` | Urgent production fix. | very short | `main` (+ tag) |

Long-lived divergent branches are avoided (trunk-based). Feature flags / settings toggles (e.g. AI feature switches in `ai_system_settings`, `system_settings`) are preferred over long branches for incomplete features.

---

## 16. Code Review Process

- **Every PR reviewed** by at least one engineer other than the author (R-DEV is the escalation/authority for architecture-affecting changes).
- **Automated first:** CI must be green before human review; reviewers do not spend time on style (Pint/Prettier) or type errors (Larastan/tsc) — the pipeline owns those.
- **Review checklist:** conforms to SRS requirement(s) and SDD design; coding standards ([SDD §33](#)); security-sensitive paths (authz, input, secrets, file upload) scrutinized; accessibility for UI; tests present and meaningful; API documented; no id leakage (uuid-only); DB constraints respected; docs/ADR updated.
- **Tooling assist:** the repo’s code-review tooling may be run on a diff for an extra pass; the `security-review` tooling is run on security-sensitive PRs. These **augment**, never replace, human review.
- **Merge:** author addresses comments; reviewer approves; squash-merge.

---

## 17. Documentation Strategy

Documentation is a first-class, versioned deliverable, homed in the Obsidian **vault** and the repo `docs/`.

| Doc type | Location | Owner | Cadence |
|---|---|---|---|
| SRS / SDD / SPMP | `vault/01 Project`, `vault/02 Architecture` | R-DEV/R-PM | Versioned; change-controlled ([§25](#25-change-management)). |
| ADRs (decision records) | `vault/08 ADR` | R-DEV | Per significant decision. |
| API contract (OpenAPI) | `backend/` (generated) | R-DEV | Kept in sync per PR ([NFR-MTN-006](#)). |
| Developer/ops docs | `docs/` (DEVELOPMENT, ENVIRONMENT, DOCKER, INSTALLATION) | R-DEV | Updated with process/infra changes. |
| User & admin guides | `vault` + delivered docs (DL-12) | R-DEV/R-QA | Per phase GA. |
| Rationale / “why” | Claude-Mem (project knowledge) | team | Continuous. |

**Rule:** a PR that changes behavior updates the relevant doc in the same PR (Definition of Done). Requirements/design docs are **referenced, not copied**, to prevent drift.

---

## 18. Testing Strategy

Realizes [SRS §31 acceptance](#) and [NFR-MTN-001/002/005](#); mirrors the SDD test pyramid ([SDD §32](#)).

| Level | Scope | Tooling | Gate |
|---|---|---|---|
| **Static** | Formatting, style, types, dead code. | Pint `--test`, Larastan L6, ESLint, Prettier `--check`, `tsc -b`. | CI |
| **Unit** | Actions/services, enums, value logic; React hooks/components. | Pest, Vitest + Testing Library. | CI |
| **Integration/Feature** | HTTP endpoints, authorization matrix, DB constraint behavior (negative CHECK/trigger cases), Sanctum auth flows. | Pest (Postgres `*_test` DB), pest-plugin-laravel. | CI |
| **Contract** | API responses match OpenAPI; uuid-only; error envelope. | Pest resource tests. | CI |
| **Accessibility** | WCAG 2.2 AA, both themes, keyboard/SR. | automated axe-style scan + manual. | phase gate |
| **Performance/Load** | Meets [SRS §13–§14](#) targets at nominal load. | load simulation + query-plan analysis. | phase gate |
| **Security** | [SRS §12](#) controls; dependency + secret checks. | security review + scanners. | phase gate |
| **UAT** | Business acceptance by client users. | scripted scenarios ([SRS §26–§27](#)). | phase gate |

- **Coverage target:** ≥ 80% line coverage of domain logic for P2 ([NFR-MTN-005](#)); tracked in CI.
- **Test data:** factories (66) + seeders; the **DemoSeeder never runs in production**.
- **Backend tests** run against the dedicated `school_it_service_management_test` DB (`phpunit.xml`), identical to CI.

---

## 19. Quality Assurance Plan

- **Prevention over detection:** the same gates run locally (`make lint && make test`) and in CI on every push/PR to `main` — no change merges without them.
- **Objective “done” bar:** [Definition of Done §8.3](#83-definition-of-done-a-work-item-is-done-when) + [SRS §31 global gates AC-G1..G6](#).
- **Database as a QA backstop:** integrity is enforced by FK/CHECK/partial-unique/trigger constraints ([SDD §8](#), [DR-002/005/012](#)), so whole classes of defects are impossible to merge.
- **Quality metrics tracked per sprint:** CI pass rate, coverage %, open-defect count by severity, escaped-defect count, a11y violations (target 0), performance p95 vs targets.
- **Phase quality gates:** security review + WCAG AA audit + load test + UAT must pass before a phase GA (WP-*.hardening packages).
- **Continuous dependency hygiene:** `composer.lock`/`package-lock.json` pinned; periodic update + vulnerability scan ([§29](#29-security-management)).

---

## 20. Configuration Management

- **Source control:** Git; `main` protected; tagged releases ([§14](#14-git-workflow), [§21](#21-release-management)).
- **Environment configuration:** `.env` (root, Docker) and `backend/.env` (Laravel) from tracked `*.example` templates; **real `.env*` are gitignored** (`docs/ENVIRONMENT.md`). Key production settings: `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`.
- **Secrets:** never in Git, DB, logs, or client bundle ([NFR-SEC-005](#)); dev uses weak defaults, **production uses a secrets manager and a least-privilege DB role** (`docs/ENVIRONMENT.md` security notes). `APP_KEY` generated per environment.
- **Dependency management:** lockfiles committed; Composer/npm versions pinned; images pinned by tag (`pgvector/pgvector:pg17`, `redis:7-alpine`, `nginx:1.27-alpine`, `php:8.4-fpm`).
- **Database schema:** the **only** mechanism for schema change is a migration; schema changes are change-controlled ([CON-02](#), [§25](#25-change-management)) and verified via `migrate:fresh`→`rollback`→`migrate`.
- **Infrastructure as code:** `compose.yaml` + `docker/*/Dockerfile` (multi-stage: development/production) are versioned artifacts.
- **Configuration items baselined:** SRS, SDD, SPMP, schema (DBML + migrations), CI config, Docker/infra, API contract.

---

## 21. Release Management

- **Versioning:** Semantic Versioning `MAJOR.MINOR.PATCH`. Phase GAs are minor/major releases (e.g. P2 GA = `v1.0.0`, P3 = `v1.1.0`, P4 = `v1.2.0`); fixes are patches.
- **Release train:** feature-complete → freeze (optional `release/*`) → hardening (security/a11y/perf/UAT) → tag → deploy to staging → client sign-off → promote to production.
- **Changelog:** generated from Conventional Commits per release.
- **Build artifacts:** production Docker image (baked source + `composer install --no-dev --optimize-autoloader`) and the built SPA (`vite build`) served as static assets by Nginx (no dev Vite/node in prod) ([SDD §5.2](#), [§9.1](#)).
- **Feature toggles:** incomplete or opt-in features gated by settings (`ai_system_settings`, `system_settings`) rather than code branches — e.g. AI predictions ship **off** ([CON-07](#)).
- **Rollback:** redeploy the previous tagged image; restore the database from the pre-deploy backup if a migration must be reverted ([§27](#27-backup-and-recovery-strategy)).

---

## 22. Risk Management

**Product/technical risks are inherited from [SRS §32 `RSK-01..10`](#)** (AI provider, PII-to-AI, counter-cache drift, scope creep, a11y regressions, perf at volume, aging hardware, open-decision ambiguity, backup untested, single-tenant assumption). This section adds **project/management risks** and the control process.

| ID | Project risk | L | I | Mitigation | Owner |
|---|---|---|---|---|---|
| PR-01 | Team capacity/velocity unconfirmed → schedule uncertainty. | H | M | Relative sizing; confirm capacity at approval; re-baseline after 2 sprints of actuals ([SA-01/03](#appendix-b--assumption-register)). | R-PM |
| PR-02 | Bus factor (small team / single contributor). | M | H | ADRs + Claude-Mem + vault docs; pairing; ≥ 1 reviewer per PR; automated everything. | R-DEV |
| PR-03 | Client-gate availability delays phase starts. | M | M | Schedule gate reviews in advance; keep decisions batched; async approval option. | R-PM |
| PR-04 | AI provider cost/latency/policy change (P3). | M | M | Provider abstraction ([SDD DD-11](#)); token/latency logging; degrade gracefully; cost budget alert. | R-DEV |
| PR-05 | Third-party dependency churn (React 19 / Tailwind 4 / Laravel 13 ecosystem). | M | M | Pinned lockfiles; scheduled update sprints; CI catches breakage. | R-DEV |
| PR-06 | Scope creep from Future/opt-in features into P2. | M | M | Firm phasing; [§25](#25-change-management); Future items stay in [§32](#32-future-roadmap). | R-PM |
| PR-07 | Open decisions ([SRS `OI-01..07`](#)) unresolved before their phase. | M | M | Track as change items; force resolution at the relevant gate. | R-PM |
| PR-08 | Environment drift (dev vs prod) causes “works on my machine”. | L | M | Docker parity; identical CI DB; staging mirrors prod. | R-DEV |

**Process:** risk register reviewed **every sprint**; each risk has L (likelihood), I (impact), mitigation, and owner; new risks logged in [Issue Management](#23-issue-management) with a `risk` label; top risks reported at each gate.

---

## 23. Issue Management

- **Tracker:** GitHub Issues ([SA-05](#appendix-b--assumption-register)), linked to PRs and commits (Conventional Commit footers / “Closes #”).
- **Types/labels:** `bug`, `feature`, `tech-debt`, `security`, `a11y`, `performance`, `risk`, `docs`, `question`; phase labels `P2/P3/P4`.
- **Severity (defects):** S1 blocker (prod down / data loss) · S2 major (feature broken, no workaround) · S3 minor (workaround exists) · S4 cosmetic.
- **Triage:** new issues triaged within one working day; S1/S2 interrupt the sprint; S3/S4 enter the backlog.
- **Traceability:** every issue references the SRS/SDD ID(s) it affects; every fix references its issue and adds a regression test.
- **Definition of resolved:** fix merged (CI green), regression test added, verified against acceptance, closed with a note.

---

## 24. Communication Plan

| Event | Frequency | Participants | Output |
|---|---|---|---|
| Async daily status | daily | team | short written update (blockers). |
| Sprint planning | per sprint | team (+PO) | committed sprint backlog. |
| Sprint review/demo | per sprint | team + PO | demo of vertical slices vs acceptance. |
| Sprint retrospective | per sprint | team | process improvements. |
| **Phase-gate review** | per phase (M0,M5,M6,M7) | team + **client** | acceptance sign-off / go-no-go. |
| Risk review | per sprint | team | updated risk register. |
| Ad-hoc decision record | as needed | R-DEV | ADR in `vault/08 ADR`. |

**Channels:** repository (issues/PRs) is the system of record; synchronous calls for gate reviews; decisions captured as ADRs (structure) + Claude-Mem (rationale). **Reporting:** a one-page status per sprint (progress vs milestones, risks, defects, next gate).

---

## 25. Change Management

- **Baselines under change control:** the SRS, SDD, and SPMP; the database schema; the API contract.
- **Change request (CR) flow:** raise CR (issue, `change` label) → **impact analysis** against SRS/SDD/schedule/cost → decision (R-DEV + R-PM, client for scope/cost) → update the affected baselined document with a version bump → implement.
- **Schema changes** additionally require a migration + review and a `migrate:fresh`→`rollback`→`migrate` verification ([CON-02](#), [§20](#20-configuration-management)).
- **The seven open product decisions ([SRS `OI-01..07`](#))** are pre-registered change items (single vs multi-role, self-registration, MFA, QR expiry, SLA model, auto-escalation, confirm NFR targets). Each must be resolved **before** the phase it affects; resolution updates the SRS to v1.1 and cascades to the SDD/SPMP as needed.
- **Document versioning:** SemVer-style for docs (`1.0 → 1.1`); revision history table in each document; superseded versions retained.
- **No silent scope change:** anything outside the SRS scope is a CR, not a task.

---

## 26. Deployment Strategy

Architecture references: [SDD §5 (Deployment)](#) and [SDD §37 (Deployment diagrams)](#).

- **Environments:** **development** (Docker on workstation) → **staging** (production-like, used for UAT and release validation) → **production**.
- **Production shape:** TLS-terminating edge; stateless Laravel app containers scaled horizontally behind it; the SPA served as a static build; Redis for cache/session/queue; separate `queue` and `scheduler` workers; managed/backed-up PostgreSQL ([SDD §5.2](#), [SDD §9.1](#)).
- **Release procedure:** build tagged image + SPA → deploy to staging → run migrations → smoke + acceptance on staging → **client sign-off** → promote to production (rolling/blue-green on the stateless tier for near-zero downtime) → run migrations → post-deploy health check (`/up`, `/api/health`).
- **Database migrations:** forward-only in production; destructive changes gated by a pre-deploy backup and a rollback plan.
- **Config/secrets:** injected via environment/secrets manager; `SANCTUM_STATEFUL_DOMAINS`/`SESSION_DOMAIN` set to the production origin.
- **Rollback:** previous image + (if needed) DB restore ([§27](#27-backup-and-recovery-strategy)).
- **[SA-06]** The concrete production hosting target (managed containers vs VM, managed Postgres vs self-hosted) is confirmed with the client before M5; the design is host-agnostic.

---

## 27. Backup and Recovery Strategy

Realizes [SRS `NFR-AVL-003/004`](#).

- **Backups:** PostgreSQL dumps via the existing `scripts/backup.sh` (`pg_dump | gzip → backups/<db>-<ts>.sql.gz`); in production, scheduled (cron/scheduler) and shipped **off-site**, with WAL/PITR enabled where the hosting supports it.
- **Targets:** **RPO ≤ 24 h** (target ≤ 1 h with PITR); **RTO ≤ 4 h**.
- **Restore:** `scripts/restore.sh` (`make restore FILE=…`); a **documented restore drill** is performed before M5 and periodically thereafter ([NFR-AVL-004](#)) — an unrehearsed backup is treated as no backup ([RSK-09](#)).
- **Retention:** backups retained per policy; PII and high-volume logs pruned per [NFR-SEC-015](#) while honoring legal holds.
- **Backup inventory:** the `backup_history` table records backup runs (type/status/location/size) for auditability.
- **File assets:** attachment/repair-image storage included in the backup scope (stored outside the web root, [NFR-SEC-007](#)).

---

## 28. Monitoring Strategy

Realizes [SRS `NFR-OBS-*`](#) and [`NFR-AVL-001`](#).

- **Health:** framework `/up` and `/api/health` (DB + Redis reachability) feed orchestrator liveness/readiness and uptime monitoring ([SDD §5.2](#)).
- **Structured logs** with per-request correlation ids propagated to jobs and provider calls ([SDD §20](#)); shipped to a log aggregator in production.
- **Metrics:** request latency (vs [SRS §13](#) p95 targets), error rate, queue depth/failure rate, DB connections; **AI token/latency** captured on analysis/conversation rows ([NFR-OBS-003](#)).
- **Alerting:** on health failure, error-rate spikes, queue backlog, failed backups, SLA-breach volume, and (P3) AI cost/latency thresholds.
- **Availability:** measured against **≥ 99.5%** business-hours target ([NFR-AVL-001](#)); planned downtime scheduled via `maintenance_windows` and announced.
- **Security events:** login/lockout/permission-change and audit-trail volume monitored ([§29](#29-security-management)).

---

## 29. Security Management

Design references: [SDD §29 (Security Architecture)](#) implementing [SRS §12](#).

- **Ownership:** R-DEV owns security posture; security is a **release gate** each phase (DL-09).
- **Secrets:** managed via a secrets manager in production; least-privilege DB role (not the dev superuser); never committed (`docs/ENVIRONMENT.md`).
- **Dependency & code security:** dependency vulnerability scanning in CI; the repo’s `security-review` tooling run on security-sensitive PRs; static analysis (Larastan) enforced.
- **Application controls (verified per phase):** uuid-only external ids, deny-by-default RBAC, Sanctum first-party cookies + CSRF, server-side validation, output encoding, file-upload allow-list + checksum, append-only audit, rate limiting ([SRS `NFR-SEC-001..017`](#)).
- **AI data governance (P3):** data minimization/redaction before any Gemini call; AI advisory-only; transparency and disable switch ([SRS §17.5](#), [`RSK-02`](#)).
- **Access control (project):** production access least-privilege; audit of privileged actions ([NFR-SEC-017](#)).
- **Incident response:** severity-based (align to [issue severities](#23-issue-management)); S1 security incident → contain, assess, notify stakeholders, remediate, post-mortem + ADR.
- **Reviews:** a full security review precedes each phase GA; findings tracked as `security` issues and closed before release.

---

## 30. Acceptance Process

- **Basis:** the acceptance criteria in **[SRS §31](#)** — the six **global gates `AC-G1..G6`** plus per-feature criteria (`AC-TKT-*`, `AC-AI-*`, etc.).
- **Per work item:** demonstrated against its acceptance criteria at sprint review (Definition of Done).
- **Per phase (formal):** an acceptance package is prepared for the phase-gate review — passing CI, coverage report, security review report, WCAG 2.2 AA audit report, load-test results, and UAT results against the operational scenarios ([SRS §26](#)).
- **UAT:** client-side users execute scripted scenarios on staging; defects triaged by severity; S1/S2 must be resolved before sign-off.
- **Sign-off:** the client formally approves the phase gate ([M5](#12-milestones)/[M6](#12-milestones)/[M7](#12-milestones)); approval authorizes production release and the start of the next phase.
- **Definition of acceptance:** all mandatory (`M`) requirements for the phase pass; `Future` items are explicitly excluded and remain in the roadmap.

---

## 31. Maintenance Plan

Post-GA steady state (begins at [M5](#12-milestones), formalized at [M8](#12-milestones)):

- **Support model:** issue-tracker intake with the [severity SLAs](#23-issue-management); S1 hotfix path via `hotfix/*` → tag → deploy.
- **Corrective:** bug fixes with regression tests; **adaptive:** dependency/security updates on a scheduled cadence (e.g. monthly), each through CI; **perfective:** performance/UX improvements from monitoring and feedback; **preventive:** tech-debt sprints, backup restore drills, log/PII retention pruning ([NFR-SEC-015](#)).
- **Operational routines:** verify backups + periodic **restore drill** ([NFR-AVL-004](#)); review audit/security logs; watch SLA-breach and AI-cost trends.
- **Knowledge continuity:** ADRs and Claude-Mem kept current; onboarding doc maintained; the vault is the durable engineering record.
- **Handover pack (DL-13):** runbooks (deploy, rollback, backup/restore, incident), environment/secret inventory (names only), monitoring/alert catalog, and the current documentation set.

---

## 32. Future Roadmap

Beyond the committed phases; scope-controlled and **not** part of P2–P4 unless promoted via [§25](#25-change-management). Sources: [SRS §34](#) and [SDD §38 `EXT-01..11`](#).

| Horizon | Item | Reference |
|---|---|---|
| Near | Resolve open decisions that add capability (MFA, multi-role, SLA policies, auto-escalation). | SRS `OI-01..07`, EXT-04/05 |
| Near | Saved/scheduled reports + analytics materialized views. | FR-RPT-006/007, EXT-03 |
| Mid | Predictive maintenance enabled at scale (currently opt-in, default off). | FR-AI-011/012, EXT-02 |
| Mid | Log partitioning + read replica as volume grows. | NFR-SCAL-004/007, EXT-08 |
| Mid | Additional UI locales (strings already externalized). | NFR-I18N-002, EXT-09 |
| Long | Network topology / heat maps (optional PostGIS). | SRS §19 (future), EXT-07 |
| Long | Programmatic API tokens for integrations. | EXT-06 |
| Long | Multi-tenancy (would require a tenancy/RLS re-architecture — flagged, not seamed). | CON-08, EXT-11 |

---

## Appendix A — Tooling & Command Reference

From the implemented repo (`Makefile`, `scripts/`, `.github/workflows/ci.yml`, `docs/DEVELOPMENT.md`):

| Task | Command |
|---|---|
| Start / stop stack | `make up` / `make down` |
| Logs / status | `make logs` / `make ps` |
| Shell in app container | `make shell` |
| Migrate / fresh | `make migrate` / `make fresh` |
| Lint (all) | `make lint` (Pint `--test` + Larastan + ESLint) |
| Format (all) | `make format` (Pint + Prettier) |
| Test (all) | `make test` (Pest + Vitest) |
| DB backup / restore | `make backup` / `make restore FILE=…` |
| psql / redis shell | `make psql` / `make redis` |
| CI (auto) | `.github/workflows/ci.yml` — backend + frontend jobs on push/PR to `main` |

**CI gates enforced:** backend — Pint `--test`, Larastan (L6, `--memory-limit=512M`), Pest (against `pgvector/pgvector:pg17` service DB); frontend — ESLint, Prettier `--check`, Vitest, `vite build` (Node 22).

---

## Appendix B — Assumption Register

All calendar/resourcing figures are **assumptions** pending confirmation at plan approval.

| ID | Assumption | Impact if wrong | Confirm by |
|---|---|---|---|
| SA-01 | Team capacity supports the indicative durations in [§11](#11-phase-plan); schedule is re-baselined after 2 sprints of actual velocity. | Timeline shifts (not scope). | Plan approval |
| SA-02 | Small team; roles ([§7](#7-team-roles-and-responsibilities)) may be held by few people; AI assistance is human-reviewed. | Role coverage / bus-factor (PR-02). | Plan approval |
| SA-03 | Sprint length = 2 weeks. | Cadence of reviews/gates. | Plan approval |
| SA-04 | `main` branch protection with required PR + green CI is enabled. | Quality-gate enforcement. | Before P2 |
| SA-05 | Issue tracking uses GitHub Issues (same platform as CI). | Tooling for [§23](#23-issue-management). | Plan approval |
| SA-06 | Production hosting target chosen before M5; design is host-agnostic. | Deployment specifics ([§26](#26-deployment-strategy)). | Before M5 |
| SA-07 | Client provides UAT participants and timely gate approvals. | Gate scheduling (PR-03). | Per phase |
| SA-08 | Production secrets manager + least-privilege DB role available in prod. | Security posture ([§29](#29-security-management)). | Before M5 |
| SA-09 | A Gemini API key with adequate quota/budget is provided for P3. | P3 start ([RSK-01/PR-04](#)). | Before P3 |

---

## Appendix C — Consistency Review Log

Per the required post-generation review: verify consistency with the SRS and SDD, remove contradictions/duplication, and improve clarity.

### C.1 Sources reconciled
SRS v1.0 (phasing, `FR/NFR/BO/RSK/CON/ASM/OI` IDs, acceptance §31); SDD v1.0 (domains, `DD-01..16`, `RES-01/02`, deployment §5/§37, tech stack); and the **current repository** — `.github/workflows/ci.yml`, `Makefile`, `scripts/*`, `docs/DEVELOPMENT.md`, `docs/ENVIRONMENT.md`, `scripts/backup.sh`, `compose.yaml` — read directly for realism.

### C.2 SRS/SDD consistency — confirmed
- **Phasing** (P2/P3/P4/Future) is identical across SRS, SDD, and this plan; the WBS, phase plan, milestones, and roadmap all use it.
- **Acceptance** references SRS §31 (global gates + feature ACs) rather than inventing new criteria.
- **Domains** in the WBS match SDD §14, including the two new P2 domains from **RES-01 (Locations)** and **RES-02 (Identity, Administration)** — the plan schedules exactly those.
- **Risks:** product/technical risks are inherited from SRS §32 (`RSK-*`) and *not duplicated*; only project/management risks (`PR-*`) are added here.
- **Open decisions** (`OI-01..07`) are carried as change items in §25/§32, consistent with the SRS.
- **Security/backup/deployment** reference SDD §29/§5/§37 and SRS NFR targets (RPO ≤ 24 h, RTO ≤ 4 h, uptime ≥ 99.5%) without restating them.

### C.3 Repository consistency — confirmed
CI gates, `make`/`scripts` commands, env-file strategy, Redis-for-everything scale-readiness, Sanctum stateful-domain config, and the `backup.sh`/`restore.sh` flow described here match the implemented files exactly (Appendix A). The baseline underlying this plan matches the git history (phases 0–7 + DB layer) and the SDD’s statement that the application layer is not yet built.

### C.4 Contradictions & duplication removed
- **No duplication of requirements/design/schema:** all referenced by ID; product scope defers to SRS §3; deployment/security defer to SDD.
- **Risk duplication avoided:** SRS `RSK-*` referenced, not copied; management risks separated as `PR-*`.
- **Single phasing vocabulary:** eliminated any ambiguity between “Phase 2 (business logic)” and SRS “P2” by treating them as the same thing (P2 = the first application build phase after documentation).
- **Assumptions consolidated** in Appendix B and cross-referenced from the body, rather than scattered.

### C.5 Clarity & maintainability improvements
Milestones are acceptance-defined (robust to schedule change); calendar figures are explicitly assumptions; the plan references living artifacts (CI, Makefile, docs) so it stays true as the repo evolves; every section ties to an owner and, where relevant, an SRS/SDD ID.

### C.6 Residual items
None blocking. Confirm the Appendix B assumptions (chiefly team capacity **SA-01/02/03**) at plan approval to finalize the indicative schedule; resolve SRS `OI-01..07` before their respective phases. No contradictions with the SRS or SDD remain.

---

*End of Software Project Management Plan v1.0 — Version 1.0 baseline — Waiting for Client Approval. Phase 2 development proceeds on separate go-ahead.*
