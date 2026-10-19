---
title: "Documentation Deliverable Regeneration Report"
project: "SccIT — School IT Service Management System"
work_package: "Documentation build — Word deliverables from the reconciled Markdown"
stage: "COMPLETE — awaiting acceptance"
date: "2026-09-06"
author: "Engineering"
status: "Committed b30969f · NOT pushed · NOT deployed · SRS/SDD/SPMP all Version 1.0"
baseline: "SRS / SDD / SPMP Version 1.0 (frozen — unchanged)"
---

# Documentation Deliverable Regeneration Report

The Word deliverables are rebuilt from the reconciled canonical Markdown using
the project's own pipeline. No `.docx` was hand-edited and no Markdown source
was modified.

---

## 1. Executive summary

The three Word deliverables were regenerated and all three changed. They were
**two reconciliations behind, not one** — see §3, which is the finding worth
your attention.

Everything was produced by the existing two-step build; nothing was written by
hand, per CLAUDE.md §7 (*"Never hand-edit the `.docx` files"*).

**Thirty-six content checks were run against the generated `.docx` themselves**
— not against the Markdown that fed them — and all thirty-six pass.

---

## 2. Build command / script used

The project's documented pipeline, run in order from `docs/`:

```bash
cd docs
python build_publication_sources.py   # docs/*.md  ->  docs/_publish/*.md
python build_word_deliverables.py     # docs/_publish/*.md  ->  ../deliverables/word/*.docx
```

**Step 1** extracts the SDD's inline Mermaid diagrams, renders them as
publication figures, and emits publication copies with developer-only artifacts
stripped. **Step 2** renders those copies through pandoc against the shared
`reference.docx` template, then applies the figure, table and navigation
production rules and prints a structural verification.

Both scripts state that the Markdown sources are never modified, and the git
status confirms it: the three canonical documents are untouched by this task.

### Toolchain verified before running

| Dependency | Status |
|---|---|
| `pypandoc` | 1.17 |
| `pandoc` | 3.9 |
| `python-docx` | present |
| `Pillow` | present |
| Mermaid CLI | invoked via `npx @mermaid-js/mermaid-cli`; 34 diagrams already committed, so a render failure would have been non-fatal |

### Build output

```
SDD: extracted + rendered 11 Mermaid diagrams -> figures
SRS removed: Appendix B (Mermaid/PlantUML sources + render commands);
             §9.1 ASCII text-diagram (superseded by Figure 1)
SPMP: no developer artifacts present (0 code fences) — copied as-is

doc     figures  tables  tbl-caps  lists  leads  nav
SRS           6      52        52     18      9  TOC+LOF+LOT
SDD          11      14        14     34      9  TOC+LOF+LOT
SPMP          0      18        18     21      8  TOC+LOT
```

---

## 3. Finding — the deliverables were two reconciliations behind

The report that authorized this task said `deliverables/word/` held the
*pre-reconciliation* build. That was true, and understated.

`docs/_publish/*.md` — the intermediate the Word build actually reads — was
dated **2026-08-28**, while the `.docx` files were dated **2026-08-30**. The
publication sources therefore contained **neither**:

| Missing from `_publish` before this build | Date |
|---|---|
| The **WP-2.6b** documentation amendments | 2026-08-30 |
| The **Phase 2.7** reconciliation (WP-2.6b/2.7a/2.7b/2.7d) | 2026-09-06 |

Verified by counting revision rows before the build:

| Document | `2026-08-30` rows in canonical | in `_publish` | `2026-09-06` rows in canonical | in `_publish` |
|---|:--:|:--:|:--:|:--:|
| SRS | 1 | **0** | 3 | **0** |
| SDD | 1 | **0** | 3 | **0** |
| SPMP | 1 | **0** | 3 | **0** |

**Consequence:** the 2026-08-30 Word build was produced from stale
intermediates, so **the WP-2.6b documentation amendments had never reached the
Word deliverables at all.** Running only step 2 would have rebuilt from those
same stale sources and silently reproduced the gap. Both steps were run, and
both reconciliations are now included.

This also explains the SDD file-size anomaly noted during the 2026-08-30 build:
that build was rendering an older document than the repository contained.

---

## 4. Files regenerated

