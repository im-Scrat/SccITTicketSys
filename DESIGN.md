---
name: SccIT
description: Vertical-agnostic ITSM/ITAM platform — a calm, dense enterprise operations console.
colors:
  # Values below are the LIGHT-mode canonical set (OKLCH; this project is OKLCH-first).
  # Dark-mode values live in §2 Colors and the normative :root/[data-theme=dark] block.
  bg: "oklch(0.978 0.005 258)"
  surface: "oklch(1.000 0.000 0)"
  surface-sunken: "oklch(0.955 0.006 258)"
  border: "oklch(0.912 0.007 258)"
  control-border: "oklch(0.640 0.012 258)"
  ink: "oklch(0.255 0.020 258)"
  ink-strong: "oklch(0.205 0.022 258)"
  muted: "oklch(0.500 0.016 258)"
  faint: "oklch(0.620 0.012 258)"
  primary: "oklch(0.545 0.170 258)"
  primary-hover: "oklch(0.480 0.165 258)"
  primary-active: "oklch(0.430 0.150 258)"
  primary-strong: "oklch(0.430 0.150 258)"
  primary-subtle: "oklch(0.955 0.030 258)"
  on-primary: "oklch(1.000 0.000 0)"
  focus: "oklch(0.545 0.170 258)"
  success: "oklch(0.560 0.130 150)"
  success-strong: "oklch(0.430 0.120 150)"
  success-subtle: "oklch(0.955 0.035 150)"
  warning: "oklch(0.700 0.150 75)"
  warning-strong: "oklch(0.470 0.110 75)"
  warning-subtle: "oklch(0.955 0.045 85)"
  danger: "oklch(0.550 0.200 28)"
  danger-strong: "oklch(0.455 0.175 28)"
  danger-subtle: "oklch(0.960 0.040 25)"
  info-strong: "oklch(0.450 0.120 235)"
  info-subtle: "oklch(0.955 0.030 235)"
typography:
  display:
    fontFamily: "Inter, system-ui, -apple-system, Segoe UI, Roboto, sans-serif"
    fontSize: "1.875rem"
    fontWeight: 600
    lineHeight: 1.15
    letterSpacing: "-0.01em"
    fontFeature: "'tnum' 1, 'cv05' 1"
  headline:
    fontFamily: "Inter, system-ui, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 600
    lineHeight: 1.25
    letterSpacing: "-0.006em"
  title:
    fontFamily: "Inter, system-ui, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 600
    lineHeight: 1.3
    letterSpacing: "normal"
  body:
    fontFamily: "Inter, system-ui, sans-serif"
    fontSize: "0.9375rem"
    fontWeight: 400
    lineHeight: 1.5
    letterSpacing: "normal"
  body-lg:
    fontFamily: "Inter, system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.6
    letterSpacing: "normal"
  label:
    fontFamily: "Inter, system-ui, sans-serif"
    fontSize: "0.8125rem"
    fontWeight: 500
    lineHeight: 1.4
    letterSpacing: "0.005em"
  code:
    fontFamily: "'JetBrains Mono', ui-monospace, 'SFMono-Regular', Menlo, monospace"
    fontSize: "0.8125rem"
    fontWeight: 400
    lineHeight: 1.5
    fontFeature: "'zero' 1"
rounded:
  sm: "4px"
  md: "6px"
  lg: "10px"
  full: "9999px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "12px"
  lg: "16px"
  xl: "24px"
  2xl: "32px"
  3xl: "48px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-primary}"
    rounded: "{rounded.sm}"
    padding: "8px 14px"
    typography: "{typography.body}"
  button-primary-hover:
    backgroundColor: "{colors.primary-hover}"
    textColor: "{colors.on-primary}"
  button-secondary:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.sm}"
    padding: "8px 14px"
  button-ghost:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.sm}"
    padding: "8px 10px"
  button-danger:
    backgroundColor: "{colors.danger}"
    textColor: "{colors.on-primary}"
    rounded: "{rounded.sm}"
    padding: "8px 14px"
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.sm}"
    padding: "7px 10px"
    height: "34px"
  card:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    padding: "16px"
  status-pill:
    backgroundColor: "{colors.surface-sunken}"
    textColor: "{colors.ink}"
    rounded: "{rounded.full}"
    padding: "2px 8px"
    typography: "{typography.label}"
  nav-item-active:
    backgroundColor: "{colors.primary-subtle}"
    textColor: "{colors.primary-strong}"
    rounded: "{rounded.sm}"
    padding: "6px 10px"
  table-row-selected:
    backgroundColor: "{colors.primary-subtle}"
    textColor: "{colors.ink}"
