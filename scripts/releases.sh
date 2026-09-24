#!/usr/bin/env sh
# =============================================================
#  SccIT — release artifact retention (WP-2.7d).
#
#      scripts/releases.sh list
#      scripts/releases.sh show  <release>
#      scripts/releases.sh prune [--keep N] [--dry-run]
#
#  A "release artifact" here is a pair: the immutable image tags
#  sccit/{app,web}:prod-<sha>, and the record in releases/<sha>/manifest.json
#  describing what was deployed, what verified it, and which snapshot was taken
#  first. Rollback needs BOTH — the record alone cannot restore anything, and
#  an untagged image cannot be identified.
#
#  Retention keeps the N most recent releases by deployment time. Two are always
#  protected regardless of N: the currently deployed release, and the one it
#  would roll back to. Pruning the rollback target to save disk is how a
#  retention policy quietly becomes a single point of failure.
#
#  releases/ is machine-local and regenerable-by-deployment, so it is gitignored
#  in the same way backups/ is; this script is the committed part.
# =============================================================
set -eu

. "$(dirname "$0")/lib/stack.sh"

RELEASES="$REPO_ROOT/releases"
CMD="${1:-list}"
[ $# -gt 0 ] && shift

KEEP="${SCCIT_RELEASE_KEEP:-5}"
DRY_RUN=0
TARGET=''

while [ $# -gt 0 ]; do
    case "$1" in
        --keep) KEEP="${2:?--keep needs a number}"; shift 2 ;;
        --dry-run) DRY_RUN=1; shift ;;
        -h|--help) sed -n '2,22p' "$0"; exit 0 ;;
        -*) die "unknown argument: $1" ;;
        *) TARGET="$1"; shift ;;
    esac
done

json_field() { sed -n "s/.*\"$2\"[[:space:]]*:[[:space:]]*\"\{0,1\}\([^\",}]*\)\"\{0,1\}.*/\1/p" "$1" | head -1; }

# Releases newest-first, ordered by the manifest's own deployed_at rather than
# by directory mtime — a restored or copied directory must not reorder history.
ordered_releases() {
    [ -d "$RELEASES" ] || return 0
    for dir in "$RELEASES"/*/; do
        [ -f "$dir/manifest.json" ] || continue
        printf '%s\t%s\n' "$(json_field "$dir/manifest.json" deployed_at)" "$(basename "$dir")"
    done | sort -r | cut -f2
}

CURRENT="$(cat "$RELEASES/current" 2>/dev/null || echo '')"

case "$CMD" in

list)
    if [ -z "$(ordered_releases)" ]; then
        say "No releases recorded yet. Deploy with:  make deploy-prod"
        exit 0
    fi
    printf '%-10s  %-22s  %-10s  %-6s  %s\n' 'RELEASE' 'DEPLOYED (UTC)' 'BRANCH' 'MIGR' 'IMAGES'
    ordered_releases | while read -r rel; do
        m="$RELEASES/$rel/manifest.json"
        imgs='present'
        docker image inspect "sccit/app:prod-$rel" >/dev/null 2>&1 || imgs='APP IMAGE MISSING'
        docker image inspect "sccit/web:prod-$rel" >/dev/null 2>&1 || imgs='WEB IMAGE MISSING'
        marker=''
        [ "$rel" = "$CURRENT" ] && marker=' <- deployed'
        printf '%-10s  %-22s  %-10s  %-6s  %s%s\n' \
            "$rel" \
            "$(json_field "$m" deployed_at)" \
            "$(json_field "$m" branch)" \
            "$(json_field "$m" migration_count)" \
            "$imgs" "$marker"
    done
    ;;

show)
    [ -n "$TARGET" ] || die "usage: scripts/releases.sh show <release>"
    [ -f "$RELEASES/$TARGET/manifest.json" ] || die "no such release: $TARGET"
    cat "$RELEASES/$TARGET/manifest.json"
    ;;

prune)
    ALL="$(ordered_releases)"
    [ -n "$ALL" ] || { say "Nothing to prune."; exit 0; }

    # The current release and the next one down are the rollback pair; they are
    # protected even when --keep would drop them.
    PROTECTED="$CURRENT
$(printf '%s\n' "$ALL" | grep -v "^${CURRENT}$" | head -1)"

    INDEX=0
    printf '%s\n' "$ALL" | while read -r rel; do
        INDEX=$((INDEX + 1))
        [ "$INDEX" -le "$KEEP" ] && continue
        if printf '%s\n' "$PROTECTED" | grep -qx "$rel"; then
            say "  keep    $rel (rollback pair)"
            continue
        fi
        if [ "$DRY_RUN" -eq 1 ]; then
            say "  would prune  $rel"
            continue
        fi
        docker image rm -f "sccit/app:prod-$rel" >/dev/null 2>&1 || true
        docker image rm -f "sccit/web:prod-$rel" >/dev/null 2>&1 || true
        rm -rf "$RELEASES/$rel"
        say "  pruned  $rel"
    done
    ok "retention (keeping $KEEP most recent, plus the rollback pair)"
    ;;

*)
    die "unknown command '$CMD' (expected list, show or prune)"
    ;;
esac
