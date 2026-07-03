# Product

## Register

product

## Users

Three roles share one platform, each with a different tempo and tolerance:

- **Administrators** — own the system. Oversight across sites, buildings, and teams: technician assignment, asset lifecycle, analytics and reports, configuration, and (later) interactive floor-plan management. They need at-a-glance operational truth and confident control.
- **Technicians** — the daily drivers. They live in the work queue: triaging requests, running maintenance, updating asset and repair records, scanning QR codes on the floor. Their screens reward information density, speed, and keyboard efficiency.
- **Teachers / requesters (end users)** — occasional and non-technical. They report a broken PC or projector and track its status. Their surface must be near-zero-learning-curve and low-anxiety.

**Positioning / context.** Initially deployed for educational institutions, but architected as a **vertical-agnostic ITSM/ITAM platform** — the same product should deploy to businesses, government offices, hospitals, and other organizations with minimal customization. The UI, branding, and language must stay generic and re-brandable; nothing should hard-code a "school" identity into the visual system.

## Product Purpose

A modern platform for **IT Asset Management (ITAM)** and **IT Service Management (ITSM)**: managing IT assets and inventory, service and maintenance requests, preventive maintenance, QR-based asset tracking, AI-assisted troubleshooting, interactive floor plans, analytics, and technician workflows.

It exists to **replace manual, fragmented IT processes** with a single system that cuts busywork and improves operational efficiency. Success looks like: requests resolved faster, assets accurately tracked across their lifecycle, maintenance done before things break, and administrators seeing the real operational picture at a glance — all at a level of polish suitable for enterprise procurement.

## Brand Personality

Professional, trustworthy, and modern — enterprise-grade and intelligent, expressed through **restraint rather than decoration**. Efficient, reliable, minimal, data-driven.

**Voice:** clear, professional, confident, helpful, calm, direct. Never chatty, never cute. State the situation and the next action plainly; respect the user's time and expertise.

## Visual References

Directional touchstones for the *feel* — to learn from, not to clone:

- **Linear** — density with calm; keyboard-first speed; restrained, confident craft.
- **Stripe Dashboard** — enterprise trust, data legibility, disciplined use of color.
- **GitHub** — dense operational UI that stays readable and consistent at scale.
- **Notion** — clean structure, low chrome, content-first.
- **Atlassian Jira** — the ITSM workflow model (issues, queues, assignment) — the mechanics, not the visual weight.
- **Apple HIG / Material Design 3** — systematic, accessible, well-documented interaction and component behavior.
- **Reddit** — familiar, scannable list/feed patterns for high-volume item streams.

## Anti-references

This must NOT look like:

- Glassmorphism-heavy or neumorphic interfaces; excessive shadows; rounded-everything.
- Overly animated dashboards; gaming UI; crypto dashboards; purple gradients.
- Generic AI-generated SaaS templates; visual clutter.
- Dribbble "concepts" that prioritize aesthetics over usability.
- Cross-register bans still apply: gradient text, side-stripe accent borders, cream/sand "slop" backgrounds, and uppercase tracked eyebrows on every section.

## Design Principles

1. **Enterprise-first — productivity over decoration.** Every element serves the task. If it doesn't help someone resolve a request or find an asset faster, it doesn't ship.
2. **Information density without clutter.** Show a lot, calmly — through clear hierarchy, rhythm, and alignment, not boxes nested in boxes.
3. **Consistency over novelty.** A scalable, component-driven design system where the same control behaves the same way everywhere. Predictability is a feature.
4. **Accessibility by default.** Not a retrofit — contrast, keyboard, focus, and reduced-motion are built into every component from the start.
5. **Vertical-agnostic and re-brandable.** No hard-coded school identity; tokens and language stay generic so the platform re-skins cleanly for any organization.
6. **Fast and responsive everywhere.** Performance-first; solid on desktop, tablet, and mobile — including the aging hardware common in these environments.

## Accessibility & Inclusion

- **Target: WCAG 2.2 AA.** Body text ≥ 4.5:1, large text ≥ 3:1, visible focus states, full keyboard operability, and semantic structure throughout.
- **Light and dark modes**, both meeting contrast targets — neither treated as an afterthought.
- **Reduced motion honored throughout** (`prefers-reduced-motion`); motion is functional and never required to understand state.
- **Broad, mixed-ability, mixed-technical audience** — from non-technical requesters to power-user technicians — often on **varied and older hardware and smaller screens**. Favor conservative, robust patterns over clever ones.