---

# Design System: SccIT

## 1. Overview

**Creative North Star: "The Control Room"**

SccIT is the console an operations team sits in front of all day. Picture a calm control room: the walls are quiet and dim so the instruments read clearly, information is packed onto the panels without ever feeling frantic, and there is exactly one indicator light that *means something* the moment it turns on. Nothing on the walls is there to impress a visitor — every dial, label, and readout earns its place because someone needs it to make a decision. That is the entire aesthetic: neutral surfaces do the architectural work, a single brand signal carries interaction, and typographic discipline carries density.

This system is **enterprise-first and productivity-over-decoration**, taken literally. Color is spent, not sprinkled: the brand hue appears on the primary action, the current selection, and the focus ring — roughly a tenth of any screen — and everywhere else is neutral surface and legible text. Depth is conveyed by tone and hairline borders, not by shadow; shadow is reserved to mean "this floats above the page" (menus, dialogs, toasts). Numbers align because they are tabular. The same control looks identical on every screen. The result should read the way Linear, Stripe's dashboard, and GitHub read: familiar, trustworthy, and quiet enough to disappear into the task.

Crucially, SccIT is **vertical-agnostic**. It ships for schools first but is built to re-skin for a business, a hospital, or a government office by changing a single brand token. So the identity is deliberately carried by *structure and restraint*, not by any motif that would tie it to a classroom. What must never happen — pulled straight from the product's anti-references — is the drift into decorated-dashboard territory: glassmorphism, neumorphism, gaming or crypto flourishes, purple gradients, heavy shadows, rounded-everything, or the generic AI-SaaS-template look. If a screen could be mistaken for a Dribbble concept that prioritizes aesthetics over usability, it has failed.

**Key Characteristics:**
- Calm, dense, instrument-like: high information density with low visual noise.
- Restrained color — a single brand signal (`--primary`) on ≤10% of any surface.
- Flat by tone: layered neutrals and hairline borders; shadow means "floating overlay" only.
- Dual-mode by construction: light ("day shift") and dark ("night shift") are equal citizens.
- Vertical-agnostic: brand lives in one swappable token; nothing hard-codes "school."
- WCAG 2.2 AA is the floor, verified — not a retrofit.

## 2. Colors

A cool, near-neutral architecture (a whisper of blue at hue 258) with one confident brand blue, and a fixed set of universal status signals. Neutrals carry the room; the brand hue is the light that turns on.

### Primary
- **Signal Blue** (`oklch(0.545 0.170 258)`): the single brand voice. Primary buttons, the active navigation item, current selection, links, and the focus ring. A trustworthy, technical blue chosen by elimination — red, amber, and green are reserved for status; purple is banned — so the one hue left that reads "enterprise, dependable, not a toy" is blue. Holds white text at 5.05:1. Hover `oklch(0.480 0.165 258)`, active/strong `oklch(0.430 0.150 258)`. **Signal Blue Wash** (`primary-subtle`, `oklch(0.955 0.030 258)`) tints selected rows, active nav, and informational chips.

### Neutral
- **Canvas** (`bg`, `oklch(0.978 0.005 258)`): the app background behind panels — a barely-cool off-white, the dim room.
- **Panel** (`surface`, pure white `oklch(1 0 0)`): cards, tables, toolbars, dialogs — the lit instrument faces.
- **Recess** (`surface-sunken`, `oklch(0.955 0.006 258)`): table header strips, insets, code blocks, disabled fields.
- **Hairline** (`border`, `oklch(0.912 0.007 258)`): dividers and panel edges. Intentionally faint (~1.3:1) — a divider, not a fence.
- **Control Edge** (`control-border`, `oklch(0.640 0.012 258)`): the resting outline of inputs, checkboxes, and radios, tuned to clear 3:1 so the affordance boundary is a real one.
- **Ink** (`oklch(0.255 0.020 258)`): body text (15.8:1). **Ink Strong** (`oklch(0.205 0.022 258)`): headings and key figures.
- **Muted** (`oklch(0.500 0.016 258)`): secondary text, metadata, and placeholders — held at ≥4.5:1 so it is never the elegant-but-unreadable gray. **Faint** (`oklch(0.620 0.012 258)`): disabled labels and decorative glyphs only — never load-bearing text.

