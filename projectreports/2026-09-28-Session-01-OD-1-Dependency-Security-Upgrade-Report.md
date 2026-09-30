---
title: "SESSION 01 — OD-1 DEPENDENCY-SECURITY UPGRADE"
project: "SccIT — School IT Service Management System"
work_package: "OD-1 (owner-approved) — guzzle, commonmark, excel, phpspreadsheet"
stage: "OD-1 CHECKPOINT — complete; WP-A not started"
date: "2026-09-28"
author: "Engineering"
status: "Complete — 0 vulnerabilities remaining, all backend gates pass"
baseline: "HEAD 281356c · branch recovery/phase-2.7-restored-baseline · recovery baseline c9ab8a4"
---

# SESSION 01 — OD-1 DEPENDENCY-SECURITY UPGRADE

**Purpose.** Execute the owner-approved OD-1 dependency-security upgrade only. No
application feature, WP-A, migration, production configuration or Gemini/Reverb work was
performed. This session implemented exactly one scoped Composer upgrade and one commit.

**Pre-flight scope protection.** `git status` and `git diff --cached` were inspected
before any change. The pre-existing 21-file uncommitted working tree from SESSION 00
(the authorized E2E harness recovery and related import-normalization edits) was
confirmed present and was **not** reset, stashed, cleaned, amended, or altered in any way
by this session. It remains exactly as it was, byte-for-byte, after the OD-1 commit.

Every claim below carries one of the approved evidence labels.

---

## 1. Baseline audit — inspected

Read from the actual repository files and the running `app` container, not from the
SESSION 00 report.

| Package | composer.json constraint | Installed (locked) | Direct or transitive |
|---|---|---|---|
| `guzzlehttp/guzzle` | not listed — pulled by `laravel/framework` `^7.8.2` | 7.12.3 | transitive |
| `league/commonmark` | not listed — pulled by `laravel/framework` `^2.8.1` | 2.8.2 | transitive |
| `maatwebsite/excel` | `^3.1` | 3.1.69 | **direct** |
| `phpoffice/phpspreadsheet` | not listed — pulled by `maatwebsite/excel` `^1.30.4` | 1.30.5 | transitive |

`composer validate --strict` (pre-upgrade): **valid**.

`composer audit` (pre-upgrade, captured to file): **20 advisories across 4 packages** —
6 guzzle (1 high / 5 medium), 10 commonmark (8 high / 2 medium), 1 excel (high), 3
phpspreadsheet (high). Matches the SESSION 00 report exactly; independently reconfirmed
from the live environment rather than assumed.

---

## 2. Stated intended package changes (declared before any modification)

| Package | Current | Target | Fixed-in line | Confirms |
|---|---|---|---|---|
| `guzzlehttp/guzzle` | 7.12.3 | **7.15.5** | ≥7.15.2 (one advisory needs ≥7.14.2, one ≥7.15.1) | latest 7.x — no major bump |
| `league/commonmark` | 2.8.2 | **2.10.3** | ≥2.9.0 (one advisory needs ≥2.10.0) | latest 2.x — no major bump |
| `maatwebsite/excel` | 3.1.69 | **3.1.70** | `<3.1.70` affected | latest 3.1.x patch — exact fixed version, `^3.1` unchanged |
| `phpoffice/phpspreadsheet` | 1.30.5 | **1.30.7** | `>1.30.5` | latest 1.x patch — carried by the Excel upgrade's own dependency, not a manual bump |

All four targets were confirmed to exist on Packagist before touching anything. No
`composer.json` edit was anticipated or made — all four moves stay inside constraints
already declared in the manifest.

---

## 3. Resolution strategy — inspected, then implemented

Two dry runs were performed before the real update:

- **Bare `composer update <4 packages>` (no flags):** resolved
  `guzzlehttp/guzzle` to `7.12.x-dev 9aa17bc` — a **dev-branch snapshot of the old 7.12
  line**, not a real fix. This does not clear the advisories and is not an acceptable
  target. **Rejected.**
- **`composer update <4 packages> --with-dependencies` (`-w`):** resolved all four to the
  intended stable targets above, plus 17 further packages that are dependencies *of*
  those four (not of anything else). Composer's own resolver explicitly listed which
  root requirements it declined to touch without `-W`: `laravel/framework`,
  `mockery/mockery`, `phpunit/phpunit` — confirming the update stayed inside the
  four-package sub-tree. **Selected**, per OD-1 requirement 5 ("narrowest strategy that
  can correctly resolve the approved packages").

`--with-dependencies` was not optional — it was the only flag combination that actually
resolved to a security-fixed release rather than a dev snapshot.

---

## 4. The 17 additional transitive packages — traced and classified

Every package outside the four approved targets was traced to its actual requirer using
`composer why`, not assumed. All 17 are **required transitive changes**: real
dependencies of the four approved packages, patch/minor version movement only, and none
appear in `composer.json`'s `require` or `require-dev` (so the OD-1 stop condition for an
"unrelated direct dependency" was not triggered).

