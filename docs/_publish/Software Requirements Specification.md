---
title: Software Requirements Specification (SRS)
system: AI-Powered IT Asset & Service Management System (SccIT)
doc_id: SCCIT-SRS
version: 1.0
status: Waiting for Client Approval
date: 2026-07-17
author: Engineering (Beemo)
classification: Internal — Confidential
standard: Aligned with ISO/IEC/IEEE 29148:2018 (Requirements Engineering)
owner: Client / Product Owner (project owner · system owner · primary decision-maker)
---

# Software Requirements Specification

**AI-Powered IT Asset & Service Management System — codename “SccIT”**

> **Authoritative requirements baseline.** This document is the single source of truth for *what* the system must do and *how well* it must do it. It does not prescribe *how* the system is built internally — that is the role of the companion Software Design Description (SDD). Where this SRS and any other artifact disagree, **this SRS governs the requirements**; the DBML (`docs/database/database_design_v2.dbml`) governs the physical data model it references.

---

## Document Control

| Field | Value |
|---|---|
| Document ID | SCCIT-SRS |
| Version | 1.0 |
| Date | 2026-07-17 |
| Status | **Waiting for Client Approval** |
| Standard | ISO/IEC/IEEE 29148:2018 (adapted) |
| Prepared by | Engineering |
| Owner / decision authority | Client / Product Owner — project owner, system owner, and primary decision-maker |
| Related documents | `PRODUCT.md`, `DESIGN.md`, `docs/database/database_architecture_report.md`, `docs/database/database_design_review.md`, `docs/database/database_design_v2.dbml`, `docs/ENVIRONMENT.md`, `docs/PROJECT_STRUCTURE.md`, `docs/diagrams/` (UML sources + rendered figures) |

### Revision History

| Version | Date | Author | Summary |
|---|---|---|---|
| 1.0 | 2026-07-17 | Engineering | **Version 1.0 baseline.** Consolidated requirements specification: scope, business objectives, stakeholders, user roles and the seeded permission matrix, system context, the complete functional requirement set (FR-AUTH/USER/LOC/PC/TKT/ASN/MNT/AST/AUD plus AI, QR, floor-plan, dashboard, reporting, notification and settings requirements), non-functional/security/performance/scalability/availability/accessibility requirements, external interfaces, business rules, operational scenarios, the System Use Case Diagram (**Figure 2**) with detailed use-case specifications (**UCS-01…10**) and workflow activity diagrams (**Figures 3–6**), system-wide AI behaviour, data requirements, traceability, acceptance criteria, risks/constraints/assumptions, open decisions, glossary, and Appendices A–B. Product Perspective Block Diagram at **Figure 1**. Status: **Waiting for Client Approval**. |

### Conventions used in this document

- **Requirement verbs (RFC 2119 / ISO 29148 sense):** *shall* = mandatory; *should* = recommended; *may* = optional; *will* = statement of fact/intent.
- **Requirement identifier:** every requirement carries a unique, stable ID (e.g. `FR-TKT-014`). IDs are never reused once assigned.
- **Class:** `M` = Mandatory (in scope for the release stated under **Rel**); `F` = Future (planned, explicitly **out of current scope**).
- **Rel (release phase):**
  - `P2` — Core platform (the next build phase; the MVP baseline).
  - `P3` — AI & Knowledge Base (Gemini + RAG).
  - `P4` — Interactive Floor Plan + real-time.
  - `—` — Future/backlog (not yet scheduled).