### Status (universal — never re-skinned)
These four hues are safety signals with fixed meaning across every deployment. When a vertical re-brands, `--primary` changes; these do not.
- **Success / Online** — green `oklch(0.560 0.130 150)`; text `success-strong`, wash `success-subtle`.
- **Warning / Under Maintenance** — amber `oklch(0.700 0.150 75)`; text `warning-strong`, wash `warning-subtle`.
- **Danger / Offline / Critical** — red `oklch(0.550 0.200 28)`; text `danger-strong`, wash `danger-subtle`. *(This is the brand seed color, placed in its correct home.)*
- **Info** — blue-cyan `oklch(0.450 0.120 235)` text on `info-subtle`; visually distinct from Signal Blue so a re-skin never confuses "brand" with "informational."

### Named Rules
**The One Voice Rule.** Signal Blue covers ≤10% of any screen — primary action, current selection, active nav, focus. Its rarity is what makes it read as a signal instead of a theme. If two blue buttons compete on a screen, one of them is wrong.

**The Reserved-Red Rule.** Red is *only* destructive, critical, or offline. It is never a brand color, a decorative accent, an emphasis, or a "look here." A red that doesn't mean danger is a bug.

**The Swap-One-Token Rule.** Brand identity is `--primary` and its derived tints — nothing else. Re-skinning SccIT for a new organization is changing one hue and its four shades. Status colors, neutrals, and type never carry vertical-specific identity. If a screen can't be re-branded by editing one token, it has leaked identity somewhere it shouldn't.

### Normative tokens (both modes)
These custom properties are the source of truth; the frontmatter above mirrors the light set for tooling. Author every component against these role tokens, never against raw OKLCH.

```css
:root {
  /* neutrals */
  --bg: oklch(0.978 0.005 258);
  --surface: oklch(1 0 0);
  --surface-sunken: oklch(0.955 0.006 258);
  --border: oklch(0.912 0.007 258);
  --control-border: oklch(0.640 0.012 258);
  --ink: oklch(0.255 0.020 258);
  --ink-strong: oklch(0.205 0.022 258);
  --muted: oklch(0.500 0.016 258);
  --faint: oklch(0.620 0.012 258);
  /* brand — the only tokens a vertical re-skins */
  --primary: oklch(0.545 0.170 258);
  --primary-hover: oklch(0.480 0.165 258);
  --primary-active: oklch(0.430 0.150 258);
  --primary-strong: oklch(0.430 0.150 258);
  --primary-subtle: oklch(0.955 0.030 258);
  --on-primary: oklch(1 0 0);
  --focus: oklch(0.545 0.170 258);
  /* status — universal, never re-skinned */
  --success: oklch(0.560 0.130 150);
  --success-strong: oklch(0.430 0.120 150);
  --success-subtle: oklch(0.955 0.035 150);
  --warning: oklch(0.700 0.150 75);
  --warning-strong: oklch(0.470 0.110 75);
  --warning-subtle: oklch(0.955 0.045 85);
  --danger: oklch(0.550 0.200 28);
  --danger-strong: oklch(0.455 0.175 28);
  --danger-subtle: oklch(0.960 0.040 25);
  --danger-on: oklch(1 0 0);
  --info: oklch(0.450 0.120 235);
  --info-subtle: oklch(0.955 0.030 235);
}

[data-theme="dark"] {
  --bg: oklch(0.165 0.007 258);
  --surface: oklch(0.205 0.008 258);
  --surface-sunken: oklch(0.245 0.009 258);
  --border: oklch(0.300 0.010 258);
  --control-border: oklch(0.550 0.012 258);
  --ink: oklch(0.955 0.006 258);
  --ink-strong: oklch(0.985 0.004 258);
  --muted: oklch(0.720 0.012 258);
  --faint: oklch(0.560 0.010 258);
  /* brand: the FILL stays dark enough to hold white text (hover/active darken,
     as in light mode); a separate BRIGHT variant (--primary-strong) is what
     reads as brand text/border/icon against dark surfaces. */
  --primary: oklch(0.545 0.170 258);           /* button fill — white text 5.05:1 */
  --primary-hover: oklch(0.500 0.170 258);     /* fill hover — white text ~5.8:1 */
  --primary-active: oklch(0.455 0.155 258);    /* fill active — white text ~7.4:1 */
  --primary-strong: oklch(0.760 0.130 258);    /* brand TEXT on dark: links, active-nav, selected */
  --primary-subtle: oklch(0.300 0.060 258);
  --on-primary: oklch(1 0 0);
  --focus: oklch(0.760 0.130 258);
  --success: oklch(0.720 0.130 150);
  --success-strong: oklch(0.720 0.130 150);
  --success-subtle: oklch(0.280 0.045 150);
  --warning: oklch(0.780 0.130 80);
  --warning-strong: oklch(0.780 0.130 80);
  --warning-subtle: oklch(0.300 0.050 80);
  --danger: oklch(0.680 0.170 28);             /* danger text on dark */
  --danger-strong: oklch(0.680 0.170 28);
  --danger-subtle: oklch(0.300 0.070 28);
  --danger-fill: oklch(0.545 0.190 28);        /* destructive button bg */
  --danger-on: oklch(1 0 0);
  --info: oklch(0.740 0.110 235);
  --info-subtle: oklch(0.290 0.045 235);
}
```

