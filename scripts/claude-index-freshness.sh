#!/usr/bin/env bash
# Fast index-staleness check for Claude Code's SessionStart hook.
#
# Neither Graphify nor codebase-memory-mcp watches the filesystem, so both go
# stale silently after code changes. This does not rebuild them — a rebuild
# takes ~1-2 minutes and must not be attached to every session start. It only
# reports, so a session never quietly reasons from a stale graph.
#
# Must stay fast (<0.5s). It therefore asks git rather than walking the tree:
# a full `find -newermt` sweep over backend/ + frontend/ costs ~18s on Windows.
# Always exits 0 — a broken health check must never block a session.

set -u

ROOT="${CLAUDE_PROJECT_DIR:-$(pwd)}"
cd "$ROOT" 2>/dev/null || exit 0

SRC_DIRS="backend/app backend/routes backend/database/migrations frontend/src"
STALE=""

# ── Graphify: GRAPH_REPORT.md records the commit the graph was built from.
# Read that rather than graph.json, which is ~10MB and slow to parse.
REPORT="$ROOT/graphify-out/GRAPH_REPORT.md"
HEAD_FULL="$(git rev-parse HEAD 2>/dev/null || echo '')"
if [ ! -f "$REPORT" ]; then
  STALE="${STALE}  - Graphify graph missing.  build: graphify update . --force
"
elif [ -n "$HEAD_FULL" ]; then
  BUILT="$(sed -n 's/^- Built from commit: `\([0-9a-f]*\)`.*/\1/p' "$REPORT" | head -1)"
  if [ -n "$BUILT" ]; then
    case "$HEAD_FULL" in
      "$BUILT"*) : ;;
      *) STALE="${STALE}  - Graphify built at ${BUILT}, HEAD is ${HEAD_FULL:0:8}.  refresh: graphify update . --force
" ;;
    esac
  fi
fi

# ── codebase-memory-mcp: the DB carries no commit marker, so compare its mtime
# against (a) the HEAD commit time and (b) the mtime of any uncommitted source
# file. Both come from git, so cost stays flat regardless of repo size.
# codebase-memory names each index after the repository's absolute path, with
# the separators flattened (C:/Users/x/Repo -> C-Users-x-Repo). Derive it rather
# than hard-coding one machine's filename, and fall back to matching on the
# folder name so a different checkout location still finds its index.
DB_DIR="$HOME/.cache/codebase-memory-mcp"
DB_KEY="$(printf '%s' "$(pwd -W 2>/dev/null || pwd)" | sed 's#:##g; s#[/\\]#-#g')"
DB="$DB_DIR/$DB_KEY.db"

if [ ! -f "$DB" ]; then
  DB="$(ls -1 "$DB_DIR"/*"$(basename "$ROOT")".db 2>/dev/null | head -1)"
fi
REFRESH_CBM="  refresh: index_repository(repo_path=\"$ROOT\", mode=\"full\")  # full, never moderate/fast"

if [ ! -f "$DB" ]; then
  STALE="${STALE}  - codebase-memory index missing for this project.
${REFRESH_CBM}
"
else
  DB_MTIME="$(stat -c %Y "$DB" 2>/dev/null || echo 0)"
  OUTDATED=0

  HEAD_TIME="$(git log -1 --format=%ct 2>/dev/null || echo 0)"
  [ "$HEAD_TIME" -gt "$DB_MTIME" ] 2>/dev/null && OUTDATED=1

  if [ "$OUTDATED" = "0" ]; then
    # Only uncommitted source files need stat'ing; that list is normally tiny.
    while IFS= read -r line; do
      f="${line:3}"
      [ -f "$f" ] || continue
      m="$(stat -c %Y "$f" 2>/dev/null || echo 0)"
      if [ "$m" -gt "$DB_MTIME" ] 2>/dev/null; then OUTDATED=1; break; fi
    done <<EOF
$(git status --porcelain -- $SRC_DIRS 2>/dev/null)
EOF
  fi

  if [ "$OUTDATED" = "1" ]; then
    STALE="${STALE}  - codebase-memory index predates the newest source change.
${REFRESH_CBM}
"
  fi
fi

if [ -n "$STALE" ]; then
  MSG="STALE CODE INDEX - do not trust graph answers until refreshed:
${STALE}"
  ESCAPED="$(printf '%s' "$MSG" | python -c 'import json,sys; print(json.dumps(sys.stdin.read()))' 2>/dev/null)"
  [ -n "$ESCAPED" ] && printf '{"hookSpecificOutput":{"hookEventName":"SessionStart","additionalContext":%s}}\n' "$ESCAPED"
fi

exit 0