| File | Before | After | Δ |
|---|---:|---:|---:|
| `deliverables/word/Software Requirements Specification.docx` | 3,292,680 | 3,301,397 | +8,717 |
| `deliverables/word/Software Design Description.docx` | 1,428,351 | 1,444,196 | +15,845 |
| `deliverables/word/Software Project Management Plan.docx` | 47,979 | 55,365 | +7,386 |

Checksums changed for all three:

| File | Before (md5) | After (md5) |
|---|---|---|
| SRS | `6949ab83…` | `eb973618…` |
| SDD | `407380ee…` | `5bbeb026…` |
| SPMP | `a3773f7e…` | `df44e0a6…` |

The growth is proportionate to the added text; no size anomaly.

**`docs/diagrams/` is unchanged** — the 11 SDD Mermaid diagrams re-rendered
byte-identically, so all 53 tracked diagram files are untouched.

---

## 5. Validation results

Thirty-six checks were run against text extracted from the generated `.docx`
(paragraphs **and** table cells), not against the Markdown.

### 1–3. Deliverables reflect the reconciled Markdown

| Check | SRS | SDD | SPMP |
|---|:--:|:--:|:--:|
| WP-2.7d revision row present | ✅ | ✅ | ✅ |
| WP-2.7a revision row present | ✅ | ✅ | ✅ |
| WP-2.7b revision row present | ✅ | ✅ | ✅ |

### 4. Version 1.0

| Document | States 1.0 | Contains "Version 1.1" |
|---|:--:|:--:|
| SRS | ✅ | **No** |
| SDD | ✅ | **No** |
| SPMP | ✅ | **No** |

### 5. FR-NOT-003 — the approved 9-of-12 state

| Check | Result |
|---|:--:|
| Reads *"partly satisfied"* | ✅ |
| Reads *"nine of its twelve triggers are implemented"* | ✅ |
| Carries *"must not be recorded as satisfied"* | ✅ |
| Twelve-row trigger table present — *Low-stock reorder* (7) and *New announcements* (12) both listed | ✅ |

### 6. WP-2.6b / 2.7a / 2.7b / 2.7d reconciliation present

| Check | Result |
|---|:--:|
| SDD §24 reads **"Scan/verify (implemented — WP-2.6b)"** | ✅ |
| SDD Administration is **"partly implemented"** | ✅ |
| DD-52 marked **"Realized as written"** | ✅ |
| DD-58, DD-63, DD-64, DD-65 present | ✅ |
| `NotificationDispatcher` in the service table | ✅ |
| `features/notifications` slice recorded | ✅ |
| SPMP carries WP-2.7a / 2.7b / 2.7c / 2.7d | ✅ |
| SRS FR-QR-011 *"defect found and corrected"* | ✅ |
| SRS OI-06 *"STILL OPEN"* | ✅ |
| SRS D5 *"It does not send email"* | ✅ |
| SRS FR-NOT-002 *"obligation on the system"* | ✅ |

### 7. Deferred items correctly marked

| Check | Result |
|---|:--:|
| Migration state — *"30 → 31"* and *"production carries 30"* | ✅ |
| Rollback — *"never been exercised"* | ✅ |
| D1 digest recorded as approved but not implemented (DD-63) | ✅ |

### 8. No unrelated content changed

The canonical Markdown was **not modified by this task** —
`git status docs/Software*.md` is empty, and the build scripts state that
sources are never touched. The `.docx` differences derive solely from the
already-accepted `f044bca` content plus the WP-2.6b rows that had never been
rendered.

### One check reported FAIL, and it is a pass

My check *"NO stale 'not in the repository'"* fired on the SDD. Investigated:
the phrase occurs **exactly once**, inside the **2026-09-06 revision-history
row**, which quotes the old wording in order to describe the correction. The
live §24 text reads *"Scan/verify (implemented — WP-2.6b)"*. The check was too
blunt; the document is correct.

**Effective result: 36 / 36.**

### 9–12. Nothing outside the documentation build

| Category | Files changed |
|---|:--:|
| Application / backend source | **0** |
| Frontend source | **0** |
| Database schema · migrations | **0** (31 on disk, unchanged) |
| Tests | **0** |
| Docker · deployment configuration | **0** |
| Production data | **untouched** |

---

## 6. Files changed