> **Verified.** Body ink ≥ 14.8:1 (light) / 16.9:1 (dark); muted ≥ 5.6:1 / 7.2:1; white on primary and danger fills ≥ 5.0:1; every status text-on-wash ≥ 6.0:1; control borders ≥ 3.3:1; focus ring ≥ 3:1 against its background. All clear WCAG 2.2 AA.

## 3. Typography

**UI / Body Font:** Inter (variable) — with `system-ui, -apple-system, "Segoe UI", Roboto, sans-serif` fallback.
**Code / ID Font:** JetBrains Mono — with `ui-monospace, "SFMono-Regular", Menlo, monospace` fallback.

**Character:** One neutral, highly legible humanist-grotesque carries the entire UI — headings, labels, body, and dense table data — because a data tool does not need a display face competing for attention; it needs one face that stays crisp at 13px on an aging monitor. The monospace companion is not decoration: asset serial numbers, QR payloads, ticket IDs, IP addresses, and log lines belong in mono so they stay unambiguous and column-aligned. Inter and JetBrains Mono pair on a true contrast axis (proportional vs. monospaced), never two-similar-sans. Both fonts are self-hosted for performance and for networks (schools, government) that block third-party CDNs.

### Hierarchy
Fixed rem scale (product UIs are viewed at consistent DPI; fluid clamp headings would only wobble in a sidebar). Ratio ≈ 1.2. All sizes are `rem`, so browser zoom and user font-size settings scale the whole system (200% zoom reflows without loss of function). **The document base (`body`) is 16px/lh 1.6** — the comfortable primary-content default; components opt into density with Body/Label below. Sizes are tuned for readability including mild low-vision comfort while holding enterprise density (the smallest UI text is 13px, never 12px).
- **Display** (600, 1.875rem/30px, lh 1.15, tabular): large dashboard KPI numerals and empty-state figures. Rare.
- **Headline** (600, 1.5rem/24px, lh 1.25): page titles. One per screen.
- **Title** (600, 1.125rem/18px, lh 1.3): section and card headers.
- **Body** (400, 0.9375rem/15px, lh 1.5): the dense workhorse — UI text, table cells, form values, secondary metadata.
- **Body Large / Base** (400, 1rem/16px, lh 1.6): the document default and reading-heavy surfaces (knowledge-base articles, AI troubleshooting answers, form field values, prose). Cap prose at 65–75ch.
- **Label** (500, 0.8125rem/13px, lh 1.4): form labels, table column headers, metadata, timestamps, badges/pills. Sentence case by default.
- **Code** (400, 0.8125rem/13px, JetBrains Mono, slashed zero): IDs, serials, QR payloads, code, log lines.