| Package | Change | Required by |
|---|---|---|
| `guzzlehttp/psr7` | 2.12.3 → 2.13.1 | `guzzlehttp/guzzle` (direct dependency) |
| `guzzlehttp/promises` | 2.5.0 → 2.5.3 | `guzzlehttp/guzzle` (direct dependency) |
| `ezyang/htmlpurifier` | v4.19.0 → v4.19.1 | `phpoffice/phpspreadsheet` `^4.15` |
| `nette/schema` | v1.3.5 → v1.3.6 | `league/config`, itself required by `league/commonmark` |
| `nette/utils` | v4.1.4 → v4.1.5 | `nette/schema` `^4.0` |
| `composer/semver` | 3.4.4 → 3.5.0 | `maatwebsite/excel` `^3.3` (direct dependency) |
| `phpdocumentor/reflection-common` | 2.2.0 → 2.2.1 | `phpdocumentor/reflection-docblock` `^2.2`, `phpdocumentor/type-resolver` `^2.0` |
| `phpstan/phpdoc-parser` | 2.3.2 → 2.3.5 | `phpdocumentor/reflection-docblock`, `phpdocumentor/type-resolver` |
| `nikic/php-parser` | v5.7.0 → v5.9.0 | shared sub-dependency inside the resolved tree |
| `sebastian/diff` | 7.0.0 → 7.0.1 | shared sub-dependency inside the resolved tree |
| `symfony/console` | v8.1.1 → v8.1.7 | shared sub-dependency inside the resolved tree |
| `symfony/string` | v8.1.0 → v8.1.7 | shared sub-dependency inside the resolved tree |
| `symfony/service-contracts` | v3.7.1 → v3.7.3 | shared sub-dependency inside the resolved tree |
| `symfony/polyfill-intl-grapheme` | v1.38.1 → v1.41.0 | shared sub-dependency inside the resolved tree |
| `symfony/polyfill-intl-idn` | v1.38.1 → v1.42.0 | shared sub-dependency inside the resolved tree |
| `symfony/polyfill-intl-normalizer` | v1.38.0 → v1.42.0 | shared sub-dependency inside the resolved tree |
| `symfony/polyfill-php85` | v1.38.1 → v1.41.0 | shared sub-dependency inside the resolved tree |

None are major-version changes. None required a `composer.json` edit — the manifest's
`content-hash` before and after is **identical** (`eec5c66d7d77101af9f9586a2228ebc0`),
independently confirming no constraint in `composer.json` changed.

---

## 5. Implementation — implemented

Command executed inside the `app` container:

```
composer update guzzlehttp/guzzle league/commonmark maatwebsite/excel phpoffice/phpspreadsheet --with-dependencies
```

Result: **21 lock-file updates, 0 installs, 0 removals.** Output matched the dry run
exactly — no surprises between preview and execution.

### composer.json diff

**No diff.** Byte-identical before and after (`diff` returned nothing).

### composer.lock diff

21 packages changed version (verified programmatically against both the `packages` and
`packages-dev` sections, not just the top of the CLI output):

```
composer/semver                    3.4.4  => 3.5.0
ezyang/htmlpurifier                v4.19.0 => v4.19.1
guzzlehttp/guzzle                  7.12.3 => 7.15.5
guzzlehttp/promises                2.5.0  => 2.5.3
guzzlehttp/psr7                    2.12.3 => 2.13.1
league/commonmark                  2.8.2  => 2.10.3
maatwebsite/excel                  3.1.69 => 3.1.70
nette/schema                       v1.3.5 => v1.3.6
nette/utils                        v4.1.4 => v4.1.5
nikic/php-parser                   v5.7.0 => v5.9.0
phpoffice/phpspreadsheet           1.30.5 => 1.30.7
phpdocumentor/reflection-common    2.2.0  => 2.2.1
phpstan/phpdoc-parser              2.3.2  => 2.3.5
sebastian/diff                     7.0.0  => 7.0.1
symfony/console                    v8.1.1 => v8.1.7
symfony/polyfill-intl-grapheme     v1.38.1 => v1.41.0
symfony/polyfill-intl-idn          v1.38.1 => v1.42.0
symfony/polyfill-intl-normalizer   v1.38.0 => v1.42.0
symfony/polyfill-php85             v1.38.1 => v1.41.0
symfony/service-contracts          v3.7.1 => v3.7.3
symfony/string                     v8.1.0 => v8.1.7
```