```
deliverables/word/Software Design Description.docx       | Bin 1428351 -> 1444196 bytes
deliverables/word/Software Project Management Plan.docx  | Bin   47979 ->   55365 bytes
deliverables/word/Software Requirements Specification.docx | Bin 3292680 -> 3301397 bytes
docs/_publish/Software Design Description.md             |  73 +++++++---
docs/_publish/Software Project Management Plan.md        | 100 ++++++++++--
docs/_publish/Software Requirements Specification.md     | 130 +++++++++++++++-
6 files changed, 275 insertions(+), 28 deletions(-)
```

The three `_publish` files are **tracked build intermediates** (3 tracked files
in `git ls-files docs/_publish`), so they belong in the same commit as the
`.docx` they produced. Committing the Word files without them would leave the
repository unable to reproduce the build.

---

## 7. Git status

| Item | Value |
|---|---|
| Branch | `chore/phase-0-tooling-recovery` |
| HEAD | `b30969f8ff507fe60d8da2b6e05c64aa50a1be5d` |
| Subject | `docs: regenerate reconciled Word deliverables` |
| Parent | `f044bca` — the reconciliation commit, **intact and unamended** |
| Files in commit | **6** — 3 `.docx` + 3 `_publish/*.md` |
| Tracked working tree | **Clean** |
| Recovery baseline | `c9ab8a4` — untouched |
| Pushed | **No** — 0 remote refs contain HEAD |
| Deployed | **No** |

Nothing was reset, rebased or amended. `f044bca` verifies as an intact commit
with its original subject.

### Tracking policy

The `.docx` files **are tracked** (`git ls-files deliverables/` returns all
three) and `.gitignore` carries no rule for `deliverables/` or `*.docx`, so the
project's policy is to version the deliverables. They were committed
accordingly rather than reported as ignored.

---

## 8. Commit hash

```
b30969f8ff507fe60d8da2b6e05c64aa50a1be5d
docs: regenerate reconciled Word deliverables
```

---

## 9. Confirmation — no application implementation changed

**Confirmed.** The commit contains six files: three Word deliverables and three
publication intermediates. It contains **zero** backend files, **zero** frontend
files, **zero** migrations, **zero** tests and **zero** Docker or configuration
files. Migrations remain at **31** on disk and none appears in the commit. No
test was run because no executable artefact changed.

---

## 10. Known limitations

1. **The `_publish` staleness could recur.** Nothing enforces that step 1 runs
   before step 2 — they are separate scripts, and running only the second
   silently rebuilds from whatever intermediates happen to be on disk. That is
   exactly what produced the 2026-08-30 gap. A guard (or a single `make docs`
   target running both) would prevent it; that is a tooling change and outside
   this task's scope, so it is reported rather than made.

2. **The generated TOC, LOF and LOT are field codes.** Word populates them on
   first open with "update fields"; they will read as placeholders until then.
   That is how the pipeline has always produced them and is unchanged here.

3. **Diagram rendering depends on `npx`.** The Mermaid CLI is fetched on demand
   and the script does not check its exit code. It succeeded this time and the
   output was byte-identical, but a silent failure would leave stale figures
   without warning.

---

## 11. Assessment

## **GREEN**

The deliverables are rebuilt from the reconciled canonical Markdown by the
project's own pipeline, with no hand-editing. All three still state **Version
1.0**. FR-NOT-003 carries the approved **nine-of-twelve** wording, and all four
work-package reconciliations are present in the generated documents.

The build surfaced a real gap: the deliverables were **two reconciliations
behind**, and the WP-2.6b amendments had never been rendered. Both are now
included.

Scope held exactly — six files, all documentation build output; no application
implementation of any kind changed.

---

## 12. Recommended next step

**Review and accept the regenerated deliverables.** The Word documents in
`deliverables/word/` are now the current, reconciled Version 1.0 set and are
suitable for client or thesis submission.

When you are ready, the outstanding options are unchanged from the previous
report:

1. **WP-2.7c — announcements.** D5 is settled and its dependency on WP-2.7a is
   satisfied; the infrastructure needs one topic case and one listener.
2. **WP-2.7e — the D1 digest.** Fully specified in DD-63, including both
   recorded hazards.
3. **Deploy Phase 2.7 to production.** The only way the 31st migration and the
   FR-QR-011 fix reach the deployed bundle, and what converts the accepted
   F-2/F-3 rollback risks into verified behaviour.

I have not started any of them.