### Named Rules
**The Tabular-Truth Rule.** Every numeric that lives in a column, metric, ID, or timestamp uses `font-variant-numeric: tabular-nums` (and slashed zero for codes). Digits must align so a technician scanning a queue reads down the column, not across each row.

**The No-Eyebrow Rule.** No tiny uppercase letter-spaced kicker above sections. Hierarchy comes from size and weight, not from a decorative all-caps label on every panel. Uppercase is permitted only on table column headers, at ≤ +0.04em tracking.

## 4. Elevation

The system is **flat by default and layered by tone.** Depth on the page plane is built from three neutral steps — Canvas behind Panel behind Recess — plus hairline borders. Inline cards, panels, toolbars, and tables carry **no shadow**; stacking them with shadows is exactly the "excessive shadows" anti-reference. Shadow is a semantic, not a texture: it appears only on elements that genuinely float above the page, and its presence tells the user "this is temporary and overlaid."

In dark mode, shadows are nearly invisible against dark surfaces, so overlays separate from the page using a lighter surface step (`--surface-sunken` or a dedicated overlay tone) plus a `--border` outline rather than relying on the shadow.

### Shadow Vocabulary
- **Overlay-sm** (`0 4px 12px oklch(0.2 0.02 258 / 0.10), 0 1px 3px oklch(0.2 0.02 258 / 0.08)`): dropdowns, popovers, comboboxes, context menus, tooltips.
- **Overlay-md** (`0 16px 40px oklch(0.2 0.02 258 / 0.16), 0 4px 8px oklch(0.2 0.02 258 / 0.10)`): dialogs, modals, the command palette.

### Named Rules
**The Flat-Plane Rule.** Surfaces are flat at rest. A shadow means the element overlays the page (menu, dialog, toast, drag preview) — never an inline card, never a "lifted" panel, never a hover-elevation on a table row. If it doesn't float, it doesn't cast.

## 5. Components

Every interactive component ships with the full state set — default, hover, focus-visible, active, disabled, and where relevant loading, selected, error. Half a set is a bug. Focus is always a **2px `--focus` ring at 2px offset**, visible on every focusable element in both modes (never removed, never replaced by color-only). Radii are restrained: `sm 4px` for controls, `md 6px` for panels, `lg 10px` for dialogs, `full` only for status dots, avatars, and toggle knobs — never for buttons or cards (rounded-everything is banned).

### Buttons
- **Shape:** 4px radius (`--radius-sm`), 34px height, `8px 14px` padding, body-size 500 weight, 150ms ease-out on color/transform.
- **Primary:** `--primary` fill, white text; hover → `--primary-hover`, active → `--primary-active`. The one filled-color button on a screen.
- **Secondary:** `--surface` fill, `--ink` text, `--control-border` 1px border; hover raises to `--surface-sunken`. The default for most actions.
- **Ghost:** transparent, `--ink` text, no border; hover → `--surface-sunken`. Toolbar and table-row actions.
- **Danger:** `--danger` (light) / `--danger-fill` (dark) fill, white text. Destructive only, and never the pre-selected default in a dialog.
- **Disabled:** `--surface-sunken` fill, `--faint` text, no pointer. **Loading:** label holds width, a 14px spinner replaces the leading icon; the button stays its own color (no dimming that drops contrast).

### Inputs / Fields
- **Style:** `--surface` fill, `--control-border` 1px border (≥3:1), 4px radius, 34px height, `7px 10px` padding, `--ink` value text, `--muted` placeholder (≥4.5:1).
- **Focus:** border → `--primary`, plus the 2px `--focus` ring at 2px offset.
- **Error:** border → `--danger`, helper text `--danger-strong`, and a text message — never color alone (color-blind users). **Disabled:** `--surface-sunken` fill, `--faint` text.
- Labels sit above the field (top-aligned) in Label type; required is marked with text or a `--danger` asterisk plus `aria-required`.