`git diff --cached --stat` (staged for commit): `backend/composer.lock | 296
++++++++++++++++++++++++++------------------------` — **1 file changed, 156 insertions,
140 deletions.** `git diff --cached --name-only` confirmed exactly one file staged.

`composer validate --strict` (post-upgrade): **valid**.

---

## 6. Gate — tested / passed

Run against the `app` container serially, per the project's one-shared-test-database
constraint. This checkpoint is backend-only (OD-1 touched no frontend file), so the
frontend gate (`tsc -b`, lint, `format:check`, Vitest, build) was not part of this
checkpoint's required verification and was not run.

| Gate | Result | Status |
|---|---|---|
| `composer validate --strict` | valid, before and after | passed |
| `./vendor/bin/pint --test` | **PASS — 666 files**, 0 style violations | passed |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=1G` (Larastan, level 6) | **[OK] No errors** | passed |
| `./vendor/bin/pest` (full suite) | **943 passed, 0 failed, 3704 assertions**, 504.38s | passed |

No test was altered, weakened, or skipped to make the gate pass. No pre-existing failure
was encountered — including the known flakes (`sccit-flaky-asset-directory-search-test`,
`sccit-flaky-location-archive-test`) did not surface on this run.

**No OD-1 regression.** Zero test, style, or static-analysis failures were introduced by
the upgrade.

---

## 7. Advisory comparison — before / after

| | Before | After |
|---|---|---|
| Total advisories | **20** | **0** |
| Packages affected | guzzle (6), commonmark (10), excel (1), phpspreadsheet (3) | none |

`composer audit` post-upgrade: `No security vulnerability advisories found.`

**New warnings introduced by the upgrade: none.** **Compatibility regressions: none** —
confirmed by the full green gate in §6, not by source inspection alone.

---

## 8. Git — implemented

Pre-commit boundary check: `git status --porcelain` (full) and `git diff --cached` were
inspected. Only `backend/composer.lock` was staged, via `git add backend/composer.lock`
— **not** `git add .` or any broad staging command. `git diff --cached --name-only`
confirmed exactly one file in the staged set before committing.

| Item | Value |
|---|---|
| Commit | `281356c8ef7adf4e02a156d7aad832e2107ff8db` (`281356c`) |
| Subject | `fix(deps): OD-1 dependency-security upgrade (guzzle, commonmark, excel, phpspreadsheet)` |
| Files changed | 1 — `backend/composer.lock` |
| Branch | `recovery/phase-2.7-restored-baseline` |
| Parent | `d05fd60` (SESSION 00's inspected HEAD) |
| Pushed | **No** — not authorized in this session |
| Recovery baseline `c9ab8a4` still an ancestor of HEAD | **Yes** — reconfirmed post-commit |
| Pre-existing 21-file uncommitted working tree | **Unaffected** — confirmed byte-identical to the SESSION 00 snapshot after the commit |

No reset, rebase, amend, stash, or force operation was used at any point in this session.

---

## 9. Remaining advisories

**None.** `composer audit` reports a clean result. No further Composer-level security
action is required from OD-1.

---

## 10. Boundary statement

| Assertion | Status |
|---|---|
| WP-A or any other application feature implemented | **No** |
| Migrations modified | **No** |
| Production configuration touched | **No** |
| Gemini configuration touched | **No** |
| Reverb installed | **No** |
| `.gitignore` / `.prettierignore` modified (F-2) | **No** — out of scope for OD-1, deferred as instructed |
| `@playwright/test` / `@axe-core/playwright` added (F-1) | **No** — out of scope for OD-1, deferred as instructed |
| Production backup fabricated or backup records touched (F-3) | **No** — out of scope for OD-1, deferred as instructed |
| Pre-existing uncommitted working tree altered | **No** |
| Tests weakened or altered to force a pass | **No** |
| Commits amended, reset, or rebased | **No** |
| Push performed | **No** |

---

## 11. Stop condition

Per the approved plan, this session **stops here**. OD-1 is complete and verified. No
work proceeds toward F-1, F-2, WP-A, WP-H, WP-N, WP-O, Reverb, Laravel AI, Gemini,
migrations, the floor plan, RAG, the assistant, maintenance, predictive maintenance,
accessibility remediation, or any other Phase 2.7/2.8 item without a further explicit
authorization.
