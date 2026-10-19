---
title: "SESSION 03 — REMOTE HISTORY CLEANUP (REMOVE CLOUD SESSION 00 REPORT COMMIT)"
project: "SccIT — School IT Service Management System"
work_package: "Owner-directed removal of 1d31ce0 from origin/recovery/phase-2.7-restored-baseline"
stage: "COMPLETE — single exact-lease ref update; STOPPED"
date: "2026-09-28"
author: "Engineering"
status: "Complete — remote moved 1d31ce0 → 742ded5; 1d31ce0 unreachable from the branch; working tree byte-identical"
baseline: "remote & HEAD 742ded5 · 281356c · d05fd60 · 18aa6c4 · 1debc58 · c9ab8a4"
---

# SESSION 03 — REMOTE HISTORY CLEANUP

**Owner decision executed.** The owner decided that commit `1d31ce0`, the docs-only cloud
Session 00 report (`projectreports/2026-09-27-Phase-2.7-2.8-Session-00-Execution-Environment-and-Safety-Checkpoint-Report.md`),
must not remain on `recovery/phase-2.7-restored-baseline`. It was **not** fetched,
merged or preserved.

---

## 1. Pre-change verification

| Check | Evidence | Status |
|---|---|---|
| Live remote tip is exactly `1d31ce0caaed4baeb446d9cd480deb298fa4a908` | `git ls-remote` | **passed** |
| Local HEAD is `742ded5` | `742ded58d0100b92419d9d83cf84cdc4f61dc6a6` on `recovery/phase-2.7-restored-baseline` | **passed** |
| `281356c` is the parent of `742ded5` | `git rev-parse 742ded5^` | **passed** |
| `d05fd60`, `c9ab8a4` are ancestors | `merge-base --is-ancestor` → YES | **passed** |
| `1d31ce0` is NOT an ancestor of `742ded5` | object absent locally; 0 occurrences in `rev-list HEAD` | **passed** |
| Proposed target history | `742ded5 → 281356c → d05fd60 → 18aa6c4 → 1debc58 → c9ab8a4`. The owner's list abbreviates `18aa6c4`/`1debc58`; both were already in history and stay untouched | **inspected** |
| Other refs holding `1d31ce0` | none: no other branch, tag, PR or pull ref | **inspected** |
| Branch protection | `protected: false` | **inspected** |
| Hooks | no `pre-push` hook (nothing bypassed) | **inspected** |
| Working tree before | tracked-diff SHA-256 `79c8b2b8…a1c911dea7024`; 21 modified, 0 staged | **inspected** |

**Stated before acting:** the operation makes `1d31ce0` and its single added file
(349 lines) unreachable from the branch. No other ref holds it; GitHub may still serve
the object by SHA until its own garbage collection.

---

## 2. Remote operation — implemented

```
git push --porcelain \
  --force-with-lease=refs/heads/recovery/phase-2.7-restored-baseline:1d31ce0caaed4baeb446d9cd480deb298fa4a908 \
  origin 742ded58d0100b92419d9d83cf84cdc4f61dc6a6:refs/heads/recovery/phase-2.7-restored-baseline
```

- **Exact expected-old-value lease.** The server applies the update only if the ref is
  `1d31ce0` at that moment. No bare `--force`, no `+` refspec, no `--mirror`.
- **One ref only.** Nothing else was pushed, and no branch was deleted.
- **Result:** `1d31ce0...742ded5 (forced update)`, `Done`, exit 0.

| | SHA |
|---|---|
| **Old remote SHA** | `1d31ce0caaed4baeb446d9cd480deb298fa4a908` |
| **New remote SHA** | `742ded58d0100b92419d9d83cf84cdc4f61dc6a6` |

---

## 3. Post-change verification

| Check | Evidence | Status |
|---|---|---|
| Live remote points to `742ded5` | `git ls-remote`; local tracking ref also `742ded5` | **passed** |
| `1d31ce0` no longer reachable from the branch | GitHub compare `1d31ce0...branch` → `diverged`, `behind_by: 1`, merge base `d05fd60` | **passed** |
| Remote branch history | `742ded5, 281356c, d05fd60, 18aa6c4, 1debc58, c9ab8a4, 24d46ac, …` | **passed** |
| `281356c` / `d05fd60` / `c9ab8a4` remain ancestors | GitHub compare `X...branch` → `behind_by: 0` for each | **passed** |
| No application, migration or dependency file changed | HEAD unchanged; 0 staged; 0 tracked changes outside the pre-existing 21 | **passed** |
| 21-file working tree byte-for-byte unchanged | tracked-diff SHA-256 before = after = `79c8b2b8…a1c911dea7024` | **passed** |

---

## 4. Unexpected condition

The untracked-entry count was **40** at the pre-change snapshot (09:07:35 +08) and **39**
afterwards. `projectreports/` changed at **09:08:12**, before the push and without any
command from this session touching it. All 36 known report files and the 3 known
artifact directories are present, and no tracked file changed. So one **transient,
unidentified** untracked entry appeared and was removed by something outside this
session. The most plausible cause is a Microsoft Word lock file (`~$….docx`) from a
report being opened and closed; this **cannot be confirmed after the fact**.
**Status: inspected — not attributable; no preserved content affected.**

---

## 5. Still unresolved (not touched)

F-1, F-2, F-3, N-2, N-3; production Reverb runtime and `:8081` WebSocket/CSP are
**not yet verified**; WP-O and WP-B remain **unauthorized**.

A Claude Code cloud session can push to this branch. Future checkpoints should verify
the remote with `git ls-remote`, not the local tracking ref.

---

## 6. Stop

Remote history cleanup is complete and verified. **Stopped.** No WP-B work begun.