### Cards / Panels
- **Corner:** 6px (`--radius-md`). **Background:** `--surface`. **Border:** 1px `--border`. **Shadow:** none (see Flat-Plane Rule). **Padding:** 16px (`--space-lg`); dense variants 12px.
- Never nest a card inside a card. Group with spacing, a hairline divider, or a `--surface-sunken` inset instead.

### Status Pill (signature)
The vocabulary that ties tickets, assets, maintenance, and the floor plan together. **Subtle wash background + strong same-hue text + an 8px leading dot** — calm, not a saturated fill, and never color-only (the text label carries the meaning). Full radius, Label type, `2px 8px` padding.
- Online → success · Offline → danger · Under Maintenance → warning · Assigned → Signal Blue (`--primary-subtle` / `--primary-strong`) · Available → neutral (`--surface-sunken` / `--muted`).

### Data Table (signature)
- Header row: `--surface-sunken`, Label type, sticky on scroll. Rows: `--surface`, 1px `--border` between rows, `--ink` cells. Row height 40px comfortable / 32px dense (a density toggle is expected).
- Hover → `--surface-sunken`; selected → `--primary-subtle` (tone, not a left-stripe). Numeric columns right-aligned and tabular. Zebra striping is off by default; hairlines do the separating.
- Empty state teaches ("No open tickets. New requests appear here as they arrive.") with the primary next action — never a bare "No data."

### Navigation
- **Primary nav:** left sidebar, `--surface` on `--bg`, collapsible to a 56px icon rail; a drawer on mobile. Items are Body-size with a 16px leading icon.
- **Active item:** `--primary-subtle` background, `--primary-strong` text/icon, 4px radius. Hover on inactive → `--surface-sunken`. Active state is never color-only — the fill plus weight carry it.
- **Top bar:** `--surface` with a bottom `--border`, holding global search (command palette, ⌘K), environment/org switcher, notifications, and the user menu.

## 6. Do's and Don'ts

### Do:
- **Do** spend Signal Blue like currency — primary action, current selection, active nav, focus — and let neutrals plus type carry the other ~90% (The One Voice Rule).
- **Do** build every deployment's brand from the single `--primary` token and its tints (The Swap-One-Token Rule); keep status colors and neutrals vertical-neutral.
- **Do** reserve red exclusively for destructive/critical/offline (The Reserved-Red Rule).
- **Do** convey depth with the tonal stack (Canvas → Panel → Recess) and hairlines; use shadow only for floating overlays (The Flat-Plane Rule).
- **Do** pair every status color with a text label and shape/icon so meaning survives color blindness and grayscale.
- **Do** give every interactive element a visible 2px focus ring at 2px offset, and a full state set (hover/focus/active/disabled/loading).
- **Do** use tabular figures for all columnar numbers, IDs, and timestamps (The Tabular-Truth Rule).
- **Do** keep both light and dark verified at WCAG 2.2 AA — re-check contrast whenever a token value changes.

### Don't:
- **Don't** use glassmorphism or neumorphism — no frosted-glass panels, no soft-extruded "puffy" controls.
- **Don't** over-animate the dashboard, add gaming-UI or crypto-dashboard flourishes, or orchestrated page-load sequences. Motion is 150–250ms and conveys state only.
- **Don't** use purple gradients — or gradient text of any kind (`background-clip: text`). Emphasis is weight and size.
- **Don't** pile on excessive shadows or make everything rounded; buttons and cards are 4–6px, not pills.
- **Don't** ship visual clutter or "Dribbble concepts" that prioritize aesthetics over usability — if an element doesn't help resolve a request or find an asset faster, cut it.
- **Don't** look like a generic AI-generated SaaS template: no cream/sand/parchment background, no tiny uppercase tracked eyebrow above every section, no identical icon-card grids, no hero-metric template.
- **Don't** use a colored `border-left`/`border-right` stripe as an accent on cards, rows, or alerts — use a full border, a wash background, or a leading dot/icon instead.
- **Don't** signal state with color alone, and never drop below AA — muted gray at "elegant" low contrast is the single most common failure; keep secondary text ≥4.5:1.
- **Don't** nest cards, or use a display/script font in labels, buttons, or data.