- **Ver (verification method):** `T` Test · `D` Demonstration · `I` Inspection · `A` Analysis. See [§4.5](#45-verification-methods).
- **Traceability:** requirements trace up to Business Objectives (`BO-n`) and down to data entities and acceptance criteria (see [§30](#30-requirements-traceability-matrix)).

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Purpose](#2-purpose)
3. [Scope](#3-scope)
4. [Definitions, Acronyms & Verification Methods](#4-definitions-acronyms--verification-methods)
5. [Business Objectives](#5-business-objectives)
6. [Product Overview](#6-product-overview)
7. [Stakeholders](#7-stakeholders)
8. [User Roles](#8-user-roles)
9. [System Context](#9-system-context)
10. [Functional Requirements](#10-functional-requirements)
11. [Non-Functional Requirements](#11-non-functional-requirements)
12. [Security Requirements](#12-security-requirements)
13. [Performance Requirements](#13-performance-requirements)
14. [Scalability Requirements](#14-scalability-requirements)
15. [Availability & Reliability Requirements](#15-availability--reliability-requirements)
16. [Accessibility Requirements (WCAG 2.2 AA)](#16-accessibility-requirements-wcag-22-aa)
17. [AI Requirements](#17-ai-requirements)
18. [QR Code Requirements](#18-qr-code-requirements)
19. [Interactive Floor Plan Requirements](#19-interactive-floor-plan-requirements)
20. [Dashboard Requirements](#20-dashboard-requirements)
21. [Reporting Requirements](#21-reporting-requirements)
22. [Notification Requirements](#22-notification-requirements)
23. [System Settings Requirements](#23-system-settings-requirements)
24. [External Interface Requirements](#24-external-interface-requirements)
25. [Business Rules](#25-business-rules)
26. [Operational Scenarios](#26-operational-scenarios)
27. [Use Cases](#27-use-cases)
28. [User Stories](#28-user-stories)
29. [Data Requirements](#29-data-requirements)
30. [Requirements Traceability Matrix](#30-requirements-traceability-matrix)
31. [Acceptance Criteria](#31-acceptance-criteria)
32. [Risks, Constraints & Assumptions](#32-risks-constraints--assumptions)
33. [Open Issues & Decisions Requiring Client Approval](#33-open-issues--decisions-requiring-client-approval)
34. [Future Expansion](#34-future-expansion)
35. [Glossary](#35-glossary)
36. [Appendix A — Internal Review & Verification Log](#appendix-a--internal-review--verification-log)

### List of Figures

| Figure | Title | Section |
|---|---|---|
| **Figure 1** | Product Perspective Block Diagram | [§6.1](#61-product-perspective) |
| **Figure 2** | System Use Case Diagram | [§27](#27-use-cases) |
| **Figure 3** | Teacher / Requester Workflow — Activity Diagram | [§27.3](#273-operational-workflow-activity-diagrams) |
| **Figure 4** | Technician Workflow — Activity Diagram | [§27.3](#273-operational-workflow-activity-diagrams) |
| **Figure 5** | Administrator Workflow — Activity Diagram | [§27.3](#273-operational-workflow-activity-diagrams) |
| **Figure 6** | System-Wide AI Behavior — Activity Diagram | [§27.3](#273-operational-workflow-activity-diagrams) |

> Figures are rendered from version-controlled UML sources in `docs/diagrams/`, with high-resolution `.svg`/`.png` committed: the Product Perspective from Mermaid + PlantUML, and the (stickman) Use Case Diagram from PlantUML + a self-contained layout generator.

---

## 1. Executive Summary

SccIT is a **vertical-agnostic IT Service Management (ITSM) and IT Asset Management (ITAM) platform**, deployed first for educational institutions but architected to re-skin for businesses, government offices, hospitals, and other organizations by changing a single brand token. It replaces manual, fragmented IT processes — spreadsheets, email threads, and paper repair logs — with one system in which service requests are resolved faster, assets are tracked accurately across their full lifecycle, preventive maintenance happens before hardware fails, and administrators see the real operational picture at a glance.

The platform serves three role classes on a shared surface: **Administrators** (system owners), **Technicians** (the daily work-queue drivers), and **Teachers/requesters** (occasional, non-technical reporters). It couples a classic ticketing/assignment workflow with a complete asset inventory (serialized assets and quantity-tracked consumables), QR-based physical verification, preventive-maintenance scheduling, an AI-assisted troubleshooting layer (Google Gemini + Retrieval-Augmented Generation over pgvector), a future interactive floor plan, and first-class analytics/reporting.

The **data layer is already implemented and verified** to a production bar: a PostgreSQL 17 relational schema (66 Eloquent models and 34 enum domains) with timezone-correct timestamps, referential integrity with deliberate delete policies, soft deletes on business entities, full-text and vector indexes, tamper-resistant audit logging, and an RBAC model. The **business logic (application) layer has not yet been built** — this SRS defines the requirements for that build and everything that follows.

This document has been reconciled against every available source of truth and an internal review (Appendix A). It records **flagged decisions that would alter the specified business model** (e.g. multi-role users, self-service registration, MFA) as **open issues in [§33](#33-open-issues--decisions-requiring-client-approval)** rather than silently adopting them; the client’s decisions on those items will be folded into version 1.1 before the SDD is authored.

---

## 2. Purpose

### 2.1 Purpose of the system
SccIT exists to **replace manual, fragmented IT operations with a single, trustworthy system** that (a) cuts busywork, (b) improves mean time to resolution and asset accuracy, and (c) presents administrators with reliable operational truth — all at a level of polish suitable for enterprise procurement.

### 2.2 Purpose of this document
This SRS specifies the functional and non-functional requirements of SccIT in a complete, consistent, verifiable, and traceable form. Its intended readers and uses are:

| Reader | Use |
|---|---|
| Client / product owner | Confirm the system will satisfy the business need; approve scope and phasing. |
| Engineering | Basis for the Software Design Description (SDD), implementation, and estimation. |
| QA / test | Basis for test plans; every requirement is written to be verifiable. |
| Operations / security | Basis for deployment, hardening, backup, and audit review. |
| Future maintainers | Authoritative record of *why* the system behaves as it does. |

### 2.3 Requirements quality commitment
Per ISO/IEC/IEEE 29148, every requirement in this document is intended to be **necessary, unambiguous, singular, feasible, verifiable, and traceable**. The set is intended to be **complete, consistent, and free of duplication**. Deviations, gaps, and decisions still owned by the client are listed explicitly in [§32](#32-risks-constraints--assumptions) and [§33](#33-open-issues--decisions-requiring-client-approval).

---

## 3. Scope

### 3.1 Product name
**SccIT** — AI-Powered IT Asset & Service Management System. (Repository: `SccITTicketSys`.)

### 3.2 In scope

The system provides, across phased releases:

- **Identity & access control** — authentication, three-role RBAC with per-user permission overrides, user lifecycle, session/login history.
- **Location model** — buildings → floors → rooms (typed: laboratory/office/storage/server room/…), the spatial foundation for asset placement and the floor plan.
- **Computer (PC unit) management** — PC units as composed configuration items, specifications, installed hardware history.
- **QR code management & physical verification** — generate, print, and scan QR codes bound to PC units or standalone assets.
- **IT service ticketing (ITSM)** — full ticket lifecycle: submission, categorization/prioritization, assignment, comments, attachments, votes, status history, SLA tracking, duplicate handling.
- **Technician workflow & maintenance** — assignment lifecycle, corrective repairs, and **preventive maintenance scheduling** with checklists, repair images, notes, and hardware replacements.
- **Inventory & asset lifecycle (ITAM)** — hardware catalog (manufacturers → components → models), **serialized assets** and **quantity-tracked consumables**, stock ledger, procurement with approval, transfers, and disposal.
- **AI assistance** — AI ticket triage/analysis, conversational troubleshooting, a RAG knowledge base, feedback capture; and (opt-in) predictive maintenance and auto-drafted knowledge articles.
- **Interactive floor plan** — visual, drag-and-drop layout of PCs per room with status-colored icons and click-through info panels *(future phase; groundwork present)*.
- **Dashboards, analytics & reporting** — role-aware dashboards, KPI widgets, on-demand reports, and data export.
- **Administration** — notifications & preferences, announcements, centralized system settings/branding, audit & activity logging, planned maintenance windows, and backup history.

### 3.3 Out of scope (this program)

The following are explicitly **not** delivered by SccIT (they may be integrated externally):

- Financial/accounting, payroll, HR, or general ERP functions beyond IT procurement request tracking.
- Learning-management, student-information, or academic-grading functionality (SccIT is IT-operations software that *happens* to ship for schools first).
- Endpoint management/RMM agents, automated software deployment, or remote device control.
- Native mobile applications (the web UI is responsive and works on mobile browsers; `ticket_source = mobile` denotes a mobile *browser*, not a native app).
- Multi-tenancy / multi-organization isolation in one deployment (see [§32 CON-08](#322-constraints)).

### 3.4 Release phasing (summary)

| Phase | Contents | Class |
|---|---|---|
| **P2 — Core** | Identity/RBAC, Locations, PC units, QR, Ticketing, Assignment, Maintenance, Inventory/Assets, Dashboards, Reporting, Notifications, Settings, Audit, Announcements, Backup. | Mandatory |
| **P3 — AI & Knowledge Base** | AI triage/analysis, conversational assistant, RAG knowledge base, AI feedback. | Mandatory |
| **P4 — Interactive Floor Plan & Real-time** | Floor plan editor/viewer, drag-drop, snap-to-grid, live position/status updates (Reverb). | Mandatory (later) |
| **Future** | Predictive maintenance at scale, network topology/heat maps, MFA, multi-role, scheduled reports, multi-tenant. | Future |

> **Note on “AI-Powered” naming.** AI is a core differentiator (P3), but sequenced *after* the core platform because it depends on the ticketing/asset data and on an external provider (Gemini). AI availability degrades gracefully: if AI is disabled or unreachable, every core workflow remains fully usable (see [FR-AI-020](#173-ai-functional-requirements)).

---

## 4. Definitions, Acronyms & Verification Methods

### 4.1 Acronyms

| Acronym | Meaning |
|---|---|
| ITSM / ITAM | IT Service Management / IT Asset Management |
| CI | Configuration Item (e.g. a PC unit composed of parts) |
| RBAC | Role-Based Access Control |
| RAG | Retrieval-Augmented Generation |
| MTTR / FRT | Mean Time To Resolution / First Response Time |
| SLA | Service Level Agreement |
| IDOR | Insecure Direct Object Reference |
| WCAG | Web Content Accessibility Guidelines |
| SPA | Single-Page Application |
| RPO / RTO | Recovery Point / Time Objective |
| FTS | Full-Text Search |
| HNSW | Hierarchical Navigable Small World (vector index) |

### 4.2 Key domain terms
See the [Glossary (§35)](#35-glossary) for the full list. Core terms: **Reporter** (role-neutral ticket originator), **PC Unit** (a managed computer), **Asset** (a serialized physical unit), **Consumable** (quantity-tracked stock), **Room** (typed location), **Layout** (a versioned floor-plan canvas for a room).

### 4.3 Requirement statement pattern
Functional requirements follow: *“The system shall <capability> [for <role>] [when <condition>] [so that <outcome/measure>].”* Non-functional requirements state a **measurable target** and its **verification method**.

### 4.4 Priority & class legend
`M` Mandatory · `F` Future · `P2/P3/P4/—` release phase (see Conventions).

### 4.5 Verification methods
- **T (Test):** executed against acceptance criteria (automated test, scripted manual test).
- **D (Demonstration):** operate the system and observe the result (no measurement instrument).
- **I (Inspection):** review of artifacts (code, config, schema, UI) against the requirement.
- **A (Analysis):** modeling, calculation, or load simulation (used for performance/scalability).

---

## 5. Business Objectives

| ID | Business Objective | Success measure (indicative; final targets confirmed at approval) |
|---|---|---|
| **BO-01** | Reduce time to resolve IT service requests. | Median MTTR reduced ≥ 30% vs. the current manual baseline within 2 reporting periods after go-live. |
| **BO-02** | Achieve and maintain accurate asset tracking across the full lifecycle. | ≥ 98% of managed assets have a current status, location, and custodian; physical audit variance < 2%. |
| **BO-03** | Shift maintenance from reactive to preventive. | ≥ 60% of maintenance events are scheduled/preventive (not ticket-triggered) within 2 periods. |
| **BO-04** | Give administrators reliable, at-a-glance operational truth. | Core KPIs (open backlog, SLA compliance, asset counts) available on a dashboard with data no older than the last completed transaction. |
| **BO-05** | Lower the effort and anxiety of reporting a problem for non-technical staff. | A first-time reporter can submit a valid ticket in ≤ 2 minutes without training; AI self-help deflects a measurable share of low-severity tickets. |
| **BO-06** | Enforce accountability and auditability of IT operations. | 100% of create/update/delete actions on business entities are attributable to a user and recorded in a tamper-resistant audit trail. |
| **BO-07** | Deliver enterprise-grade quality, security, and accessibility. | WCAG 2.2 AA verified; security requirements ([§12](#12-security-requirements)) met; performance targets ([§13](#13-performance-requirements)) met. |
| **BO-08** | Remain vertical-agnostic and re-brandable. | A new deployment can be re-branded (name, logo, primary color, locale) via settings only, with **zero code changes**. |

---

## 6. Product Overview

### 6.1 Product perspective
SccIT is a **new, self-contained web platform** — not a replacement module inside an existing suite. It is a **modular monolith**: a single deployable application organized internally into domains (Tickets, Assets, Maintenance, KnowledgeBase, Analytics, FloorPlan) so future modules drop in without restructuring. It exposes a REST API consumed by a single-page web client.

![SccIT Product Perspective (System Context) Block Diagram](diagrams/product-perspective.png)

**Figure 1. Product Perspective Block Diagram.** A high-level, layered context view of the product and the parties it interacts with, read top to bottom: **External Users → Web Browser → Nginx → React SPA → Laravel Backend → PostgreSQL/Redis → External Services.** **External users** (Administrators, Technicians, Teachers/Requesters) reach the platform through a **web browser** over a single HTTPS origin. **Nginx** is the single-origin edge: it serves the **React 19 single-page application** (Vite build, TanStack Query, design-token styling) and routes `/api`·`/sanctum` traffic to the **Laravel 13 REST API** (a modular monolith running on PHP-FPM). The backend is shown as a set of connected components — **Auth & RBAC, Audit, Notification, AI Services, and the Domain Modules** (Tickets · Assets · Maintenance · Analytics). The backend persists to its **data stores**: **PostgreSQL 17** (+ pgvector) as the system of record and **Redis** for cache, session, and queue. The platform integrates a small set of **external services** — an **email transport** (Mailpit in development, SMTP in production), the **Google Gemini AI provider**, and **QR-code generation/verification**. The whole stack is packaged and run by the **infrastructure layer** (Docker, Nginx, PHP-FPM, Redis-backed queue workers). Node colours denote *architectural tier*, not delivery phase. This is a *product-perspective* view, not a deployment diagram: it deliberately omits replica counts, ports, and container topology (see the SDD Deployment Architecture for those).

### 6.2 Product functions (high level)
1. Report, triage, assign, and resolve IT service tickets with SLA tracking.
2. Maintain a complete, auditable inventory of computers, assets, and consumables.
3. Schedule and record preventive and corrective maintenance.
4. Verify equipment physically via QR codes.
5. Assist troubleshooting with AI (triage, chat, knowledge base) grounded in the organization’s own history.
6. Visualize equipment on interactive floor plans *(future)*.
7. Surface operational KPIs through dashboards, analytics, and exportable reports.
8. Administer users, roles, branding, notifications, and system configuration.

### 6.3 Operating environment (summary)
- **Backend:** Laravel 13 (PHP 8.4) REST API; Laravel Sanctum for SPA authentication.
- **Frontend:** React 19 + TypeScript + Vite SPA.
- **Database:** PostgreSQL 17 with the `pgvector`, `citext`, and `pgcrypto` extensions.
- **Cache/queue:** Redis. **Mail (dev):** Mailpit. **Real-time (future):** Laravel Reverb (WebSockets).
- **Delivery:** Dockerized; single browser origin fronted by Nginx (`/api` → Laravel, all else → SPA).

See [§24](#24-external-interface-requirements) for full interface requirements and `docs/ENVIRONMENT.md` for environment specifics.

### 6.4 Design & brand constraints
The user experience is governed by `DESIGN.md` (“The Control Room”): calm, dense, instrument-like; a single brand signal (Signal Blue) on ≤10% of any surface; flat-by-tone elevation; light and dark modes as equal citizens; WCAG 2.2 AA as the floor. These are **binding UI constraints** (see [§16](#16-accessibility-requirements-wcag-22-aa) and [NFR-USB](#116-usability-requirements)).

---

## 7. Stakeholders

| Stakeholder | Interest / concern |
|---|---|
| **Client / sponsor (institution leadership)** | Value for money; operational improvement; enterprise-grade quality; procurement-readiness. |
| **IT Administrator(s)** | Full control and oversight; configuration; analytics; accountability. |
| **IT Technicians** | Fast, dense, keyboard-efficient work queue; accurate asset/repair data on the floor. |
| **Teachers / staff (requesters)** | Effortless, low-anxiety problem reporting and status tracking. |
| **Data Protection / Compliance owner** | PII handling, audit trail, retention, third-party (AI) data flows. |
| **Security officer** | Authentication, authorization, IDOR/enumeration resistance, tamper-resistant audit. |
| **Operations / DevOps** | Deployability, backups, availability, observability. |
| **Development & QA team** | Clear, testable requirements; maintainable architecture. |
| **Future adopters (other verticals)** | Re-brandability; generic language; no hard-coded “school” identity. |
| **Provider: Google (Gemini API)** | External AI processor; SLA, cost, and data-governance implications. |

---

## 8. User Roles

Three **system roles** are seeded and non-deletable (`is_system = true`). Authorization is enforced by role plus optional per-user permission overrides (grant/deny). The permission surface is a `module.action` matrix (below), assigned to roles at seed time and adjustable by Administrators at runtime.

### 8.1 Administrator
Owns the system. Full oversight across sites, buildings, and teams. Capabilities: user & role management, permission assignment, technician assignment oversight, asset & inventory lifecycle, procurement approval, maintenance oversight, analytics/reports, all configuration and branding, audit review, backups, announcements, and (future) floor-plan editing. Administrators hold **all** permissions.

### 8.2 Technician
The daily driver. Lives in the work queue. Capabilities: view/update/assign/comment/export tickets; run and complete maintenance (all maintenance permissions); view/update/transfer assets; view inventory and adjust stock; view AI output and give AI feedback; view and create knowledge articles; view reports; view the floor plan. Technicians **cannot** create/delete users, manage roles/system settings, delete tickets/assets, dispose assets, or approve procurement unless individually granted.

### 8.3 Teacher / Requester
Occasional, non-technical end user. Capabilities: create tickets; view **their own** tickets; comment on and upvote tickets; view published knowledge articles; use the AI assistant; give AI feedback. Teachers have **no** administrative or technician capabilities.

### 8.4 Seeded permission matrix (baseline)

| Module | Actions | Administrator | Technician | Teacher |
|---|---|:--:|:--:|:--:|
| tickets | view, create, update, delete, assign, comment, vote, export | all | view, update, assign, comment, export | view, create, comment, vote |
| locations | view, create, update, delete | all | — | — |
| assets | view, create, update, delete, transfer, dispose | all | view, update, transfer | — |
| maintenance | view, create, update, delete, complete | all | all | — |
| inventory | view, create, update, delete, adjust | all | view, adjust | — |
| ai | view, configure, feedback | all | view, feedback | view, feedback |
| knowledge | view, create, update, delete, publish | all | view, create | view |
| users | view, create, update, delete | all | — | — |
| roles | view, manage | all | — | — |
| reports | view, export | all | view | — |
| floorplan | view, manage | all | view | — |
| system | settings.manage, backup.manage, audit.view, announcements.manage | all | — | — |

> This matrix is the **baseline** (seeded). [FR-USER-010](#102-user--role-management-fr-user) permits Administrators to adjust role permissions and per-user overrides at runtime; the baseline is the tested default.
>
> **Note on the `locations` module.** It is **Administrator-only** — every action, including `view`. Managing the estate is site administration, not day-to-day work, so Technicians and Teachers reach no part of the module: no directory, tree, dashboard, detail page or edit control, and no read endpoint behind them.
>
> Naming a place inside another workflow is a separate, much narrower need (a Teacher saying where a fault is; a Technician recording where work happened). It is served by the **narrow location lookup** ([FR-LOC-011](#103-location-management-fr-loc)), which is authorized by the permission of the *consuming* workflow — `tickets.create`, `maintenance.view`, `assets.transfer` and the like — and never by a `locations.*` permission. An Administrator may still grant `locations.*` to an individual account through per-user overrides ([FR-USER-004](#102-user--role-management-fr-user)) when a deputy genuinely needs it.

---

## 9. System Context

### 9.1 Context

The system context is shown in **Figure 1** ([§6.1](#61-product-perspective)).

### 9.2 External actors & systems

| Actor / system | Role in context |
|---|---|
| Teacher / Technician / Administrator | Human users via the SPA. |
| Google Gemini API | External AI processor for chat completion and text embeddings (P3). Data leaves the organization — governed by [§17.5](#175-ai-data-governance--safety-requirements). |
| SMTP mail service | Outbound email notifications (Mailpit in dev; real SMTP in production). |
| Reverb WebSocket service | Real-time channel for live updates (P4). |
| Object/file storage | Attachment and repair-image storage (local disk by default; pluggable). |
| Backup target | Destination for database/file backups. |

---

## 10. Functional Requirements

> Notation reminder: **Class** M/F · **Rel** P2/P3/P4/— · **Ver** T/D/I/A. Requirement IDs are stable and unique.

### 10.1 Authentication & Session Management (FR-AUTH)

| ID | Requirement (the system *shall* …) | Class | Rel | Ver |
|---|---|:--:|:--:|:--:|
| FR-AUTH-001 | Authenticate users by email (case-insensitive) and password using Laravel Sanctum SPA cookie-based sessions over HTTPS. | M | P2 | T |
| FR-AUTH-002 | Store passwords only as salted bcrypt/argon2 hashes; never store or log plaintext passwords. | M | P2 | I |
| FR-AUTH-003 | Enforce a configurable password policy: minimum 10 characters with at least three of {lowercase, uppercase, digit, symbol}; reject the 1,000 most common passwords. | M | P2 | T |
| FR-AUTH-004 | Reject authentication for users whose `status` is not `active` (i.e. `pending`, `rejected`, `suspended`, `inactive`). Credential errors return a **non-disclosing** message; when credentials are valid but the account is not active, the response **may** disclose the account-status reason to the legitimate account holder (e.g. “awaiting administrator approval”) so applicants can learn their registration outcome. | M | P2 | T |
| FR-AUTH-005 | Record every authentication attempt in `login_history` with outcome (`success`/`failed`/`locked_out`), timestamp, IP (`inet`), user agent, browser, and platform. | M | P2 | T |
| FR-AUTH-006 | Lock an account for a configurable window (default: 15 minutes) after a configurable number of consecutive failed attempts (default: 5), record the lockout, and notify the user by email. | M | P2 | T |
| FR-AUTH-007 | Update `last_login_at` and `last_login_ip` on successful authentication. | M | P2 | T |
| FR-AUTH-008 | Provide a self-service “forgot password” flow using single-use, time-limited reset tokens (`password_reset_tokens`); reset links expire within a configurable period (default 60 minutes). | M | P2 | T |
| FR-AUTH-009 | Expire idle sessions after a configurable inactivity period (default 8 hours) and support explicit logout that invalidates the session server-side. | M | P2 | T |
| FR-AUTH-010 | Protect all state-changing requests with CSRF tokens and enforce same-site cookie policy. | M | P2 | I |
| FR-AUTH-011 | Allow an authenticated user to change their own password after re-entering their current password, invalidating other active sessions on change. | M | P2 | T |
| FR-AUTH-012 | Support multi-factor authentication (TOTP) as a configurable, per-user or role-enforced second factor. | F | — | T |
| FR-AUTH-013 | Provide a public **registration-request** workflow: a prospective **Teacher or Technician** (never an Administrator) may submit a registration request. Submitting a request **shall not** create an active account; it creates a user record in `pending` status that **cannot authenticate**. The applicant is shown an “awaiting administrator approval” acknowledgement and may learn the outcome by attempting to sign in (see FR-AUTH-004). | M | P2 | T |
| FR-AUTH-014 | Allow Administrators (permission `users.update`) to view pending registration requests, review applicant details, and **approve** or **reject** each request. Approval sets the account to `active` and enables sign-in; rejection sets it to `rejected` and records `rejection_reason`, `rejected_by`, and `rejected_at`. Every decision is recorded in the activity/audit trail. | M | P2 | T |
| FR-AUTH-015 | On an approval decision, email the applicant that their account has been activated; on a rejection decision, email the applicant that the registration was not approved, including the rejection reason when one was provided. | M | P2 | T |
| FR-AUTH-016 | Enforce account status centrally (a single account-status middleware) on every authenticated request so that `pending`, `rejected`, `suspended`, and `inactive` accounts are consistently denied access with an appropriate explanation, without duplicating the check across controllers. | M | P2 | T |

> **Note.** FR-AUTH-013–016 realize the Client decision on **[§33 OI-02](#33-open-issues--decisions-requiring-client-approval)** (resolved): registration is **request-and-approve**, restricted to Teachers and Technicians. Administrator accounts are provisioned only by existing Administrators (or the documented local dev seeder) and are never self-registerable ([BR-01a](#25-business-rules)). MFA (FR-AUTH-012) remains **Future**.

### 10.2 User & Role Management (FR-USER)

| ID | Requirement (the system *shall* …) | Class | Rel | Ver |
|---|---|:--:|:--:|:--:|
| FR-USER-001 | Allow Administrators to create, view, update, and soft-delete user accounts. | M | P2 | T |
| FR-USER-002 | Require, on user creation: role, first name, last name, unique email; and optionally employee number (unique when present), middle name, contact number, profile picture. | M | P2 | T |
| FR-USER-003 | Assign exactly one role per user (`Administrator`, `Technician`, or `Teacher`); prevent deletion of the three system roles. | M | P2 | T |
| FR-USER-004 | Support per-user permission overrides that **grant** or **deny** individual permissions, overriding the role default. | M | P2 | T |
| FR-USER-005 | Enforce authorization on every API endpoint using the effective permission set (role permissions adjusted by user overrides). | M | P2 | T |
| FR-USER-006 | Never hard-delete a user referenced by tickets, comments, or history; soft-delete instead and preserve all attributions. | M | P2 | T |
| FR-USER-007 | Allow Administrators to set a user’s status (`active`/`inactive`/`suspended`/`pending`/`rejected`) and reflect it immediately in access control. | M | P2 | T |
| FR-USER-008 | Allow a user to view and edit their own profile (name, contact number, profile picture, locale/theme preference) but not their own role or permissions. | M | P2 | T |
| FR-USER-009 | Record `created_by`/`updated_by` on user records and emit audit entries for account changes. | M | P2 | T |
| FR-USER-010 | Allow Administrators to view all permissions and adjust the permission set assigned to each non-system aspect of a role at runtime. | M | P2 | T |
| FR-USER-011 | Allow a user to hold more than one role simultaneously. | F | — | T |
| FR-USER-012 | Present an administrative **dashboard** at the top of the Users module: totals by status (total/active/pending/suspended/rejected/inactive/archived) and by role (Administrators/Technicians/Teachers), recent registrations, recent sign-in activity, and recent administrative actions. | M | P2 | T |
| FR-USER-013 | Provide a **server-side** user directory: pagination, search (full name, email, employee number), filtering (status, role, archived visibility), and sorting on a fixed allow-list of columns (name/email/status/role/created/last-login). | M | P2 | T |
| FR-USER-014 | Support **bulk** administration over a selected set: activate, suspend, reactivate, deactivate, reject, assign role, send notification email, and export. Each bulk mutation validates the per-record policy for every target, is fully audited, and runs transactionally (all-or-nothing). | M | P2 | T |
| FR-USER-015 | Support **export** of the directory to CSV and true Excel (`.xlsx`), honouring the current search/filter/sort and an allow-listed column subset; exports respect RBAC and never include credential material. | M | P2 | T |
| FR-USER-016 | Provide administrator **account actions**: send password reset, require password reset at next sign-in (force reset), unlock a locked account, and re-send approval / rejection / password-reset emails. Each action is audited. | M | P2 | T |
| FR-USER-017 | Provide a complete, chronological **audit timeline** per user (registration, approval, rejection, sign-in/out, failed sign-in, suspension, reactivation, password changes/resets, role changes, permission changes, administrative actions). | M | P2 | T |
| FR-USER-018 | Enforce **account-safety invariants**: an administrator may not suspend/deactivate/archive their own account, change their own role, or remove their own required permissions; and the system must always retain at least one active Administrator (no demote/suspend/archive of the last active admin). | M | P2 | T |
| FR-USER-019 | Allow an Administrator to **edit a pending registration** (profile fields and the self-registerable role) before approving or rejecting it. | M | P2 | T |

### 10.3 Location Management (FR-LOC)

| ID | Requirement (the system *shall* …) | Class | Rel | Ver |
|---|---|:--:|:--:|:--:|
| FR-LOC-001 | Allow Administrators to create, view, update, and soft-delete **buildings** (unique code, name, optional address/description, active flag). | M | P2 | T |
| FR-LOC-002 | Allow Administrators to manage **floors** within a building; enforce unique `(building, floor_number)`. | M | P2 | T |
| FR-LOC-003 | Allow Administrators to manage **rooms** within a floor with a `room_type` (laboratory/office/storage/server_room/faculty_room/library/other), unique code, optional room number, capacity (≥ 0), and active flag. | M | P2 | T |
| FR-LOC-004 | Cascade-soft-delete semantics: deleting a building/floor makes its child floors/rooms unavailable while preserving history; the system shall block deletion where it would orphan in-use PC units/assets/tickets, or reassign them first. | M | P2 | T |
| FR-LOC-005 | Present rooms, floors, and buildings as selectable location context throughout ticketing, assets, and maintenance. | M | P2 | D |
| FR-LOC-006 | Present a Location Management surface combining an **estate explorer** (buildings → floors, with per-level counts) and a **server-side directory** for buildings and rooms: pagination, search (building name/code/address; room name/code/number), filtering (room type, availability, archived visibility, and scope to a selected building or floor), and sorting on a fixed allow-list of columns. Selecting a node in the explorer scopes the directory. | M | P2 | T |
| FR-LOC-007 | Present estate metrics: buildings/floors/rooms totals with active, inactive and archived splits; seated capacity; the room-type mix; and occupancy — PC units placed versus unplaced, rooms holding no PC units, and buildings with no rooms. Metrics shall be computed as database aggregates. | M | P2 | T |
| FR-LOC-008 | Allow Administrators to take a building or room **out of service** (`is_active = false`) reversibly and without archiving: the record and its history are untouched, but neither it nor — for a building — anything beneath it is offered as location context. Floors carry no separate availability flag; a floor's availability derives from its building. | M | P2 | T |
| FR-LOC-009 | Where an archive is refused under [FR-LOC-004](#103-location-management-fr-loc), return a machine-readable **blocker report** (counts of live PC units, serialized assets, consumable stock lines and open tickets, plus the specific rooms responsible) and offer **occupant reassignment**: moving a room's live PC units, assets and consumable stock to another room in one transaction. Ticket location is history and is never rewritten — open tickets must be resolved or closed first. | M | P2 | T |
| FR-LOC-010 | Provide a complete, chronological **audit timeline** per building, floor and room (creation, edits with old→new values, floor moves, activation/deactivation, archive/restore including the cascade counts, and occupant reassignments). | M | P2 | T |
| FR-LOC-011 | Restrict the **entire Location Management module** — navigation, pages, estate explorer, metrics, directories, building/floor/room detail views, audit timelines and every create/update/activate/deactivate/archive/restore action — to Administrators (`locations.*`), enforced on the server for every request and not merely hidden in the client. Where another workflow needs a location field, expose only a **narrow lookup** confined to that form: it returns the labels of selectable locations (building · floor · room) and nothing else — no counts, capacity, custodianship, timestamps, archived rows or edit affordances — and is authorized by the permission of the workflow that needs it (e.g. `tickets.create`, `maintenance.view`, `assets.transfer`), never by a `locations.*` permission. | M | P2 | T |

### 10.4 PC Unit & Specification Management (FR-PC)

| ID | Requirement (the system *shall* …) | Class | Rel | Ver |
|---|---|:--:|:--:|:--:|
| FR-PC-001 | Allow Technicians/Administrators to create, view, update, and soft-delete **PC units** with a unique `unit_code`, `pc_name`, optional asset tag/hostname/serial/brand/model, optional room, and network fields (IP `inet`, MAC). | M | P2 | T |
| FR-PC-002 | Track PC `status` (available/assigned/online/offline/under_maintenance/retired) and `current_condition` (working/faulty/for_repair/decommissioned). | M | P2 | T |
| FR-PC-003 | Maintain a 1:1 **specification snapshot** (`pc_specifications`) per PC for fast display, editable independently of installed-component history. | M | P2 | T |
| FR-PC-004 | Maintain an authoritative **component installation history** (`pc_component_installations`) linking serialized assets to a PC, enforcing that a given asset is installed in at most one PC at a time. | M | P2 | T |
| FR-PC-005 | Enforce `warranty_expiration ≥ purchase_date` when both are present. | M | P2 | T |
| FR-PC-006 | Present, for any PC unit, a consolidated info view: specs, installed hardware, QR code, ticket history, maintenance timeline, and (P3) AI predictions. | M | P2 | D |

### 10.5 Ticketing / ITSM (FR-TKT)

| ID | Requirement (the system *shall* …) | Class | Rel | Ver |
|---|---|:--:|:--:|:--:|
| FR-TKT-001 | Allow any authenticated user to create a ticket with title, description, category, and optional priority, room, PC unit, tags, and attachments. | M | P2 | T |
| FR-TKT-002 | Assign each ticket a unique, human-readable `ticket_number` and an external `uuid` used in all URLs/APIs (never the numeric id). | M | P2 | T |
| FR-TKT-003 | Set a role-neutral `reporter_id` (the creating user by default) on every ticket; permit Technicians/Administrators to raise a ticket on behalf of another reporter. | M | P2 | T |
| FR-TKT-004 | Default a new ticket to the seeded default status (`Open`) and derive `response_due_at`/`resolution_due_at` from the selected priority’s SLA minutes. | M | P2 | T |
| FR-TKT-005 | Support the configurable status set (Open, Assigned, In Progress, On Hold, Resolved, Closed, Cancelled) with `is_open`/`is_terminal` semantics, and record every transition in `ticket_status_history` with actor and remarks. | M | P2 | T |
| FR-TKT-006 | Stamp lifecycle timestamps: `first_response_at` (first technician/admin response), `resolved_at`, `closed_at`, `reopened_at`. | M | P2 | T |
| FR-TKT-007 | Allow threaded comments, including `is_internal` notes visible only to Technicians/Administrators. | M | P2 | T |
| FR-TKT-008 | Allow attachments on tickets with server-side validation of MIME type (allow-list) and size (configurable max, default 10 MB), storing files outside the web root with a SHA-256 checksum. | M | P2 | T |
| FR-TKT-009 | Allow authenticated users to upvote a ticket at most once (`ticket_votes` unique per user), maintaining an accurate `upvote_count`. | M | P2 | T |
| FR-TKT-010 | Maintain accurate counter caches (`upvote_count`, `comment_count`, `attachment_count`) transactionally so they never drift. | M | P2 | T |
| FR-TKT-011 | Allow marking a ticket as a duplicate of another (`duplicate_of_id`), preventing self-reference, and surface the canonical ticket. | M | P2 | T |
| FR-TKT-012 | Provide an append-only activity feed (`ticket_updates`) capturing comments, status/priority changes, assignment, AI analysis, and system events. | M | P2 | T |
| FR-TKT-013 | Restrict a Teacher’s ticket views to tickets they reported (or are otherwise entitled to view); Technicians/Administrators may view all. | M | P2 | T |
| FR-TKT-014 | Provide full-text search over ticket title and description (Postgres `tsvector` + GIN) and filterable, sortable, paginated ticket lists (by status, priority, category, technician, room, tag, date range). | M | P2 | T |
| FR-TKT-015 | Support tags (`tags` + `ticket_tags`) for classification and search. | M | P2 | T |
| FR-TKT-016 | Allow reopening a Resolved or Closed ticket within a configurable window, stamping `reopened_at` and recording the transition (`Resolved` is non-terminal/awaiting confirmation; `Closed` is terminal). | M | P2 | T |
| FR-TKT-017 | Detect SLA breach when the current time passes `response_due_at` or `resolution_due_at` on a non-terminal ticket, flag the breach, and raise a notification. | M | P2 | T |
| FR-TKT-018 | Automatically escalate a breached ticket (e.g. raise priority / reassign per configurable rules). | F | — | T |

### 10.6 Technician Assignment & Workflow (FR-ASN)

| ID | Requirement (the system *shall* …) | Class | Rel | Ver |
|---|---|:--:|:--:|:--:|
| FR-ASN-001 | Allow Administrators (and permitted Technicians) to assign a ticket to a technician, creating a `technician_assignments` record and setting `tickets.assigned_technician_id`. | M | P2 | T |
| FR-ASN-002 | Enforce at most one **active** assignment per ticket (statuses pending/accepted/in_progress/on_hold). | M | P2 | T |
| FR-ASN-003 | Track the assignment lifecycle with timestamps: assigned, accepted, started, completed, declined (with reason), and support reassignment/cancellation. | M | P2 | T |
| FR-ASN-004 | Allow an assigned technician to accept, decline (with reason), start, hold, and complete their assignment; reflect the ticket status accordingly. | M | P2 | T |
| FR-ASN-005 | Notify the technician on assignment and the reporter/administrator on acceptance, decline, and completion. | M | P2 | T |
| FR-ASN-006 | Provide each technician a personal work queue filtered to their active assignments, ordered by priority and SLA due time. | M | P2 | D |

### 10.7 Maintenance (FR-MNT)

| ID | Requirement (the system *shall* …) | Class | Rel | Ver |
|---|---|:--:|:--:|:--:|
| FR-MNT-001 | Allow Technicians/Administrators to create maintenance records against a PC unit and/or a standalone asset, optionally linked to a ticket, with a maintenance type. | M | P2 | T |
| FR-MNT-002 | Support **preventive maintenance with no originating ticket** (`ticket_id` nullable) and a `scheduled_for` date. | M | P2 | T |
| FR-MNT-003 | Track maintenance status (scheduled/in_progress/on_hold/completed/cancelled) with `started_at`/`completed_at`, and capture diagnosis, root cause, resolution, preventive recommendation, downtime minutes (≥0), labor hours (≥0), and cost (≥0). | M | P2 | T |
| FR-MNT-004 | Support **checklist templates** and template items per maintenance type, instantiated into per-record checklists with completion tracking (who/when) and required-item enforcement before completion. | M | P2 | T |
| FR-MNT-005 | Allow attaching repair images typed `before`/`during`/`after`, and free-form maintenance notes. | M | P2 | T |
| FR-MNT-006 | Record **hardware replacements** during maintenance, referencing the old/new catalog component and the specific new serialized asset fitted (`new_asset_id`), with quantity (>0) and optional warranty months (≥0); update the PC’s installation history accordingly. | M | P2 | T |
| FR-MNT-007 | Generate preventive-maintenance due reminders based on the configurable default interval (`maintenance.default_interval_days`, default 90) and reminder lead time (`maintenance.reminder_days`, default 7). | M | P2 | T |
| FR-MNT-008 | Update the target PC unit’s `status`/`current_condition` when maintenance starts and completes (e.g. → `under_maintenance` → prior/working). | M | P2 | T |

### 10.8 Inventory & Asset Lifecycle (FR-AST)

| ID | Requirement (the system *shall* …) | Class | Rel | Ver |
|---|---|:--:|:--:|:--:|
| FR-AST-001 | Maintain a hardware catalog: manufacturers → hardware components (typed, generic) → hardware models (specific SKU, with JSON specifications). | M | P2 | T |
| FR-AST-002 | Manage **serialized assets** (1 row = 1 physical unit) with unique asset tag, optional unique serial number, hardware model, supplier, current room, status (in_stock/deployed/in_repair/reserved/in_transit/retired/disposed), condition, purchase price/date (≥0), and warranty. | M | P2 | T |
| FR-AST-003 | Manage **quantity-tracked consumables** with unique item code, unit of measure, `quantity_on_hand` (≥0), `reorder_level` (≥0), and unit cost. | M | P2 | T |
| FR-AST-004 | Record all consumable stock movements in an append-only `stock_transactions` ledger (stock_in/out/adjustment/transfer, non-zero quantity) and keep `quantity_on_hand` consistent with a running `balance_after`. | M | P2 | T |
| FR-AST-005 | Record every asset status change in `asset_status_history` with actor and reason. | M | P2 | T |
| FR-AST-006 | Support asset transfers between rooms (`asset_transfers`), keeping `assets.current_room_id` in sync. | M | P2 | T |
| FR-AST-007 | Support asset disposal (`disposal_records`) with method (recycled/sold/donated/destroyed/returned/lost), date, optional approver, document reference, and salvage value (≥0); set the asset status to `disposed`. | M | P2 | T |
| FR-AST-008 | Provide procurement requests with a unique request number, line items (catalog model or free-text, quantity >0, estimated unit price ≥0), and an approval workflow (draft → submitted → approved/rejected → fulfilled/cancelled) with approver, timestamp, and rejection reason. | M | P2 | T |
| FR-AST-009 | Restrict procurement approval to Administrators (or a permitted role). | M | P2 | T |
| FR-AST-010 | Raise a low-stock alert/notification when a consumable’s `quantity_on_hand` reaches or falls below its `reorder_level`. | M | P2 | T |
| FR-AST-011 | Provide filterable, searchable, paginated lists and detail views for assets, consumables, and catalog entities, with export ([FR-RPT-004](#21-reporting-requirements)). | M | P2 | T |
| FR-AST-012 | Prevent hard-deletion of catalog/reference entities that are in use (RESTRICT); soft-delete assets/consumables to preserve history. | M | P2 | T |

### 10.9 Audit & Activity Logging (FR-AUD)

| ID | Requirement (the system *shall* …) | Class | Rel | Ver |
|---|---|:--:|:--:|:--:|
| FR-AUD-001 | Record row-level create/update/delete/restore events on business entities in `audit_logs` with actor, entity reference, and JSON old/new value diffs. | M | P2 | T |
| FR-AUD-002 | Make `audit_logs` append-only and tamper-resistant (no UPDATE/DELETE via the application; enforced at the database). | M | P2 | I |
| FR-AUD-003 | Record application-level actions (who did what, in which module, on which subject) in `activity_logs` with IP and user agent. | M | P2 | T |
| FR-AUD-004 | Allow Administrators (permission `system.audit.view`) to search and filter audit and activity logs by user, entity, action, and date range. | M | P2 | T |

*(QR, AI, Floor Plan, Dashboard, Reporting, Notifications, and Settings functional requirements are specified in their dedicated sections [§18](#18-qr-code-requirements)–[§23](#23-system-settings-requirements).)*

---

## 11. Non-Functional Requirements

### 11.1 Overview
Non-functional requirements are grouped as: Security ([§12](#12-security-requirements)), Performance ([§13](#13-performance-requirements)), Scalability ([§14](#14-scalability-requirements)), Availability & Reliability ([§15](#15-availability--reliability-requirements)), Accessibility ([§16](#16-accessibility-requirements-wcag-22-aa)), plus Usability, Maintainability, Compatibility/Portability, and Observability below.

### 11.2 Quality attribute priorities
In case of conflict, resolve in this order: **Security & data integrity → Accessibility & correctness → Availability → Performance → Feature breadth.** (Rationale: this is a production system for a real client whose value is trustworthy history; correctness and accountability outrank speed.)

### 11.3 Usability Requirements (NFR-USB)

| ID | Requirement | Target / measure | Ver |
|---|---|---|:--:|
| NFR-USB-001 | A first-time requester shall be able to submit a valid ticket without training. | ≥ 90% task success; ≤ 2 minutes median. | T |
| NFR-USB-002 | The UI shall conform to the `DESIGN.md` design system (tokens, components, states, spacing). | 100% of components use role tokens; design review passes. | I |
| NFR-USB-003 | The UI shall provide light and dark themes, both meeting the accessibility contrast targets. | Both themes verified AA. | T |
| NFR-USB-004 | Every interactive control shall expose the full state set (default, hover, focus-visible, active, disabled, and loading/selected/error where relevant). | Inspection per component. | I |
| NFR-USB-005 | Data tables shall support sort, filter, pagination, a density toggle, and a teaching empty state. | Demonstration. | D |
| NFR-USB-006 | The UI shall provide a global command palette / search (⌘K) for navigation and record lookup. | Demonstration. | D |
| NFR-USB-007 | Numeric columns, IDs, and timestamps shall use tabular figures and consistent formatting per the configured locale/timezone. | Inspection. | I |

### 11.4 Maintainability Requirements (NFR-MTN)

| ID | Requirement | Target / measure | Ver |
|---|---|---|:--:|
| NFR-MTN-001 | Backend code shall pass the project quality gate (Pint formatting, Larastan level 6, Pest tests) in CI on every change. | CI green required to merge. | I |
| NFR-MTN-002 | Frontend code shall pass ESLint, Prettier, TypeScript strict checks, and Vitest in CI. | CI green required to merge. | I |
| NFR-MTN-003 | Business logic shall be organized by domain (modular monolith) so a new module is added without restructuring existing domains. | Inspection. | I |
| NFR-MTN-004 | Every closed enumeration domain shall have a single source of truth (a PHP enum mirroring the DB CHECK); user-configurable domains shall be lookup tables. | Inspection. | I |
| NFR-MTN-005 | Automated test coverage of domain business logic shall be ≥ 80% line coverage for P2 modules. | Coverage report. | A |
| NFR-MTN-006 | Public API responses shall be versioned and documented (OpenAPI). | Inspection. | I |

### 11.5 Compatibility & Portability Requirements (NFR-CMP)

| ID | Requirement | Target / measure | Ver |
|---|---|---|:--:|
| NFR-CMP-001 | The web client shall function on the current and previous major versions of Chrome, Edge, Firefox, and Safari. | Cross-browser test matrix. | T |
| NFR-CMP-002 | The UI shall be responsive and usable from 360 px (mobile) to ≥ 1920 px (desktop) without horizontal page scroll. | Responsive test. | T |
| NFR-CMP-003 | The UI shall remain usable and performant on aging, low-spec hardware and small screens common in target environments. | Manual test on a low-spec reference device. | D |
| NFR-CMP-004 | The system shall run in a Dockerized environment reproducible from `compose.yaml` with a documented clean-rebuild procedure. | Clean rebuild passes. | T |
| NFR-CMP-005 | Fonts and assets shall be self-hosted (no third-party CDN dependency) to work on networks that block external CDNs. | Inspection. | I |
| NFR-CMP-006 | The system shall present all user-facing dates/times in the configured system timezone and locale, storing all timestamps in UTC (`timestamptz`). | Test. | T |

### 11.6 Observability Requirements (NFR-OBS)

| ID | Requirement | Target / measure | Ver |
|---|---|---|:--:|
| NFR-OBS-001 | The application shall emit structured logs for requests, errors, jobs, and external-provider calls, with correlation identifiers. | Inspection. | I |
| NFR-OBS-002 | The system shall expose a health/readiness endpoint reporting database, cache, and queue connectivity. | Test. | T |
| NFR-OBS-003 | AI calls shall record token counts and latency (`prompt_tokens`, `completion_tokens`, `latency_ms`) for cost and performance observability. | Test. | T |
| NFR-OBS-004 | Failed background jobs shall be captured (failed-jobs store) and retriable without data loss. | Test. | T |

### 11.7 Internationalization (NFR-I18N)

| ID | Requirement | Class | Rel | Ver |
|---|---|:--:|:--:|:--:|
| NFR-I18N-001 | All user-facing strings shall be externalized (not hard-coded) to permit translation, and language must never be hard-coded to a “school” identity. | M | P2 | I |
| NFR-I18N-002 | The system shall support additional UI languages via locale resource files. | F | — | T |

---

## 12. Security Requirements

| ID | Requirement (the system *shall* …) | Class | Rel | Ver |
|---|---|:--:|:--:|:--:|
| NFR-SEC-001 | Expose only opaque external identifiers (`uuid`) in URLs and API responses for user-facing resources; never expose sequential numeric primary keys, to prevent IDOR/enumeration. | M | P2 | T |
| NFR-SEC-002 | Enforce authorization on every request via RBAC + per-user overrides (policies/gates); deny by default. | M | P2 | T |
| NFR-SEC-003 | Serve all traffic over HTTPS/TLS; set HSTS, secure/same-site cookies, and standard security headers (CSP, X-Content-Type-Options, X-Frame-Options/frame-ancestors). | M | P2 | I |
| NFR-SEC-004 | Validate and sanitize all input server-side (form requests); use parameterized queries exclusively (no string-built SQL). | M | P2 | I |
| NFR-SEC-005 | Store secrets (DB, mail, AI API keys) only in environment/secret stores — never in the database, repository, logs, or client bundle. | M | P2 | I |
| NFR-SEC-006 | Gate which settings may reach the frontend via `system_settings.is_public`; never send non-public settings (e.g. mail/AI credentials) to the client. | M | P2 | T |
| NFR-SEC-007 | Store uploaded files outside the web root; validate MIME/extension against an allow-list and enforce a max size; compute and store a SHA-256 checksum. | M | P2 | T |
| NFR-SEC-008 | Scan uploaded files for malware before they are made available for download. | F | — | T |
| NFR-SEC-009 | Rate-limit authentication and other sensitive endpoints to resist brute-force and abuse. | M | P2 | T |
| NFR-SEC-010 | Keep `audit_logs` append-only and tamper-resistant at the database level. | M | P2 | I |
| NFR-SEC-011 | Concentrate PII in identifiable tables, make it soft-deletable, and support a documented hard-purge on lawful erasure request. | M | P2 | T |
| NFR-SEC-012 | Enforce least-privilege on the AI surface: only Administrators may configure AI; only permitted roles may view AI output. | M | P3 | T |
| NFR-SEC-013 | Redact or minimize personal data sent to the external AI provider per [§17.5](#175-ai-data-governance--safety-requirements). | M | P3 | T |
| NFR-SEC-014 | Escape/encode all rendered user-supplied content to prevent stored/reflected XSS. | M | P2 | T |
| NFR-SEC-015 | Provide configurable retention/pruning for high-volume logs (`activity_logs`, `audit_logs`, `qr_scan_logs`, `login_history`, `ai_conversation_logs`) that preserves legal-hold requirements. | M | P2 | T |
| NFR-SEC-016 | Support MFA (TOTP) for privileged accounts. | F | — | T |
| NFR-SEC-017 | Log all privilege changes (role/permission grants and denies) to the audit trail with actor and before/after. | M | P2 | T |

---

## 13. Performance Requirements

Targets are stated at the 95th percentile (p95) under the nominal load defined in [§14](#14-scalability-requirements), measured server-side unless noted. Final targets confirmed with the client at approval ([§33 OI-05](#33-open-issues--decisions-requiring-client-approval)).

| ID | Requirement | Target (p95) | Ver |
|---|---|---|:--:|
| NFR-PERF-001 | Read API endpoints (list/detail) shall respond within a bounded latency. | ≤ 300 ms | A/T |
| NFR-PERF-002 | Write API endpoints (create/update) shall respond within a bounded latency. | ≤ 600 ms | A/T |
| NFR-PERF-003 | Initial SPA load (first contentful paint on the reference device) shall be bounded. | ≤ 2.5 s on broadband; ≤ 4 s on the low-spec reference device | T |
| NFR-PERF-004 | Subsequent in-app navigations shall be near-instant. | ≤ 400 ms perceived | D |
| NFR-PERF-005 | Full-text ticket/knowledge search shall return the first page. | ≤ 500 ms | A/T |
| NFR-PERF-006 | AI vector similarity search (RAG retrieval) shall return top-k results. | ≤ 300 ms for k ≤ 10 over the P3 corpus | A |
| NFR-PERF-007 | Every foreign-key column used in joins/filters shall be indexed; list queries shall not perform sequential scans on large tables. | Query-plan inspection | A |
| NFR-PERF-008 | Dashboard KPI widgets shall render within a bounded time using counter caches / pre-aggregation rather than live full-table aggregation on hot paths. | ≤ 1 s | A/T |
| NFR-PERF-009 | An end-to-end AI ticket analysis (excluding provider queue time) shall complete within a bounded time, executed asynchronously so the UI is never blocked. | ≤ 10 s typical; async | T |

---

## 14. Scalability Requirements

Nominal capacity assumptions for a single institutional deployment (basis for performance testing; a deployment may be smaller):

| ID | Requirement | Target | Ver |
|---|---|---|:--:|
| NFR-SCAL-001 | Support the nominal data volume: ≥ 5,000 PC units, ≥ 20,000 assets, ≥ 3,000 users, and ≥ 50,000 tickets/year without breaching performance targets. | Meets [§13](#13-performance-requirements) at volume | A |
| NFR-SCAL-002 | Support ≥ 200 concurrent active users (≥ 50 concurrent technicians) at nominal load within performance targets. | Load test | A |
| NFR-SCAL-003 | Scale horizontally at the application tier (stateless API behind a load balancer; sessions/cache in Redis). | Design inspection | I |
| NFR-SCAL-004 | Shape high-volume append-only log tables for future declarative range partitioning by `created_at` (keys chosen now; BRIN indexes in the interim). | Inspection | I |
| NFR-SCAL-005 | Isolate vector search in a dedicated embedding store (HNSW) so RAG scales independently of OLTP tables. | Inspection | I |
| NFR-SCAL-006 | Keep list/feed reads O(1) via counter caches rather than per-request aggregation. | Inspection | I |
| NFR-SCAL-007 | Convert log tables to monthly partitions and introduce analytics materialized views once volume warrants. | F | I |

---

## 15. Availability & Reliability Requirements

| ID | Requirement | Target | Ver |
|---|---|---|:--:|
| NFR-AVL-001 | The system shall be available ≥ 99.5% during defined business hours (excluding announced maintenance windows). | Monthly uptime ≥ 99.5% | A |
| NFR-AVL-002 | Planned downtime shall be scheduled via `maintenance_windows` and communicated to users in advance (banner/announcement). | Demonstration | D |
| NFR-AVL-003 | The database shall be backed up on a schedule meeting **RPO ≤ 24 h** (target ≤ 1 h with WAL/PITR where supported); backups recorded in `backup_history`. | Test | T |
| NFR-AVL-004 | The system shall be restorable from backup within **RTO ≤ 4 h**, verified by a documented restore drill. | Restore drill | T |
| NFR-AVL-005 | External-provider outages (AI, mail) shall degrade gracefully: core workflows remain available; affected actions queue or show a clear, non-blocking error. | Test | T |
| NFR-AVL-006 | Background jobs shall be idempotent and retriable; a failed job shall not corrupt state or double-apply effects. | Test | T |
| NFR-AVL-007 | Referential integrity and money/quantity constraints shall be enforced at the database (FK actions, CHECKs) so application bugs cannot silently corrupt data. | Inspection | I |

---

## 16. Accessibility Requirements (WCAG 2.2 AA)

Accessibility is a first-class, verified requirement — not a retrofit. The design system (`DESIGN.md`) has been contrast-verified in both themes.

| ID | Requirement (the system *shall* …) | Target / measure | Ver |
|---|---|---|:--:|
| NFR-ACC-001 | Meet **WCAG 2.2 Level AA** across all user-facing screens in both light and dark themes. | Automated + manual audit; 0 AA violations | T |
| NFR-ACC-002 | Provide text contrast ≥ 4.5:1 for body text and ≥ 3:1 for large text and UI component boundaries/focus indicators. | Contrast check | T |
| NFR-ACC-003 | Be fully operable by keyboard alone, with a visible focus indicator (2 px ring at 2 px offset) on every focusable element; no keyboard traps. | Keyboard test | T |
| NFR-ACC-004 | Convey state (status, errors, selection) by text/shape/icon in addition to color — never by color alone. | Inspection | I |
| NFR-ACC-005 | Honor `prefers-reduced-motion`; motion shall be functional (150–250 ms) and never required to understand state. | Test | T |
| NFR-ACC-006 | Use semantic structure and ARIA appropriately (landmarks, labels, roles); associate every form control with a label and expose validation errors programmatically. | Screen-reader test | T |
| NFR-ACC-007 | Support browser zoom/text resize to 200% without loss of content or function. | Test | T |
| NFR-ACC-008 | Ensure target sizes and drag interactions (incl. the future floor plan) meet WCAG 2.2 target-size and dragging-movement criteria, providing a non-drag alternative. | Test | T |
| NFR-ACC-009 | Meet the target-size minimum (24×24 CSS px) for pointer targets. | Inspection | I |

---

## 17. AI Requirements

> **Class:** Mandatory, **Rel P3** unless marked otherwise. AI is grounded in the organization’s own data (RAG) and governed by the safety and data-governance rules in [§17.5](#175-ai-data-governance--safety-requirements). AI features are **configurable and default to conservative settings**: predictions, learning, and auto-article generation ship **disabled**.

### 17.1 AI scope
SccIT integrates Google **Gemini** for (a) **ticket triage/analysis**, (b) a **conversational troubleshooting assistant** grounded in a **RAG knowledge base** over `pgvector`, and (opt-in) (c) **predictive maintenance** and (d) **auto-drafted knowledge articles**. An `ai_models` registry, `ai_system_settings` singleton, and full logging (analysis, conversation, feedback, embeddings) are already modeled.

### 17.2 Seeded AI configuration (baseline)
- Chat model: **Gemini 1.5 Flash** (`gemini-1.5-flash`), default active.
- Embedding model: **Gemini text-embedding-004**, **768 dimensions** (must equal `ai_embeddings.embedding` dimension).
- Confidence threshold: **0.70**. Predictions/learning/auto-articles: **disabled** by default.

### 17.3 AI functional requirements

| ID | Requirement (the system *shall* …) | Class | Rel | Ver |
|---|---|:--:|:--:|:--:|
| FR-AI-001 | On ticket submission (or on demand), perform AI analysis producing a problem category, severity, estimated resolution minutes, a `technician_required` flag, a summary, and step recommendations, recorded in `ai_analysis_logs` with a confidence score ∈ [0,1]. | M | P3 | T |
| FR-AI-002 | Cache the latest analysis snapshot on the ticket (`ai_summary`, `ai_confidence`) while retaining full history in `ai_analysis_logs`. | M | P3 | T |
| FR-AI-003 | Present AI-recommended troubleshooting steps to the reporter and let them mark steps complete (`ai_recommendations.is_completed`, `completed_by`). | M | P3 | T |
| FR-AI-004 | Provide a conversational assistant that answers troubleshooting questions grounded in retrieved knowledge (RAG), logging each turn in `ai_conversation_logs` grouped by `conversation_id` with token/latency metrics. | M | P3 | T |
| FR-AI-005 | Maintain a RAG knowledge base (`ai_knowledge_articles`) with draft/published/archived status, full-text search, and vector embeddings; only published articles are visible to Teachers. | M | P3 | T |
| FR-AI-006 | Generate and store embeddings (`ai_embeddings`, 768-dim) for indexable sources (tickets, ticket comments, maintenance records, knowledge articles) and track indexing state/staleness in `ai_embedding_sources` (pending/processing/indexed/failed/stale) via `content_hash`. | M | P3 | T |
| FR-AI-007 | Retrieve top-k relevant context via HNSW cosine similarity to ground assistant/triage responses, and cite the source records used. | M | P3 | T |
| FR-AI-008 | Capture user feedback on AI output (`ai_feedback`: helpful flag, 1–5 rating, free text), one feedback per user per recommendation. | M | P3 | T |
| FR-AI-009 | Suggest possible duplicate tickets using similarity, for human confirmation (never auto-merge). | M | P3 | T |
| FR-AI-010 | Gate automated AI actions behind the configured confidence threshold; below threshold, present output as advisory only and require human action. | M | P3 | T |
| FR-AI-011 | When enabled, generate predictive-maintenance predictions per PC (`ai_predictions`: predicted issue, probability ∈ [0,1], horizon days, explanation, status). | F/M-opt | P3 | T |
| FR-AI-012 | When enabled, detect and maintain failure patterns per PC/component (`ai_failure_patterns`) from learning events (`ai_learning_events`). | F/M-opt | P3 | T |
| FR-AI-013 | When enabled, auto-draft knowledge articles from resolved tickets/maintenance for human review before publish. | F/M-opt | P3 | T |
| FR-AI-014 | Allow Administrators to configure the active chat model, embedding model, confidence threshold, and feature toggles via `ai_system_settings`. | M | P3 | T |
| FR-AI-015 | Register and switch provider models via `ai_models` without code change (Gemini-ready; provider-abstracted). | M | P3 | I |

> **M-opt** = mandatory to *support* (schema + toggle), but shipped **disabled**; enabling is a client decision.

### 17.4 AI reliability requirements

| ID | Requirement | Class | Rel | Ver |
|---|---|:--:|:--:|:--:|
| FR-AI-020 | If AI is disabled or the provider is unreachable, all non-AI workflows shall remain fully functional; AI surfaces shall show a clear, non-blocking unavailable state. | M | P3 | T |
| FR-AI-021 | Run AI analysis and embedding generation asynchronously (queued jobs) so user-facing requests are never blocked on the provider. | M | P3 | T |
| FR-AI-022 | Handle provider errors, timeouts, and rate limits with retry/backoff, and record failures for observability. | M | P3 | T |

### 17.5 AI data governance & safety requirements

| ID | Requirement (the system *shall* …) | Class | Rel | Ver |
|---|---|:--:|:--:|:--:|
| FR-AI-030 | Send to the external AI provider only the minimum data necessary for the task, and redact/minimize personal data (names, emails, contact numbers) from prompts and embedded content where not essential. | M | P3 | T |
| FR-AI-031 | Clearly label AI-generated content as AI-generated wherever it is shown. | M | P3 | I |
| FR-AI-032 | Never allow AI to autonomously perform irreversible or state-changing operations (close/delete tickets, dispose assets, change permissions); AI output is advisory and requires human confirmation. | M | P3 | T |
| FR-AI-033 | Make external AI processing transparent to Administrators (which model, what data categories) and configurable/disable-able. | M | P3 | D |
| FR-AI-034 | Store AI conversation/analysis logs under the same retention and access controls as other PII-bearing data. | M | P3 | I |

---

## 18. QR Code Requirements

| ID | Requirement (the system *shall* …) | Class | Rel | Ver |
|---|---|:--:|:--:|:--:|
| FR-QR-001 | Generate a QR code bound to **exactly one** target — a PC unit **or** a standalone asset (`num_nonnulls(pc_unit_id, asset_id) = 1`) — with a unique canonical `code` and optional human-readable location label. | M | P2 | T |
| FR-QR-002 | Store the canonical scannable value in `qr_codes.code`; treat `pc_units.qr_identifier` as a denormalized convenience copy kept in sync. | M | P2 | T |
| FR-QR-003 | Render a printable QR image at the configured default size (`qr.default_size`, default 256 px) and error-correction level (`qr.error_correction`, default M). | M | P2 | T |
| FR-QR-004 | Track QR lifecycle status (active/inactive/revoked) and `generated_at`/`last_scanned_at`. | M | P2 | T |
| FR-QR-005 | On scan, verify the code and resolve the target, returning the target’s live info panel to the scanning user; record every scan in `qr_scan_logs` with result (success/invalid/expired/mismatch), scanner, IP, and optional geolocation (validated: lat ∈ [-90,90], lon ∈ [-180,180]). | M | P2 | T |
| FR-QR-006 | Classify a scan result deterministically: **success** (active code, resolvable live target); **invalid** (unknown code); **mismatch** (code resolves but points to a different/moved target than claimed); **expired** (code whose status is `inactive`/`revoked` or whose target is `retired`/`disposed`). | M | P2 | T |
| FR-QR-007 | Allow Administrators/Technicians to regenerate or revoke a QR code, preserving prior scan history. | M | P2 | T |
| FR-QR-008 | Support QR-initiated maintenance: a scan may start/attach a maintenance record for the resolved target (`qr_scan_logs.maintenance_record_id`). | M | P2 | T |
| FR-QR-009 | Function on standard mobile-browser cameras without a native app. | M | P2 | D |

> **Inconsistency flagged & resolved:** the `scan_result` domain includes `expired`, but `qr_codes` has no explicit expiry timestamp. Resolution: `expired` is defined behaviorally (FR-QR-006) against QR/target status rather than a date. If time-based QR expiry is later required, add `qr_codes.expires_at` (see [§33 OI-04](#33-open-issues--decisions-requiring-client-approval)).

---

## 19. Interactive Floor Plan Requirements

> **Class:** Mandatory, **Rel P4** (later). The data model and seams are already in place (`buildings → floors → rooms → room_layouts → floor_plan_positions`; Reverb env placeholders). No floor-plan business logic exists yet. The brief mandates it be addable **without major refactoring** — these requirements govern that build.

| ID | Requirement (the system *shall* …) | Class | Rel | Ver |
|---|---|:--:|:--:|:--:|
| FR-FP-001 | Allow Administrators to create per-room **layouts** (`room_layouts`) with a canvas width/height (>0) and grid size (>0, default 20 px), an optional background image, versioning, and at most one **active** layout per room. | M | P4 | T |
| FR-FP-002 | Render an interactive canvas showing PC units positioned within the active room layout, with pan and zoom. | M | P4 | D |
| FR-FP-003 | Allow Administrators to place, drag-and-drop, snap-to-grid, rotate, and layer (`z_index`) PC icons, persisting positions to `floor_plan_positions` (unique per layout+PC; coordinates ≥ 0). | M | P4 | T |
| FR-FP-004 | Color PC icons by `status` (available/assigned/online/offline/under_maintenance/retired) using the universal status palette, with a text/shape label so meaning survives color-blindness. | M | P4 | T |
| FR-FP-005 | Open a click-through info panel for a PC showing specs, installed hardware, QR, ticket history, maintenance timeline, and (if enabled) AI predictions. | M | P4 | D |
| FR-FP-006 | Restrict layout editing to Administrators (permission `floorplan.manage`); Technicians/others may view (`floorplan.view`). | M | P4 | T |
| FR-FP-007 | Reflect PC position and status changes to other viewers in real time via Laravel Reverb. | M | P4 | T |
| FR-FP-008 | Preserve position history across layout versions and support a PC moving between rooms without data loss. | M | P4 | T |
| FR-FP-009 | Meet accessibility requirements for drag interactions, providing a keyboard/non-drag alternative for placement ([NFR-ACC-008](#16-accessibility-requirements-wcag-22-aa)). | M | P4 | T |
| FR-FP-010 | Support network-topology visualization, heat maps, asset-density overlays, AI-predicted failures on the map, and indoor navigation. | F | — | — |

---

## 20. Dashboard Requirements

| ID | Requirement (the system *shall* …) | Class | Rel | Ver |
|---|---|:--:|:--:|:--:|
| FR-DSH-001 | Present a role-aware landing dashboard: Administrators see cross-organization operations; Technicians see their queue and workload; Teachers see their tickets and quick actions. | M | P2 | D |
| FR-DSH-002 | Support configurable widgets (`dashboard_widgets`) of types counter, line/bar/pie chart, table, list, map, timeline, gauge, with per-user layout and global defaults (`user_id` NULL). | M | P2 | T |
| FR-DSH-003 | Provide, for Administrators, KPI widgets covering: open-ticket backlog, tickets by status/priority/category, SLA compliance and breaches, MTTR and first-response time, technician workload, assets by status, low-stock consumables, and upcoming preventive maintenance. | M | P2 | D |
| FR-DSH-004 | Provide, for Technicians, widgets for assigned/active tickets, tickets nearing SLA breach, and scheduled maintenance. | M | P2 | D |
| FR-DSH-005 | Compute KPI widgets from counter caches / efficient aggregates so the dashboard meets [NFR-PERF-008](#13-performance-requirements). | M | P2 | A |
| FR-DSH-006 | Allow users to enable/disable and reorder their widgets; persist the arrangement. | M | P2 | T |
| FR-DSH-007 | Render every chart accessibly (labels, legends, non-color encoding) and with a data-table fallback. | M | P2 | T |
| FR-DSH-008 | Assemble each dashboard **server-side from the caller's effective permissions**: the role selects the layout, and every widget is included only if the caller holds the permission that widget's data belongs to. A payload shall never contain figures the caller is not entitled to see, so no client-side filtering of privileged data is required. | M | P2 | T |

*(Charts follow the project data-visualization guidance: accessible categorical/sequential palettes, consistent in light and dark.)*

> **Delivered in Phase 2.4:** FR-DSH-001, 003, 004, 005, 007 and 008 — the three role dashboards (Administrator operations, Technician queue, Teacher requests), computed from database aggregates and served through a single permission-gated endpoint. Distributions are rendered as labelled proportional rows whose markup *is* the data table (no charting dependency, no colour-only encoding). **Deferred:** FR-DSH-002 and FR-DSH-006 — per-user widget selection, ordering and persistence; the `dashboard_widgets` table is reserved for them and is untouched.

---

## 21. Reporting Requirements

| ID | Requirement (the system *shall* …) | Class | Rel | Ver |
|---|---|:--:|:--:|:--:|
| FR-RPT-001 | Provide on-demand operational reports: ticket volume/aging/backlog, SLA compliance, MTTR/FRT trends, reopen rate, technician performance, maintenance history and cost, asset inventory and valuation, asset lifecycle/disposal, consumable stock and reorder, and procurement status. | M | P2 | D |
| FR-RPT-002 | Allow filtering reports by date range, building/floor/room, category/priority/status, technician, and asset attributes; date bucketing shall be timezone-correct. | M | P2 | T |
| FR-RPT-003 | Restrict report access to permitted roles (`reports.view`) and export to `reports.export`. | M | P2 | T |
| FR-RPT-004 | Export report and list data to CSV and PDF (and XLSX where applicable); exports reflect the active filters. | M | P2 | T |
| FR-RPT-005 | Compute reporting metrics from the authoritative history/lifecycle tables (`ticket_status_history`, `asset_status_history`, `stock_transactions`, maintenance records) and ticket lifecycle timestamps. | M | P2 | I |
| FR-RPT-006 | Provide analytics via materialized views refreshed on a schedule for heavy aggregates. | F | — | A |
| FR-RPT-007 | Allow saving and scheduling reports for recurring delivery (`report_definitions`, `scheduled_reports`). | F | — | T |

> **Gap flagged:** `report_definitions`/`scheduled_reports` tables are **not** in the current schema (deliberately deferred). Saved/scheduled reports (FR-RPT-006/007) are Future; P2 reporting is on-demand + export.

---

## 22. Notification Requirements

| ID | Requirement (the system *shall* …) | Class | Rel | Ver |
|---|---|:--:|:--:|:--:|
| FR-NOT-001 | Deliver in-app notifications via a notification center (`notifications`: type, title, message, JSON data, optional action URL, read state) with an unread badge count. | M | P2 | T |
| FR-NOT-002 | Support delivery channels in-app and email (`notification_channel`), honoring per-user, per-type channel preferences (`notification_preferences`, unique per user+channel+type). | M | P2 | T |
| FR-NOT-003 | Generate notifications for at least: ticket assigned/reassigned; ticket status change; new comment on a followed/owned ticket; SLA breach/nearing breach; maintenance scheduled/due; low-stock reorder; procurement approval/rejection; account lockout; and new announcements. | M | P2 | T |
| FR-NOT-004 | Mark notifications read/unread individually and in bulk; persist `read_at`. | M | P2 | T |
| FR-NOT-005 | Respect notification type set (info/success/warning/error/ticket_update/assignment/announcement/maintenance/system) for filtering and display. | M | P2 | T |
| FR-NOT-006 | Send email notifications through the configured mail transport, with content safe for external delivery (no secrets, minimal PII). | M | P2 | T |
| FR-NOT-007 | Push in-app notifications in real time via Reverb (with polling fallback). | F | — | T |
| FR-NOT-008 | Support a daily digest email (`notifications.digest_enabled`) aggregating unread items. | M | P2 | T |

### 22.1 Announcements

| ID | Requirement (the system *shall* …) | Class | Rel | Ver |
|---|---|:--:|:--:|:--:|
| FR-NOT-010 | Allow Administrators (`system.announcements.manage`) to publish announcements targeted to an audience (all/teachers/technicians/admins), with optional start/end window (`ends_at > starts_at`), active flag, and pin flag. | M | P2 | T |
| FR-NOT-011 | Display active, in-window announcements to the targeted audience; pinned announcements appear first. | M | P2 | D |

---

## 23. System Settings Requirements

| ID | Requirement (the system *shall* …) | Class | Rel | Ver |
|---|---|:--:|:--:|:--:|
| FR-CFG-001 | Provide a single centralized configuration store (`system_settings`) keyed by unique `key`, grouped by taxonomy (school, academic, maintenance, ai, email, qr, floor_plan, branding, theme, dashboard, notifications, system, general), with a typed JSON value. | M | P2 | T |
| FR-CFG-002 | Restrict create/update/delete of settings to Administrators (`system.settings.manage`) enforced by policy; Teachers/Technicians may read only `is_public` settings. | M | P2 | T |
| FR-CFG-003 | Prevent deletion of settings flagged `is_protected` (system-critical seeded keys). | M | P2 | T |
| FR-CFG-004 | Expose only `is_public` settings to the frontend; keep non-public settings (e.g. mail/AI credentials) server-side. | M | P2 | T |
| FR-CFG-005 | Allow configuring organization identity/branding (name, address, contact, logo, favicon, primary color, theme mode) so the deployment re-brands **without code change** (satisfies [BO-08](#5-business-objectives)). | M | P2 | T |
| FR-CFG-006 | Allow configuring operational defaults: academic year/semester, maintenance interval and reminder lead, QR default size and error-correction, floor-plan grid size and snap, default notification channel and digest, AI assistant toggle, system timezone and date format. | M | P2 | T |
| FR-CFG-007 | Validate setting values against their declared `type` (string/integer/boolean/float/json/array) before persisting. | M | P2 | T |
| FR-CFG-008 | Apply setting changes without requiring a redeploy (read at runtime, cached with invalidation on change). | M | P2 | T |
| FR-CFG-009 | Keep strongly-typed AI runtime configuration (active/embedding model, threshold, feature toggles) in `ai_system_settings`, distinct from general key/value settings. | M | P3 | I |

---

## 24. External Interface Requirements

### 24.1 User interfaces (EIF-UI)
- **EIF-UI-001 (M/P2):** The system shall present a responsive SPA (React 19 + TS) conforming to `DESIGN.md` and [§16](#16-accessibility-requirements-wcag-22-aa), served from a single origin.
- **EIF-UI-002 (M/P2):** The UI shall provide left-sidebar primary navigation (collapsible), a top bar with global search/command palette, notifications, org/theme switch, and user menu.
- **EIF-UI-003 (M/P2):** The UI shall support light/dark themes driven by the design tokens and the configured default.

### 24.2 Application programming interfaces (EIF-API)
- **EIF-API-001 (M/P2):** The system shall expose a versioned REST API under `/api`, returning JSON, authenticated via Sanctum session cookies.
- **EIF-API-002 (M/P2):** API resources shall be addressed by `uuid` (never numeric id) for user-facing entities.
- **EIF-API-003 (M/P2):** The API shall use standard HTTP status codes, consistent error envelopes, pagination, filtering, and sorting conventions.
- **EIF-API-004 (M/P2):** The API contract shall be documented (OpenAPI) and kept in sync with the implementation.

### 24.3 External service interfaces (EIF-EXT)
- **EIF-EXT-001 (M/P3):** The system shall integrate the Google Gemini API for chat completion and text embeddings via server-side calls with the API key held in server secrets, subject to [§17.5](#175-ai-data-governance--safety-requirements).
- **EIF-EXT-002 (M/P2):** The system shall send email via a configurable SMTP transport (Mailpit in development).
- **EIF-EXT-003 (F/P4):** The system shall provide real-time updates via Laravel Reverb (WebSockets).
- **EIF-EXT-004 (M/P2):** The system shall use Redis for cache and queue backends.

### 24.4 Data/storage interfaces (EIF-DAT)
- **EIF-DAT-001 (M/P2):** The system shall persist to PostgreSQL 17 with `pgvector`, `citext`, and `pgcrypto` extensions enabled.
- **EIF-DAT-002 (M/P2):** The system shall store uploaded files (attachments, repair images, branding assets) on a configurable disk (local by default), outside the web root, referenced by storage path + checksum.

### 24.5 Hardware interfaces (EIF-HW)
- **EIF-HW-001 (M/P2):** The system shall capture QR scans via a device camera through the browser (no native app; no specialized scanner required).

---

## 25. Business Rules

| ID | Business Rule |
|---|---|
| BR-01 | The three system roles (Administrator, Technician, Teacher) are seeded, `is_system`, and cannot be deleted. |
| BR-01a | Only **Teacher** and **Technician** accounts may be created via the public registration-request workflow; **Administrator** accounts are never self-registerable and are provisioned only by an existing Administrator (or the documented local dev seeder). A registration request is created `pending` and cannot authenticate until an Administrator approves it. *(OI-02, resolved.)* |
| BR-02 | A user holds exactly one role; effective permissions = role permissions adjusted by per-user grant/deny overrides. *(Multi-role is a flagged future decision — [§33 OI-01](#33-open-issues--decisions-requiring-client-approval).)* |
| BR-03 | Every ticket has a role-neutral reporter who is a registered, non-deleted user; there is no anonymous ticket submission in P2. |
| BR-04 | Each ticket always has exactly one current status; exactly one status is the system default (`Open`). |
| BR-05 | SLA due times are derived from the ticket’s priority (`response_time_minutes`, `resolution_time_minutes`). Seeded SLAs: Low 480/4320, Medium 240/2880, High 60/1440, Critical 15/480 minutes. |
| BR-06 | A ticket may be marked a duplicate of another ticket but never of itself. |
| BR-07 | At most one technician assignment is active per ticket at any time. |
| BR-08 | A serialized asset is installed in at most one PC unit at a time. |
| BR-09 | A QR code identifies exactly one target: a PC unit or a standalone asset. |
| BR-10 | Consumable stock is changed only through `stock_transactions`; `quantity_on_hand` must never be negative. |
| BR-11 | Procurement requests follow draft → submitted → approved/rejected → fulfilled/cancelled; approval is Administrator-gated and audited. |
| BR-12 | Business/master entities are soft-deleted (never hard-deleted) so history and attributions survive; reference/lookup data in use cannot be deleted (RESTRICT). |
| BR-13 | Audit log entries are immutable once written. |
| BR-14 | Only `is_public` settings may be exposed to the client; `is_protected` settings cannot be deleted. |
| BR-15 | AI output is advisory; no AI action performs an irreversible/state-changing operation without human confirmation. |
| BR-16 | The brand identity is a single primary color token plus branding settings; status colors and neutrals are never re-skinned per vertical. |
| BR-17 | All timestamps are stored in UTC and displayed in the configured system timezone. |
| BR-18 | A closed/resolved ticket may be reopened within the configurable reopen window; reopening is recorded. |
| BR-19 | Preventive maintenance may exist without a ticket; corrective maintenance is typically linked to one. |
| BR-20 | Money is stored as `numeric(12,2)`; probabilities/confidence/scores as values in [0,1]; quantities are non-negative. |

---

## 26. Operational Scenarios

**OS-1 — Teacher reports a broken projector (self-service + AI deflection).**
A teacher signs in, clicks “New ticket,” selects category *Peripheral*, describes the issue, optionally selects the room, and submits. AI triage (P3) returns likely-cause steps at ≥ threshold confidence; the teacher follows a step, the projector works, and marks the ticket resolved — no technician needed. If unresolved, the ticket remains open and enters the technician queue.

**OS-2 — Technician resolves a hardware fault on the floor.**
A technician scans the PC’s QR code with a phone; the info panel shows specs, installed parts, and ticket/maintenance history. They accept the assignment, start it (PC → under_maintenance), replace a faulty RAM stick (recording the hardware replacement and the specific new serialized asset), attach before/after photos, complete the checklist, and complete the record. The PC returns to working; stock and asset histories update; the reporter is notified.

**OS-3 — Administrator runs the monthly operations review.**
An administrator opens the dashboard: backlog, SLA compliance, MTTR trend, technician workload, assets by status, low-stock items, upcoming preventive maintenance. They export an SLA-compliance report (PDF) filtered to the last month and one building for a leadership meeting.

**OS-4 — Preventive maintenance cycle.**
Ninety days after the last service, the system flags due PCs and notifies technicians seven days ahead. A technician performs the scheduled inspection using the preventive checklist template; the record is logged with no originating ticket.

**OS-5 — Procurement of new equipment.**
A technician raises a procurement request with line items; an administrator reviews and approves it; on fulfillment, received units are entered as serialized assets and/or consumable stock; assets are later deployed and QR-tagged.

**OS-6 — SLA breach.**
A high-priority ticket approaches its resolution deadline; the assigned technician and administrator are notified as it nears breach, and the ticket is flagged breached if the deadline passes. *(Auto-escalation is a future enhancement, [FR-TKT-018](#105-ticketing--itsm-fr-tkt).)*

**OS-7 — AI/provider outage.**
The Gemini API is unreachable. Ticket creation, assignment, maintenance, inventory, dashboards, and reporting continue to work; AI panels show an unavailable state; queued AI analyses retry with backoff when the provider recovers.

**OS-8 — Re-brand for a new vertical.**
An administrator at a new (non-school) deployment sets the organization name, uploads a logo, sets the primary color and default theme, and adjusts locale/timezone — the platform re-skins with no code change.

---

## 27. Use Cases

![SccIT System Use Case Diagram](diagrams/use-case-diagram.png)

**Figure 2. System Use Case Diagram.** A single UML use-case model of the whole platform inside one system boundary — *the AI-Powered School IT Asset & Service Management System* — drawn to standard UML notation: **stickman actors** outside the boundary, use-case **ovals** inside it, solid **associations**, and an `«extend»` relationship. **Human actors** on the left are the *Public Visitor* (unauthenticated) and the three signed-in roles — *Teacher/Requester*, *Technician*, and *Administrator*. No abstract actor or actor generalization is used: each concrete role is **connected directly** to every common use case it performs (log out, change password, manage profile, view dashboard, track/comment on tickets, use AI, manage notifications), which keeps the model immediately readable. **External/system actors** on the right are the *AI Provider*, the *Email Service*, the *Notification Service*, and the *QR Scanner*, each associated with the use cases it serves. The diagram represents the **complete specified functional scope** — authentication & registration, profile & password management, dashboards, the full ticket lifecycle (submit, assign, track, comment, resolve), asset · inventory · PC-unit · procurement management, QR-code management and scanning, corrective and preventive maintenance, AI troubleshooting · knowledge base · recommendations, locations and floor plan, notifications and announcements, reports · analytics · audit logs, user · role management, and system settings. Relationships shown are actor **generalization** (the three roles are a *User*) and a single `«extend»` — *Attach Files* optionally extends *Submit Ticket*.

> This is a *requirements* model: it depicts the **complete required behaviour** of the specified system and carries **no implementation-status, delivery-phase, or roadmap annotations** (delivery sequencing is tracked in the SPMP, not this SRS). Every use case traces to functional requirements in [§10](#10-functional-requirements)–[§23](#23-system-settings-requirements); account provisioning uses the **registration-request + Administrator-approval** workflow ([FR-AUTH-013..016](#101-authentication--session-management-fr-auth)), so *Approve Registration* is an Administrator use case and there is no separate self-service email-verification use case (approval stamps verification). Administrators additionally hold all permissions ([§8.1](#81-administrator)). The condensed use-case narratives (**UC-01 … UC-14**) below elaborate the principal flows.

### 27.1 Use-case summaries

Format: **UC-n — Name** · *Actor(s)* · Pre → Main flow → Post; key alternates. Traces to FRs.

**UC-01 — Submit ticket.** *Reporter.* Pre: authenticated. Flow: open form → fill required fields → attach files → submit. Post: ticket created with number/uuid, default status, SLA due times, reporter set; activity + notification emitted. Alt: validation error returns field messages. *(FR-TKT-001..008)*

**UC-02 — Triage & assign ticket.** *Administrator/Technician.* Pre: open ticket exists. Flow: review (with AI summary if P3) → set priority/category/tags → assign technician. Post: assignment created (one active), status → Assigned, technician notified. Alt: reassign, decline. *(FR-ASN-001..005, FR-TKT-005)*

**UC-03 — Work & resolve ticket.** *Technician.* Pre: assigned. Flow: accept → start → comment/internal notes → (optional) create maintenance record → resolve. Post: `first_response_at`/`resolved_at` stamped; reporter notified. Alt: put on hold; escalate. *(FR-ASN-004, FR-TKT-006/007, FR-MNT-001)*

**UC-04 — Perform maintenance.** *Technician.* Pre: PC/asset exists. Flow: create/scheduled record → run checklist → replace hardware → attach images/notes → complete. Post: histories updated; PC status restored; costs/downtime captured. *(FR-MNT-001..008)*

**UC-05 — Scan QR to verify equipment.** *Technician.* Pre: QR exists & active. Flow: scan → verify → view live info panel. Post: scan logged with result. Alt: invalid/mismatch/expired handled per FR-QR-006. *(FR-QR-005/006)*

**UC-06 — Manage asset lifecycle.** *Technician/Administrator.* Flow: create asset → deploy/install → transfer → repair → dispose. Post: status history complete. *(FR-AST-002..007)*

**UC-07 — Approve procurement.** *Administrator.* Pre: submitted request. Flow: review items → approve/reject (reason). Post: status + approver recorded; requester notified. *(FR-AST-008/009)*

**UC-08 — Configure system & branding.** *Administrator.* Flow: edit settings → validate by type → save. Post: applied at runtime; audited; protected keys preserved. *(FR-CFG-001..008)*

**UC-09 — Manage users & permissions.** *Administrator.* Flow: create/edit user → set role → adjust overrides. Post: access reflects changes; audited. *(FR-USER-001..010)*

**UC-09a — Register & get approved.** *Prospective Teacher/Technician + Administrator.* Flow: applicant submits a registration request (role Teacher or Technician) → account created `pending`, cannot sign in → applicant sees “awaiting approval” → Administrator reviews the request and approves or rejects (with optional reason) → applicant is emailed the decision; on approval the account becomes `active` and can sign in, on rejection it becomes `rejected`. Post: decision recorded in the audit trail; rejected requests retained with reason. *(FR-AUTH-013..016; BR-01a)*

**UC-10 — Use AI assistant.** *Reporter/Technician.* Flow: ask question → assistant retrieves KB context → answers with citations → user rates answer. Post: conversation + feedback logged. *(FR-AI-004..008)*

**UC-11 — View dashboard & export report.** *Administrator/Technician.* Flow: open dashboard → filter → export. Post: file generated reflecting filters. *(FR-DSH-001..006, FR-RPT-001..004)*

**UC-12 — Edit floor plan (P4).** *Administrator.* Flow: open room layout → drag/snap PCs → save; viewers see live updates. Post: positions persisted; broadcast. *(FR-FP-001..008)*

**UC-13 — Publish announcement.** *Administrator.* Flow: compose → target audience → schedule window → publish. Post: shown to audience. *(FR-NOT-010/011)*

**UC-14 — Recover access (forgot password).** *Any user.* Flow: request reset → receive token link → set new password. Post: sessions invalidated; login recorded. *(FR-AUTH-008/011)*

### 27.2 Detailed Use Case Specifications

These specifications elaborate the principal use cases end-to-end in the ISO/IEC/IEEE 29148 style — **Preconditions, Trigger, Main Flow, Alternate Flows, Exceptions, Postconditions** — including UI/page touchpoints, AI decision points, database effects, notifications, and role redirects. Every specification traces to the functional requirements ([§10](#10-functional-requirements)–[§23](#23-system-settings-requirements)) and the physical data model (`database_design_v2.dbml`); nothing here introduces behaviour beyond the defined scope. The authentication, registration-approval, profile, and password flows are **implemented (Phase 2.2–2.3)**; ticketing, assignment, maintenance, QR, assets, and AI flows are **specified and scaffolded** for their respective phases ([§3.4](#34-release-phasing-summary)).

**UCS-01 — Log In & Role-Based Redirect.**
- **Primary actor:** Teacher/Requester, Technician, or Administrator (a registered user). **Secondary actors:** Email Service, Notification Service.
- **Preconditions:** the person has an `active` account; the SPA is loaded over the single HTTPS origin.
- **Trigger:** the user submits the login form (email + password).
- **Main flow:** (1) user submits email (case-insensitive) + password; (2) system verifies the salted hash and establishes a Sanctum SPA cookie session ([FR-AUTH-001/002](#101-authentication--session-management-fr-auth)); (3) the attempt is written to `login_history` with outcome, IP, user-agent, browser, platform (FR-AUTH-005); (4) `last_login_at`/`last_login_ip` updated (FR-AUTH-007); (5) the account-status middleware confirms `active` (FR-AUTH-016); (6) the user is redirected to the role dashboard — Teacher → Teacher Dashboard, Technician → assigned-work queue, Administrator → operations overview ([FR-DSH-001](#20-dashboard-requirements)).
- **Alternate flows:** *A1 —* `force_password_reset` set → mandatory change-password step before any feature (FR-USER-016). *A2 —* status `pending`/`rejected`/`suspended`/`inactive` → sign-in denied; the legitimate holder may see the status reason, e.g. "awaiting administrator approval" (FR-AUTH-004).
- **Exceptions:** *E1 —* invalid credentials → non-disclosing error, attempt logged `failed`. *E2 —* after the configurable threshold of consecutive failures (default 5) the account locks for a window (default 15 min), the lockout is recorded, and an email is sent (FR-AUTH-006).
- **Postconditions:** an authenticated session exists; the user is on their role dashboard; the attempt is auditable.
- **Traceability:** FR-AUTH-001..007/016, FR-DSH-001 · `users`, `login_history`, `sessions`.

**UCS-02 — Submit a Ticket (with AI Analysis & Priority Recommendation).**
- **Primary actor:** Teacher/Requester (Technicians/Administrators may raise on behalf). **Secondary actors:** AI Provider, Notification Service.
- **Preconditions:** authenticated; ticket categories/priorities seeded.
- **Trigger:** the requester opens "Report an issue" and submits the form.
- **Main flow:** (1) from the dashboard feed the requester searches existing tickets to avoid duplicates ([FR-TKT-014](#105-ticketing--itsm-fr-tkt)), and may upvote/comment instead (FR-TKT-009/007); (2) fills title, description, category, optional room and PC unit, tags (FR-TKT-001); (3) optionally attaches files/images — **«extend» Attach Files** — with server-side MIME + size validation, stored outside the web root with a SHA-256 checksum (FR-TKT-008); (4) on submit the system creates the ticket with `ticket_number` + `uuid`, status `Open`, `reporter_id`, and derives `response_due_at`/`resolution_due_at` from the priority SLA (FR-TKT-002/004); (5) the system **enqueues an asynchronous AI analysis job** so the request never blocks ([FR-AI-021](#173-ai-functional-requirements)); (6) AI produces category, severity, estimated minutes, `technician_required`, summary, and ordered troubleshooting recommendations with a confidence score, logged to `ai_analysis_logs`/`ai_recommendations`, and updates the ticket's `ai_summary`/`ai_confidence` snapshot (FR-AI-001/002); (7) AI **recommends a priority** from severity + the ticket's location context (room type — laboratory/office — and administrator-configured priority locations) + asset criticality, as an **advisory** value (FR-AI-001, FR-CFG-006, FR-AI-010); (8) an activity entry and a notification are emitted (FR-TKT-012, FR-NOT-003).
- **Alternate flows:** *A1 —* duplicate found → requester upvotes the canonical ticket; no new ticket. *A2 —* AI disabled/unreachable → the ticket proceeds normally, the AI panel shows an unavailable state, analysis retries later (FR-AI-020/022).
- **Exceptions:** *E1 —* validation error → field messages. *E2 —* attachment rejected (type/size) → error; the ticket is still submittable without it.
- **Postconditions:** a ticket exists in `Open`; an advisory AI analysis + priority recommendation are attached; the reporter can track it.
- **Traceability:** FR-TKT-001/002/004/007/008/009/012/014, FR-AI-001/002/010/021, FR-NOT-003 · `tickets`, `attachments`, `ai_analysis_logs`, `ai_recommendations`, `ticket_updates`.

**UCS-03 — AI-Assisted Troubleshooting & Escalation.**
- **Primary actor:** Teacher/Requester (also Technician). **Secondary actor:** AI Provider.
- **Preconditions:** a submitted ticket with AI analysis, or the assistant opened from any page.
- **Trigger:** AI presents troubleshooting steps, or the user opens the floating assistant.
- **Main flow:** (1) the **floating AI assistant — available to every authenticated user on every page** — presents grounded, ordered troubleshooting steps (FR-AI-003/004); (2) the requester works the steps and marks them complete (`ai_recommendations.is_completed`) (FR-AI-003); (3) the requester **confirms whether the issue is resolved**; (4) *if resolved* → the ticket is resolved/closed on the requester's confirmation; the conversation + feedback are logged (FR-AI-008); (5) *if unresolved* → the ticket is **escalated** into the triage/assignment queue with the AI recommendation (priority/category/summary) attached for human review — **AI never auto-assigns or auto-closes** (FR-AI-010/032).
- **Alternate flows:** *A1 —* the user rates the answer (helpful flag, 1–5) (FR-AI-008).
- **Exceptions:** *E1 —* provider error/timeout → retry with backoff; the assistant shows a non-blocking unavailable state (FR-AI-020/022).
- **Postconditions:** the issue is resolved by self-service, or the ticket is queued for assignment with AI context; every AI turn is logged.
- **Traceability:** FR-AI-003/004/007/008/010/020/022/032 · `ai_conversation_logs`, `ai_recommendations`, `ai_feedback`, `tickets`.

**UCS-04 — Approve / Reject Registration.** *(Implemented — Phase 2.2/2.3.)*
- **Primary actor:** Administrator. **Secondary actor:** Email Service.
- **Preconditions:** a prospective Teacher/Technician submitted a registration request; the account is `pending` and cannot sign in ([FR-AUTH-013](#101-authentication--session-management-fr-auth)).
- **Trigger:** the Administrator opens the registration-review queue.
- **Main flow:** (1) the admin reviews the request and may **edit** profile fields / the self-registerable role before deciding (FR-USER-019); (2) the admin **approves** → account `active`, sign-in enabled, or **rejects** → account `rejected` with `rejection_reason`, `rejected_by`, `rejected_at` (FR-AUTH-014); (3) the system **emails the applicant the decision** (activation, or rejection with reason) (FR-AUTH-015); (4) the decision is recorded in the activity/audit trail (FR-AUD-003).
- **Alternate flows:** *A1 —* resend the decision email (FR-USER-016).
- **Exceptions:** *E1 —* self-safety and last-active-admin invariants block unsafe changes (FR-USER-018).
- **Postconditions:** the applicant is `active` or `rejected`; the decision is auditable; the applicant is notified.
- **Traceability:** FR-AUTH-013..016, FR-USER-016/018/019, FR-AUD-003 · `users`, `activity_logs`, `audit_logs`.

**UCS-05 — Assign Ticket (Triage, AI Review, Override).**
- **Primary actor:** Administrator (and permitted Technician). **Secondary actor:** Notification Service.
- **Preconditions:** an `Open` ticket exists (frequently from an escalation).
- **Trigger:** the admin opens a ticket in the triage feed.
- **Main flow:** (1) the admin reviews the ticket and the **AI recommendation** (priority/category/summary) (FR-AI-001); (2) the admin **accepts or overrides** the AI-recommended priority/category — the human decision is authoritative (FR-AI-010/032); (3) the admin **assigns a technician**, creating exactly one active `technician_assignments` record, setting `assigned_technician_id`, and moving the ticket to `Assigned` (FR-ASN-001/002); (4) the system notifies the technician (FR-ASN-005, FR-NOT-003).
- **Alternate flows:** *A1 —* reassign/cancel. *A2 —* the admin configures priority laboratories/offices to tune future AI priority recommendations (FR-CFG-006).
- **Exceptions:** *E1 —* the one-active-assignment invariant prevents a second active assignment (FR-ASN-002).
- **Postconditions:** exactly one active assignment; the technician is notified; the transition is recorded in `ticket_status_history`.
- **Traceability:** FR-ASN-001/002/005, FR-AI-001/010/032, FR-TKT-005 · `tickets`, `technician_assignments`, `ticket_status_history`.

**UCS-06 — Resolve Ticket / On-Site Maintenance (QR Verify · Evidence · Checklist).**
- **Primary actor:** Technician. **Secondary actors:** QR Scanner, Notification Service.
- **Preconditions:** an assignment exists for the technician; the target PC unit has an active QR code.
- **Trigger:** the technician opens an assigned ticket and starts work.
- **Main flow:** (1) the technician **accepts** the assignment, or declines with a reason (FR-ASN-004); (2) **starts work** → ticket `In Progress`, `first_response_at` stamped (FR-TKT-006); (3) reviews full ticket details and AI troubleshooting recommendations ([FR-PC-006](#104-pc-unit--specification-management-fr-pc), FR-AI-003); (4) on-site, **scans the QR code** on the PC unit; the system verifies and resolves the target, classifying the scan (success/invalid/mismatch/expired) and logging it to `qr_scan_logs` (FR-QR-005/006); (5) performs the repair, adding **internal notes** and status updates (FR-TKT-007); (6) **captures repair evidence** — before/during/after images, including a **technician selfie with the repaired unit** — uploaded as `repair_images` (FR-MNT-005); (7) **records maintenance performed** (diagnosis, root cause, resolution, downtime, labor, cost) and any **hardware replacement**, updating the PC installation history (FR-MNT-003/006); (8) the system updates **asset status history** and restores the PC's status/condition (FR-AST-005, FR-MNT-008); (9) completes the **required checklist** (enforced before completion) (FR-MNT-004); (10) **completes the work** → ticket `Resolved`, `resolved_at` stamped, reporter/admin notified (FR-ASN-005, FR-TKT-006, FR-NOT-003).
- **Alternate flows:** *A1 —* QR mismatch/expired → reconcile the asset or reassign (FR-QR-006). *A2 —* put the assignment on hold.
- **Exceptions:** *E1 —* a required checklist item is incomplete → completion is blocked (FR-MNT-004).
- **Postconditions:** the ticket is resolved; the maintenance record, evidence, and asset history are complete; notifications are sent.
- **Traceability:** FR-ASN-004/005, FR-TKT-005/006/007, FR-QR-005/006, FR-MNT-003/004/005/006/008, FR-AST-005 · `technician_assignments`, `maintenance_records`, `maintenance_checklists`, `repair_images`, `hardware_replacements`, `qr_scan_logs`, `asset_status_history`.

**UCS-07 — Schedule & Perform Preventive Maintenance.**
- **Primary actor:** Technician (schedule configured by Administrator). **Secondary actor:** Notification Service.
- **Preconditions:** PC units/assets exist; the PM interval/reminder is configured (defaults 90/7 days).
- **Trigger:** a PM-due reminder, or manual scheduling.
- **Main flow:** (1) the system generates **PM-due reminders** from `maintenance.default_interval_days` + `reminder_days` (FR-MNT-007) and notifies technicians (FR-NOT-003); (2) a **preventive maintenance record** (no originating ticket, `scheduled_for` set) is created (FR-MNT-002); (3) the technician performs the maintenance following the **checklist template** for the type (FR-MNT-004); (4) completion updates histories, cost/downtime, and PC status (FR-MNT-003/008).
- **Alternate flows:** *A1 —* reschedule/cancel.
- **Postconditions:** the PM is recorded; the next PM cycle is derivable; histories are updated.
- **Traceability:** FR-MNT-002/003/004/007/008, FR-NOT-003 · `maintenance_records`, `checklist_templates`, `maintenance_windows`.

**UCS-08 — Search Knowledge Base (AI / RAG).**
- **Primary actor:** Teacher/Requester, Technician. **Secondary actor:** AI Provider.
- **Preconditions:** authenticated; published knowledge-base articles exist.
- **Trigger:** the user searches the knowledge base or asks the assistant.
- **Main flow:** (1) the user enters a query; the system retrieves top-k relevant context via **vector similarity** over `ai_embeddings` (RAG) (FR-AI-006/007); (2) the assistant returns an answer/articles grounded in the retrieved sources **with citations**; only *published* articles are visible to Teachers (FR-AI-005/007); (3) the conversation is logged and the user may give feedback (FR-AI-004/008).
- **Alternate flows:** *A1 —* no relevant content → the assistant offers to open a ticket.
- **Exceptions:** *E1 —* provider unavailable → non-blocking unavailable state (FR-AI-020).
- **Postconditions:** the user is assisted; the conversation + feedback are logged.
- **Traceability:** FR-AI-004/005/006/007/008 · `ai_knowledge_articles`, `ai_embeddings`, `ai_conversation_logs`, `ai_feedback`.

**UCS-09 — Manage Profile & Password.** *(Implemented — Phase 2.2/2.3.)*
- **Primary actor:** any authenticated user. **Secondary actor:** Email Service.
- **Preconditions:** an authenticated session.
- **Trigger:** the user opens their profile/security settings.
- **Main flow:** (1) the user views/edits their profile (name, contact number, profile picture, locale/theme) — **not** their own role or permissions (FR-USER-008); (2) the user **changes password** after re-entering the current password; other active sessions are invalidated (FR-AUTH-011).
- **Alternate flows:** *A1 —* forgot password (unauthenticated) → reset via a single-use, time-limited token emailed to the user (FR-AUTH-008).
- **Exceptions:** *E1 —* a password that fails the policy is rejected (FR-AUTH-003).
- **Postconditions:** the profile/password is updated; sessions are consistent; the change is audited.
- **Traceability:** FR-USER-008, FR-AUTH-003/008/011, FR-AUD-003 · `users`, `password_reset_tokens`, `sessions`, `activity_logs`.

**UCS-10 — Track Ticket & Receive Notifications.**
- **Primary actor:** Teacher/Requester (Technician/Administrator analogously). **Secondary actors:** Notification Service, Email Service.
- **Preconditions:** the user has related tickets.
- **Trigger:** a ticket event (assignment, status change, comment, SLA breach), or the user opens the notification center.
- **Main flow:** (1) the system emits notifications for ticket assigned/reassigned, status change, new comment, and SLA breach/nearing, delivered **in-app + email** per the user's channel preferences (FR-NOT-001/002/003); (2) the user opens the **notification center** (unread badge), reads/filters, and opens the referenced ticket (FR-NOT-001/004/005); (3) Teachers see only their own tickets and their comments/upvotes ([FR-TKT-013](#105-ticketing--itsm-fr-tkt)).
- **Alternate flows:** *A1 —* a daily digest email aggregates unread items (FR-NOT-008).
- **Exceptions:** *E1 —* email-transport failure → the in-app notification is still delivered.
- **Postconditions:** the user is informed; read state is persisted.
- **Traceability:** FR-NOT-001..006/008, FR-TKT-013/017 · `notifications`, `notification_preferences`, `tickets`.

### 27.3 Operational Workflow Activity Diagrams

The following UML **activity diagrams** model the end-to-end operational flow — from login to completion — for each principal role and for the system-wide AI, complementing the specifications in [§27.2](#272-detailed-use-case-specifications). They share one visual convention: rounded **start/end** nodes, rectangular **actions**, amber **decision** diamonds, green **database effects** (persisted writes), pink **notification effects**, and violet **AI steps**. Every step traces to the requirements cited beneath each figure. (High-resolution vector sources accompany each PNG in `docs/diagrams/`.)

![SccIT Teacher / Requester workflow](diagrams/activity-teacher.png)

**Figure 3. Teacher / Requester Workflow — Activity Diagram.** Log in → Teacher Dashboard (Reddit-style ticket feed of the requester's own tickets, with upvotes and comments) → search existing tickets first (upvote/comment on a duplicate) → fill and submit a ticket with optional attachments → the system creates the ticket (`Open`) and queues **asynchronous AI analysis**, which recommends a priority → the AI assistant shows troubleshooting steps → the teacher confirms **resolved** (ticket closed) or **unresolved** (escalated to the triage/assignment queue with the AI recommendation attached) → track progress and receive notifications. *(FR-TKT-001/007/008/009/012/014, FR-AI-001/003/010/021, FR-NOT-003; see [UCS-02](#272-detailed-use-case-specifications), UCS-03.)*

![SccIT Technician workflow](diagrams/activity-technician.png)

**Figure 4. Technician Workflow — Activity Diagram.** Log in → Technician Dashboard (only tickets assigned to the technician) → accept (or decline with a reason) → start work (`In Progress`) → review details + AI recommendations → **scan the PC's QR code** to verify the correct, active asset → perform the repair with internal notes → **capture and upload repair evidence** (before/during/after images including a technician selfie with the repaired unit) → record maintenance, any hardware replacement, and asset status history → complete the **required checklist** → close the work (`Resolved`) → notify the reporter and Administrator. *(FR-ASN-004/005, FR-TKT-005/006/007, FR-QR-005/006, FR-MNT-003/004/005/006/008, FR-AST-005; see UCS-06.)*

![SccIT Administrator workflow](diagrams/activity-admin.png)

**Figure 5. Administrator Workflow — Activity Diagram.** Log in → Administrator Dashboard (real-time overview of tickets, technicians, assets, inventory, maintenance, analytics, notifications, and system health; Reddit-style ticket feed) → branch into: **review & approve/reject registrations** (email decision); **review AI recommendations**, optionally **override**, and **assign a technician**; **configure priority laboratories/offices** (which feed the AI priority recommendation); **administration & oversight** (users, roles & permissions, assets, locations, floor plans, inventory, QR codes, preventive-maintenance schedules, procurement, announcements); and **reports/analytics/audit logs** and system settings. *(FR-AUTH-014/015, FR-USER-*, FR-ASN-001, FR-AI-001/010/032, FR-CFG-*, FR-DSH-*, FR-RPT-*, FR-AUD-004; see UCS-04, UCS-05.)*

![SccIT system-wide AI behavior](diagrams/activity-ai.png)

**Figure 6. System-Wide AI Behavior — Activity Diagram.** Two entry triggers: an authenticated user opening the **assistant** (RAG knowledge-base answer with citations, logged) and a **ticket submission / on-demand analysis**. For analysis the system checks AI availability (**graceful degradation** if disabled/unreachable), builds a **minimized, PII-redacted** prompt, analyzes the ticket, recommends a priority from severity + priority location + asset criticality, gates on the **confidence threshold (0.70)**, presents **advisory** troubleshooting, and either closes on self-service resolution or **escalates to human triage** — the AI never auto-assigns or auto-closes. All analyses and recommendations are logged. *(FR-AI-001..010/020..022/030..034; see [§27.4](#274-system-wide-ai-behavior).)*

### 27.4 System-Wide AI Behavior

The AI subsystem is **advisory, configurable, and grounded in the organization's own data (RAG)**. It never performs irreversible or state-changing operations autonomously; every AI output requires human confirmation ([FR-AI-032](#173-ai-functional-requirements)). The following behaviours apply system-wide and are each fully traceable to the AI requirements ([§17](#17-ai-requirements)):

- **Conversational assistant on every page.** A persistent AI assistant (the floating bottom-left chatbot) is available to **every authenticated user** on every page, for troubleshooting and knowledge-base questions, grounded in retrieved knowledge with citations. *(FR-AI-004 · `ai_conversation_logs`.)*
- **AI troubleshooting assistant.** For a reported problem the assistant presents grounded, ordered troubleshooting steps that the requester can mark complete. *(FR-AI-003 · `ai_recommendations`.)*
- **AI ticket analysis.** On submission (or on demand) an **asynchronous** job produces category, severity, estimated resolution minutes, a `technician_required` flag, a summary, and a confidence score — cached on the ticket and retained in full history. *(FR-AI-001/002/021 · `ai_analysis_logs`.)*
- **AI priority recommendation.** The AI recommends a ticket priority from the analyzed **severity**, the ticket's **location context** (room type — laboratory/office — and administrator-configured **priority locations**), and **asset criticality**. The recommendation is advisory; Administrators may override it and configure which locations are high-priority. *(FR-AI-001, FR-CFG-006, FR-AI-010 · `tickets`, `rooms`, `system_settings`.)*
- **AI knowledge-base search (RAG).** Queries retrieve top-k relevant context via **vector similarity** over embeddings and answer with **cited sources**; only *published* articles are visible to Teachers. *(FR-AI-005/006/007 · `ai_knowledge_articles`, `ai_embeddings`.)*
- **AI maintenance recommendations.** From resolved tickets and maintenance history the AI can suggest preventive actions and (opt-in) per-PC **predictive-maintenance** predictions; these ship **disabled by default**. *(FR-AI-011/013 · `ai_predictions`, `ai_failure_patterns`.)*
- **AI escalation logic.** When self-service troubleshooting does not resolve an issue, the ticket is **escalated** into the human triage/assignment queue with the AI recommendation attached. The AI surfaces and recommends; it does **not** autonomously assign, close, or change permissions. *(FR-AI-010/032, FR-ASN-001.)*
- **AI recommendation logging.** Every analysis, recommendation, conversation turn, and feedback item is **logged** under the same retention and access controls as other PII-bearing data, and AI-generated content is **labelled** as such. *(FR-AI-004/008/031/034 · `ai_analysis_logs`, `ai_recommendations`, `ai_conversation_logs`, `ai_feedback`.)*

**Reliability & governance.** If AI is disabled or the provider is unreachable, all non-AI workflows remain fully functional and AI surfaces show a clear, **non-blocking unavailable** state; analysis and embedding run asynchronously and retry with backoff; prompts and embedded content are **minimized and PII-redacted**; and external AI processing is transparent to, and disable-able by, Administrators. *(FR-AI-020/021/022/030/033.)*

---

## 28. User Stories

Grouped by role; each maps to FRs and has acceptance criteria in [§31](#31-acceptance-criteria).

**Teacher / Requester**
- US-T1: *As a teacher, I want to report a problem in under two minutes so that I can get back to teaching.* (FR-TKT-001; NFR-USB-001)
- US-T2: *As a teacher, I want to see the status of my tickets so that I know what’s happening.* (FR-TKT-013)
- US-T3: *As a teacher, I want AI to suggest quick fixes so that simple issues resolve without waiting.* (FR-AI-003)
- US-T4: *As a teacher, I want to search published help articles so that I can self-serve.* (FR-AI-005)
- US-T5: *As a teacher, I want to upvote an existing issue so that I don’t create duplicates.* (FR-TKT-009/011)

**Technician**
- US-C1: *As a technician, I want a dense, keyboard-efficient work queue so that I can move fast.* (FR-ASN-006; NFR-USB-005/006)
- US-C2: *As a technician, I want to scan a PC’s QR to pull up its full history so that I diagnose on the spot.* (FR-QR-005; FR-PC-006)
- US-C3: *As a technician, I want to record repairs, parts, and photos so that history is complete.* (FR-MNT-003/005/006)
- US-C4: *As a technician, I want to see tickets nearing SLA breach so that I prioritize correctly.* (FR-TKT-017; FR-DSH-004)
- US-C5: *As a technician, I want preventive-maintenance reminders so that I service equipment before it fails.* (FR-MNT-007)

**Administrator**
- US-A1: *As an administrator, I want at-a-glance KPIs so that I understand operations instantly.* (FR-DSH-003)
- US-A2: *As an administrator, I want to assign and rebalance technician workload so that work is distributed fairly.* (FR-ASN-001)
- US-A3: *As an administrator, I want to manage users, roles, and permissions so that access stays correct.* (FR-USER-001..010)
- US-A4: *As an administrator, I want to configure branding and settings without code so that we can re-deploy for any organization.* (FR-CFG-005; BO-08)
- US-A5: *As an administrator, I want exportable SLA and asset reports so that I can report to leadership.* (FR-RPT-001/004)
- US-A6: *As an administrator, I want a tamper-resistant audit trail so that every change is accountable.* (FR-AUD-001/002)
- US-A7: *As an administrator, I want to approve procurement so that spending is controlled.* (FR-AST-008/009)

---

## 29. Data Requirements

The physical data model is authoritatively specified in `docs/database/database_design_v2.dbml` and its architecture report, and is **already implemented and verified** (66 Eloquent models, 34 enum domains, 15 migrations, 9 seeders). This section states the *requirements* the data must satisfy; it does not restate the schema.

### 29.1 Data entities (logical groups)

| Group | Key entities |
|---|---|
| Identity & Access | roles, permissions, role_permissions, user_permissions, users, sessions, password_reset_tokens, personal_access_tokens |
| Locations & Floor Plan | buildings, floors, rooms, room_layouts, floor_plan_positions |
| Computers & QR | pc_units, pc_specifications, qr_codes, qr_scan_logs |
| Ticketing | ticket_categories, ticket_priorities, ticket_statuses, tags, tickets, ticket_tags, ticket_updates, ticket_status_history, ticket_votes, ticket_comments, attachments |
| Maintenance | technician_assignments, maintenance_types, maintenance_records, checklist_templates, checklist_template_items, maintenance_checklists, repair_images, maintenance_notes, hardware_replacements |
| Inventory & Assets | suppliers, manufacturers, hardware_components, hardware_models, assets, consumables, stock_transactions, asset_status_history, pc_component_installations, procurement_requests, procurement_request_items, asset_transfers, disposal_records |
| AI / RAG | ai_models, ai_analysis_logs, ai_recommendations, ai_conversation_logs, ai_learning_events, ai_failure_patterns, ai_knowledge_articles, ai_predictions, ai_feedback, ai_embeddings, ai_embedding_sources, ai_system_settings |
| Administration & System | notifications, notification_preferences, announcements, activity_logs, audit_logs, login_history, system_settings, dashboard_widgets, maintenance_windows, backup_history |

### 29.2 Data integrity & quality requirements

| ID | Requirement (the data model *shall* …) | Ver |
|---|---|:--:|
| DR-001 | Store every timestamp as `timestamptz` (UTC) to guarantee timezone-correct analytics and SLA computation. | I |
| DR-002 | Enforce referential integrity on all foreign keys with explicit ON DELETE policy (RESTRICT for reference/actor, CASCADE for compositions, SET NULL for optional context). | I |
| DR-003 | Index every foreign-key column plus composite indexes for known access paths. | I |
| DR-004 | Enforce closed enumerations as CHECK constraints mirrored by PHP enums; keep user-configurable domains as lookup tables. | I |
| DR-005 | Enforce domain CHECKs: scores/confidence/probability ∈ [0,1]; non-negative money/quantities/sizes; date ordering (warranty ≥ purchase; end > start); geo bounds; hex color format; single-target exclusivity where required. | T |
| DR-006 | Provide external `uuid` identifiers (unique) on all user-facing entities and route on them. | T |
| DR-007 | Soft-delete business/master entities; never soft-delete logs/history/pivots. | I |
| DR-008 | Record `created_by`/`updated_by` blame columns on key mutable entities. | I |
| DR-009 | Maintain counter caches and denormalized snapshots (ticket counters, `pc_specifications`, `tickets.ai_*`) transactionally so they never drift from their source of truth. | T |
| DR-010 | Provide full-text (`tsvector` + GIN) search on tickets and knowledge articles, and HNSW vector search on `ai_embeddings` (768-dim; must equal `ai_models.embedding_dimensions`). | T |
| DR-011 | Keep append-only logs immutable (audit hardened at DB) and shaped for future time-range partitioning (BRIN on `created_at`). | I |
| DR-012 | Ensure uniqueness constraints prevent double-votes, duplicate active assignments, duplicate active layouts, duplicate embeddings, and one-feedback-per-user-per-recommendation. | T |
| DR-013 | Store money as `numeric(12,2)`, IPs as `inet`, email as `citext`, and semi-structured data as `jsonb`. | I |
| DR-014 | Support configurable retention/archival of high-volume logs and PII, with lawful-erasure hard purge. | T |
| DR-015 | Model the account lifecycle on `users.status` ∈ {`pending`, `active`, `rejected`, `suspended`, `inactive`} enforced by a CHECK constraint mirrored by the `UserStatus` PHP enum; retain rejected registration requests with `rejection_reason`, `rejected_by` (actor), and `rejected_at` for audit. *(OI-02, resolved.)* | T |
| DR-016 | Carry three additive, backward-compatible operational columns on `users`: `force_password_reset` (boolean, default false), `password_changed_at` (timestamptz, nullable), and `registration_source` (varchar, nullable — `self`/`admin`/`seed`). Account lockout and last-activity are **derived** (Redis RateLimiter + `login_history`; `activity_logs`), not stored. Trigram (`pg_trgm`) GIN indexes on name/employee-number accelerate directory search. *(Phase 2.3.)* | T |
| DR-017 | Carry one additive, backward-compatible column on `floors`: `uuid` (unique, `gen_random_uuid()` default, backfilled). `buildings` and `rooms` were created with a public `uuid`; `floors` was not, because it was originally an internal child table. Exposing floor management ([FR-LOC-002](#103-location-management-fr-loc)) requires a stable public identifier, since numeric primary keys never appear in URLs or payloads ([NFR-SEC-001](#12-security-requirements)). No existing column is renamed, retyped or removed. *(Phase 2.4.)* | T |
| DR-018 | Record the **cascade receipt** for a location archive in the existing `deleted_at` columns: one timestamp is computed per cascade and written to the building, its floors and its rooms, so a restore reverses exactly those rows and a separately-archived child keeps its own, different stamp. Because `deleted_at` is second-precision, the cascade stamp is advanced until no descendant already carries it. No column is added for this. *(Phase 2.4; realizes [FR-LOC-004](#103-location-management-fr-loc).)* | T |
| DR-019 | Derive all dashboard figures from the authoritative operational tables at read time (tickets and `ticket_statuses.is_open`/`is_terminal`, `technician_assignments`, `maintenance_records`, `assets`, `consumables`, `announcements`, `activity_logs`, plus the users and locations aggregates). Dashboards store nothing of their own and add no tables; the per-user payload is cached for 30 seconds to meet [NFR-PERF-008](#13-performance-requirements). *(Phase 2.4.)* | A |

### 29.3 Reference/seed data (baseline)
The system ships with seeded roles, the permission matrix ([§8.4](#84-seeded-permission-matrix-baseline)) — including the `locations` module — ticket categories (Hardware, Software, Network, Peripheral, Account & Access, Other), priorities and SLAs ([BR-05](#25-business-rules)), statuses (Open→Cancelled with open/terminal flags), maintenance types (Preventive, Corrective, Hardware Upgrade, Inspection, Cleaning), AI models (Gemini 1.5 Flash; text-embedding-004/768), AI defaults (threshold 0.70; advanced features off), and system settings across all groups. A non-production demo seeder provides sample data; it must never run in production.

---

## 30. Requirements Traceability Matrix

### 30.1 Business objective → requirement coverage

| Objective | Primary requirements |
|---|---|
| BO-01 Faster resolution | FR-TKT-004/005/006/017, FR-ASN-001..006, FR-DSH-003/004, FR-RPT-001, FR-AI-001..004 |
| BO-02 Accurate assets | FR-AST-001..012, FR-PC-001..006, FR-QR-001..008, DR-002/005 |
| BO-03 Preventive maintenance | FR-MNT-002/004/007, FR-DSH-003 |
| BO-04 Operational truth | FR-DSH-001..007, FR-RPT-001..005, NFR-PERF-008 |
| BO-05 Low-effort reporting | FR-TKT-001, FR-AI-003, NFR-USB-001, NFR-ACC-* |
| BO-06 Accountability | FR-AUD-001..004, FR-USER-009, NFR-SEC-010/017, DR-002/007/008 |
| BO-07 Enterprise quality | §12 Security, §13 Perf, §16 A11y, NFR-MTN-* |
| BO-08 Vertical-agnostic | FR-CFG-005, BR-16, NFR-I18N-001 |

### 30.2 Module → data entities → key requirements

| Module | Data entities | Requirements |
|---|---|---|
| Auth & Users | users, roles, permissions, *_permissions, sessions, login_history | FR-AUTH-*, FR-USER-*, NFR-SEC-* |
| Locations | buildings, floors, rooms | FR-LOC-* |
| PC & QR | pc_units, pc_specifications, qr_codes, qr_scan_logs, pc_component_installations | FR-PC-*, FR-QR-* |
| Ticketing | tickets (+ children), ticket_* lookups, tags | FR-TKT-*, FR-ASN-* |
| Maintenance | maintenance_records (+ children), checklist_*, maintenance_types | FR-MNT-* |
| Inventory | assets, consumables, catalog, stock_transactions, procurement_*, asset_transfers, disposal_records | FR-AST-* |
| AI | ai_* (models, logs, embeddings, knowledge, feedback, settings) | FR-AI-* |
| Floor Plan | room_layouts, floor_plan_positions | FR-FP-* |
| Dashboard/Reporting | dashboard_widgets, history tables | FR-DSH-*, FR-RPT-* |
| Notifications | notifications, notification_preferences, announcements | FR-NOT-* |
| Settings/Admin | system_settings, ai_system_settings, audit_logs, activity_logs, maintenance_windows, backup_history | FR-CFG-*, FR-AUD-*, NFR-AVL-* |

### 30.3 Requirement → verification → acceptance
Every `FR-*`/`NFR-*` carries a **Ver** method (this document) and a corresponding entry in [§31](#31-acceptance-criteria) or an acceptance pattern by prefix. QA shall maintain a live test-case-to-requirement map; no requirement is “done” without a passing verification of its stated method.

---

## 31. Acceptance Criteria

General acceptance patterns plus representative Given/When/Then criteria. Each FR is accepted when its stated verification method passes against criteria of this form.

### 31.1 Global acceptance gates (all releases)
- **AC-G1:** All CI quality gates pass (backend: Pint, Larastan L6, Pest; frontend: ESLint, Prettier, tsc, Vitest).
- **AC-G2:** No endpoint returns a user-facing resource by numeric id; all use `uuid`.
- **AC-G3:** Every screen passes an automated accessibility scan with **zero** WCAG 2.2 AA violations in both themes, plus a manual keyboard/screen-reader smoke test.
- **AC-G4:** Authorization is enforced on every endpoint (a role/permission matrix test suite passes; deny-by-default verified).
- **AC-G5:** Performance targets ([§13](#13-performance-requirements)) met under nominal load ([§14](#14-scalability-requirements)) in a load test.
- **AC-G6:** A clean `docker compose` rebuild + migrate + seed succeeds and the app is reachable.

### 31.2 Representative feature criteria

- **AC-TKT-001 (FR-TKT-001..006):** *Given* an authenticated teacher, *when* they submit a ticket with title/description/category, *then* a ticket is created with a unique number and uuid, status `Open`, reporter set to them, and `response_due_at`/`resolution_due_at` derived from the priority’s SLA; *and* an activity entry and assignment-pending state exist.
- **AC-TKT-009 (FR-TKT-009):** *Given* a ticket a user has already upvoted, *when* they upvote again, *then* the vote is rejected and `upvote_count` is unchanged.
- **AC-TKT-017 (FR-TKT-017):** *Given* a non-terminal ticket past its `resolution_due_at`, *when* the SLA check runs, *then* the ticket is flagged breached and the assignee + administrator are notified.
- **AC-ASN-002 (FR-ASN-002):** *Given* a ticket with an active assignment, *when* a second active assignment is attempted, *then* it is rejected (DB partial-unique enforced).
- **AC-MNT-006 (FR-MNT-006):** *Given* a maintenance record, *when* a technician records replacing a part with a specific serialized asset, *then* the hardware replacement is stored, the PC’s installation history updates, and the replaced asset is no longer marked installed in that PC.
- **AC-AST-004 (FR-AST-003/004/010):** *Given* a consumable at quantity 3 with reorder level 3, *when* a stock_out of 1 is recorded, *then* `quantity_on_hand` = 2, `balance_after` = 2, and a low-stock notification is raised; a stock_out that would make quantity negative is rejected.
- **AC-LOC-004 (FR-LOC-004/009):** *Given* a room holding a live PC unit, *when* an Administrator archives it, *then* the request is refused with a blocker report naming the room and its live occupants, and nothing is archived; *and when* those occupants are reassigned to another room, *then* the archive succeeds. *Given* a room whose only blocker is an open ticket, *then* no reassignment is offered and the refusal states the ticket must be resolved or closed first.
- **AC-LOC-004b (FR-LOC-004):** *Given* a building archived with its floors and rooms, *when* it is restored, *then* exactly the rows archived with it return — a room archived separately beforehand stays archived.
- **AC-LOC-005 (FR-LOC-005/008):** *Given* a building that is inactive or archived, *when* any role opens the location lookup, *then* neither it nor any of its floors or rooms is offered; *and* a Teacher receives only labels of selectable rooms, never counts, custodianship or archived rows.
- **AC-LOC-011 (FR-LOC-011):** *Given* a Technician or Teacher, *when* they sign in, *then* no Locations navigation item is present; *and when* they request any Locations page URL directly, *then* the Forbidden (403) surface is shown; *and when* they call any `locations` API endpoint directly, *then* the response is **403**; *and* the narrow lookup remains **200** for them so a location field still works inside the form that needs it.
- **AC-DSH-001 (FR-DSH-001/008):** *Given* the three seeded roles, *when* each signs in and loads the dashboard, *then* each receives its own layout; *and* a Technician's payload contains no user-management or audit figures; *and when* a permission behind a widget is revoked by a per-user override, *then* that widget is absent from the payload.
- **AC-QR-006 (FR-QR-005/006):** *Given* a revoked QR code, *when* scanned, *then* the scan is logged with result `expired` and no live target panel is returned; an unknown code logs `invalid`.
- **AC-AI-010 (FR-AI-010/032):** *Given* AI analysis below the confidence threshold, *when* presented, *then* it is labeled advisory and no automated state change occurs; *and* AI never closes/deletes a ticket or disposes an asset without explicit human action.
- **AC-AI-020 (FR-AI-020):** *Given* the Gemini API is unreachable, *when* a user creates and works tickets, *then* all non-AI functions succeed and AI panels show an unavailable state; queued analyses retry on recovery.
- **AC-CFG-004 (FR-CFG-002/004):** *Given* a non-public setting (e.g. mail credentials), *when* a non-admin requests settings, *then* the value is never returned; *and* a non-admin write attempt is denied and audited.
- **AC-AUD-002 (FR-AUD-002/NFR-SEC-010):** *Given* an audit log row, *when* an update/delete is attempted via the application or a direct app-role DB call, *then* it is rejected.
- **AC-FP-003 (FR-FP-003, P4):** *Given* an active room layout, *when* an admin drags a PC and saves, *then* the snapped position persists to `floor_plan_positions` and is broadcast to other viewers; a keyboard alternative can place the same PC.

---

## 32. Risks, Constraints & Assumptions

### 32.1 Risks

| ID | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| RSK-01 | External AI (Gemini) latency/outage/cost variability. | Med | Med | Async jobs; graceful degradation ([FR-AI-020/021/022]); token/latency logging; provider abstraction. |
| RSK-02 | Sending PII/sensitive data to a third-party AI processor. | Med | High | Data minimization/redaction ([FR-AI-030]); admin transparency & disable ([FR-AI-033]); retention controls. |
| RSK-03 | Counter caches / snapshots drift from source of truth. | Med | Med | Transactional maintenance via triggers/events; reconciliation job; DR-009 tests. |
| RSK-04 | Scope creep from future modules (floor plan, predictions) into P2. | Med | Med | Strict phasing; seams only; this SRS gates scope. |
| RSK-05 | Accessibility regressions as UI grows. | Med | High | AA in CI (automated scans); design-system components; per-PR checks. |
| RSK-06 | Performance degradation at data volume (large logs, vector search). | Low | Med | FK/composite/BRIN/HNSW indexes; partitioning plan; load tests (NFR-SCAL). |
| RSK-07 | Aging/low-spec client hardware in target environments. | Med | Med | Performance budget; self-hosted assets; responsive/low-motion design. |
| RSK-08 | Ambiguity in open product decisions delaying design. | Med | Med | [§33](#33-open-issues--decisions-requiring-client-approval) resolved before SDD. |
| RSK-09 | Backup/restore not exercised → unrecoverable incident. | Low | High | Scheduled backups + periodic restore drills (NFR-AVL-003/004). |
| RSK-10 | Single-tenant assumption invalidated by a multi-org sale. | Low | High | Documented as CON-08; RLS/tenancy is a future re-architecture, flagged early. |

### 32.2 Constraints

| ID | Constraint |
|---|---|
| CON-01 | Technology stack is fixed and client-specified: Laravel 13/PHP 8.4, React 19/TS/Vite, PostgreSQL 17 + pgvector, Redis, Nginx, Dockerized, single-origin. |
| CON-02 | The physical data model is the current `database_design_v2.dbml`, already implemented; requirements must fit it (schema changes require change control). |
| CON-03 | UI must conform to `DESIGN.md` (“The Control Room”), including the ≤10% brand-signal, flat-by-tone, and dual-theme rules. |
| CON-04 | WCAG 2.2 AA is the accessibility floor, verified — not optional. |
| CON-05 | Embedding dimension is fixed at 768 (Gemini text-embedding-004); changing the embedding model requires re-embedding and index review, and must stay ≤ ~2000 dims for HNSW. |
| CON-06 | Fonts/assets self-hosted; no reliance on third-party CDNs. |
| CON-07 | AI advanced features (predictions, learning, auto-articles) ship disabled by default. |
| CON-08 | The system is single-tenant (one organization per deployment); multi-tenancy is out of scope. |
| CON-09 | No native mobile app; mobile support is via responsive web + device-camera QR scanning. |
| CON-10 | Development-only tooling (Mailpit, demo seeder) must never be active in production. |

### 32.3 Assumptions

| ID | Assumption |
|---|---|
| ASM-01 | The deploying organization provisions HTTPS/TLS, a production SMTP service, and (for P3) a Gemini API key with sufficient quota. |
| ASM-02 | Administrator accounts are provisioned only by existing Administrators (or the local dev seeder); Teacher/Technician accounts are created either by Administrators or via the public **registration-request** workflow (OI-02, resolved) and require Administrator approval before they can sign in. |
| ASM-03 | Reporters and technicians have network-connected devices with a modern browser; technicians’ devices have a camera for QR scanning. |
| ASM-04 | The organization accepts external AI processing of the data categories disclosed under [§17.5](#175-ai-data-governance--safety-requirements), or disables AI. |
| ASM-05 | Nominal capacity ([§14](#14-scalability-requirements)) reflects a single institution; larger deployments require re-validation. |
| ASM-06 | Business hours and the exact availability/SLA/performance targets will be confirmed by the client at approval (indicative values used here). |
| ASM-07 | A single primary timezone/locale per deployment is sufficient (system timezone default Asia/Manila, configurable). |

---

## 33. Open Issues & Decisions Requiring Client Approval

These are decisions that would **change the specified business model or major system behavior**. Per the working agreement, they are **not** silently applied; each is presented with its trade-off for the client to decide before the SDD. The current SRS documents the **baseline** choice; the alternative is noted.

| ID | Decision | Baseline (this SRS) | Alternative & trade-off | Recommendation |
|---|---|---|---|---|
| **OI-01** | Roles per user | Exactly one role + per-user overrides (BR-02, FR-USER-003). | Multi-role via a `role_user` pivot. **Pro:** models people who are both (e.g. a teacher who is also a technician) without over-granting. **Con:** conceptual change; requires schema addition and effective-permission recomputation; more complex UI. | Keep single-role for P2; revisit if real dual-role staff exist. |
| **OI-02** | Requester self-registration | **RESOLVED (2026-07-04).** The Client adopted the alternative: a **registration-request workflow** with Administrator approval, restricted to **Teachers and Technicians** (Administrators are never self-registerable). Requests are created `pending` and cannot sign in until approved; rejected requests are retained with a reason for audit. Realized by [FR-AUTH-013–016](#101-authentication--session-management-fr-auth). Spam/abuse is mitigated by rate limiting ([NFR-SEC-009](#12-security-requirements)) and mandatory human approval. | — (decided) | Implemented in Phase 2.2. |
| **OI-03** | MFA | No MFA in P2 (FR-AUTH-012/NFR-SEC-016 Future); schema note defers MFA columns. | Add TOTP MFA (schema columns + enrollment flow). **Pro:** materially stronger auth for privileged accounts. **Con:** added columns, flows, and support burden. | Add MFA for Administrators in an early post-P2 increment; approve now if security posture requires it. |
| **OI-04** | QR expiry semantics | `expired` defined by QR/target status, no date field (FR-QR-006). | Add `qr_codes.expires_at` for time-based expiry. **Pro:** supports rotating/temporary codes. **Con:** schema change; scan logic change. | Keep status-based unless time-limited QR is required. |
| **OI-05** | SLA model | Per-priority SLA on `ticket_priorities` (BR-05). | Dedicated `sla_policies` (per category/audience/asset-class). **Pro:** granular SLAs. **Con:** new tables + assignment logic. | Keep per-priority for P2; add `sla_policies` if differentiated SLAs are needed. |
| **OI-06** | Ticket auto-escalation | Breach is flagged + notified; no automatic action (FR-TKT-017; FR-TKT-018 Future). | Auto-reassign/raise priority on breach. **Pro:** enforces response. **Con:** changes workflow behavior; risk of churn. | Ship notify-only; add configurable escalation later. |
| **OI-07** | Indicative NFR targets | Performance/availability/SLA/capacity numbers are indicative defaults ([§13](#13-performance-requirements)–[§15](#15-availability--reliability-requirements)). | Client-specified targets. **Impact:** changes test thresholds and sizing. | Confirm exact numbers at approval; no design impact beyond thresholds. |

> **OI-02 has been resolved** (registration-request workflow; see above) and is reflected in the functional requirements, business rules, and data requirements of this document. The remaining open items (OI-01, OI-03–OI-07) are not applied in a way that alters the schema or behavior in this document; resolving any of them updates a future SRS revision and feeds the SDD.

---

## 34. Future Expansion

Explicitly out of current scope; the architecture reserves for them so they add **without major refactoring**:

| Area | Description | Enabled by (already present) |
|---|---|---|
| Interactive Floor Plan (build) | Full editor/viewer, real-time. | Location/layout/position tables; Reverb env placeholders (P4). |
| Predictive maintenance at scale | Failure-pattern learning, per-PC predictions surfaced across UI/floor plan. | `ai_predictions`, `ai_failure_patterns`, `ai_learning_events` (opt-in). |
| Network topology & heat maps | Device connectivity map, density/heat overlays. | `pc_units.ip_address/mac_address`; future `network_interfaces`/`device_connections`; optional PostGIS. |
| Scheduled/saved reports | Persisted report definitions and scheduled delivery. | Future `report_definitions`/`scheduled_reports` + materialized views. |
| MFA & advanced auth | TOTP, step-up auth. | Deferred `users` MFA columns. |
| Multi-role users | `role_user` pivot + effective-permission resolution. | Existing RBAC model. |
| Log partitioning & analytics MVs | Monthly partitions; refreshable analytics views. | BRIN-indexed, partition-ready append-only logs. |
| Multi-tenancy | Multiple organizations per deployment. | Would require tenancy/RLS re-architecture (flagged, not reserved). |
| Additional locales | Multi-language UI. | Externalized strings (NFR-I18N-001). |

---

## 35. Glossary

| Term | Definition |
|---|---|
| Announcement | A time-boxed message broadcast to a targeted audience. |
| Asset | A **serialized** physical unit (1 row = 1 unit) tracked across its lifecycle. |
| Assignment | The link between a ticket and a technician, with its own lifecycle. |
| Audit log | Immutable row-level change record (old/new JSON diffs) for accountability. |
| Blame columns | `created_by`/`updated_by` recording who created/changed a record. |
| Configuration Item (CI) | A managed thing, e.g. a PC unit composed of installed assets. |
| Consumable | **Quantity-tracked** stock (e.g. thermal paste, cables). |
| Counter cache | A denormalized count (e.g. `comment_count`) maintained transactionally. |
| Embedding | A 768-dimension vector representation of text for similarity search. |
| Lookup table | A user-configurable, often color-coded reference table (categories/priorities/statuses/types/tags). |
| Maintenance window | A scheduled period of planned system unavailability. |
| PC Unit | A managed computer, modeled as a CI (separate from `assets`). |
| Preventive maintenance | Scheduled servicing with no originating ticket. |
| RAG | Retrieval-Augmented Generation: grounding AI answers in retrieved organizational data. |
| Reporter | Role-neutral originator of a ticket (`reporter_id`). |
| Room | A typed location (laboratory/office/storage/server_room/…) within a floor. |
| Layout | A versioned floor-plan canvas for a room; one active layout per room. |
| SLA | Service Level Agreement — response/resolution time targets from priority. |
| Soft delete | Marking a record deleted (`deleted_at`) without physical removal. |
| Status (ticket) | Configurable state with `is_open`/`is_terminal` semantics. |
| tsvector / GIN | PostgreSQL full-text search vector and its index type. |
| HNSW | Approximate-nearest-neighbor vector index used for embeddings. |
| UUID (external) | Opaque public identifier used in URLs/APIs to prevent IDOR. |
| Vertical-agnostic | Re-skinnable for any organization by changing branding tokens only. |

---

## Appendix A — Internal Review & Verification Log

This appendix records the review performed after drafting, per the requested process: cross-checking every requirement against the project, removing inconsistencies and duplicates, and identifying gaps.

### A.1 Sources cross-checked
`PRODUCT.md`; `DESIGN.md`; `docs/database/database_architecture_report.md`; `docs/database/database_design_review.md`; `docs/database/database_design_v2.dbml` (all domain, pivot, and framework tables with FKs/CHECKs/indexes); implemented backend (`app/Models` ×66, `app/Enums` ×34, migrations ×15, seeders ×9); domain seams (`app/Domains/*/README.md`); `docs/PROJECT_STRUCTURE.md`, `docs/ENVIRONMENT.md`; project memory; git history (phases 0–7 + DB layer). The application/business-logic layer is confirmed **not yet implemented** — this SRS is forward-looking, not a description of existing behavior.

### A.2 Requirements coverage vs. specified model
Every module in the data model and every capability in the product brief maps to at least one requirement; every requirement traces to a data entity and a business objective ([§30](#30-requirements-traceability-matrix)). Requested SRS sections are all present. Requirement IDs are unique across the document.

### A.3 Inconsistencies found and resolved
1. **QR `scan_result: expired` vs. no expiry field.** Resolved by defining `expired` behaviorally against QR/target status (FR-QR-006); time-based expiry flagged as [OI-04].
2. **Saved/scheduled reports** implied by module naming but **no `report_definitions`/`scheduled_reports` tables** exist. Resolved: P2 reporting is on-demand + export; scheduled/saved reports marked Future (FR-RPT-006/007).
3. **Branding color drift:** `DESIGN.md` Signal Blue (`oklch(0.545 0.170 258)`) vs. seeded `theme.primary_color = #2563eb`. Resolved: primary color is a runtime branding setting (FR-CFG-005); `DESIGN.md` is the canonical default; the seed is an approximate hex placeholder. No conflict.
4. **AI feature defaults:** schema/seed ship predictions/learning/auto-articles **off**; represented as M-opt (support mandatory, enabled by client) to avoid implying they run by default.
5. **Reporter identity:** `reporter_id NOT NULL` implies no anonymous submission; captured as BR-03 and ASM-02, with self-registration flagged [OI-02].
6. **`maintenance_records` target rule** allows PC and/or asset (`num_nonnulls ≥ 1`) unlike QR’s exactly-one; documented as intentional (FR-MNT-001).

### A.4 Gaps identified and added (objective improvements, no business-model change)
Added concrete, testable requirements that the sources implied but did not specify: password policy and account-lockout thresholds (FR-AUTH-003/006); file-upload MIME/size/AV controls (FR-TKT-008, NFR-SEC-007/008); SLA breach detection & notification (FR-TKT-017); low-stock alerts (FR-AST-010); notification trigger catalog (FR-NOT-003); AI data-governance/safety (FR-AI-030..034); graceful AI degradation (FR-AI-020..022); quantified performance/scalability/availability targets (§13–§15); accessibility criteria mapped to WCAG 2.2 (§16); backup RPO/RTO and restore drill (NFR-AVL-003/004); log/PII retention (NFR-SEC-011/015); observability (NFR-OBS). Items that would change the business model were **not** applied — they are in [§33](#33-open-issues--decisions-requiring-client-approval).

### A.5 Duplication check
Cross-cutting concerns (security, accessibility, performance) are stated once in their dedicated sections and **referenced** (not restated) from functional requirements. Data-model facts live in the DBML and are stated as *requirements* (not re-listed) in [§29](#29-data-requirements). No requirement is duplicated under two IDs.

### A.6 Residual items for the client
The seven decisions in [§33](#33-open-issues--decisions-requiring-client-approval) and the indicative-target confirmations (OI-07) are the only items blocking a final, buildable baseline. No further contradictions were found.

---

*End of Software Requirements Specification — Version 1.0 baseline. Status: Waiting for Client Approval.*
