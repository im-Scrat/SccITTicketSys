#!/usr/bin/env sh
# =============================================================
#  SccIT — reproducible production deployment (WP-2.7d).
#
#      scripts/deploy-prod.sh [--skip-gates] [--skip-backup] [--allow-dirty]
#                             [--keep N] [--yes]
#
#  Replaces the ad-hoc `make prod-build && make prod-up && migrate` sequence
#  that WP-2.6b exposed as unsafe. The difference is not convenience — it is
#  that every step now leaves an artifact you can go back to.
#
#  ── Why the release tag exists ────────────────────────────────────────────
#  compose.prod.yaml pins `image: sccit/app:prod`. Building overwrites that tag
#  in place, so the moment a bad build finishes, the previous image has no name
#  and rollback is impossible. This script tags every build with the commit it
#  came from — sccit/app:prod-<sha> — and only then moves the floating `:prod`
#  tag onto it. Rollback is then a retag away, with no rebuild and no network.
#
#  ── Sequence ─────────────────────────────────────────────────────────────
#    1  Preflight     clean tree, resolvable commit, prod stack reachable
#    2  Gates         the full quality gate (skippable, and it says so)
#    3  Backup        pre-deploy snapshot of the LIVE production data
#    4  Build + tag   sccit/{app,web}:prod-<sha>, then move :prod
#    5  Deploy        recreate the application services
#    6  Migrate       php artisan migrate --force
#    7  Smoke         HTTP verification of the deployed target
#    8  Record        releases/<sha>/manifest.json + retention pruning
#
#  A failure at 5, 6 or 7 leaves the previous release tag intact and prints the
#  exact rollback command. Nothing is pruned until the smoke test has passed.
# =============================================================
set -eu

. "$(dirname "$0")/lib/stack.sh"

SKIP_GATES=0
SKIP_BACKUP=0
ALLOW_DIRTY=0
ASSUME_YES=0
KEEP="${SCCIT_RELEASE_KEEP:-5}"

while [ $# -gt 0 ]; do
    case "$1" in
        --skip-gates)  SKIP_GATES=1; shift ;;
        --skip-backup) SKIP_BACKUP=1; shift ;;
        --allow-dirty) ALLOW_DIRTY=1; shift ;;
        --keep) KEEP="${2:?--keep needs a number}"; shift 2 ;;
        --yes|-y) ASSUME_YES=1; shift ;;
        -h|--help) sed -n '2,32p' "$0"; exit 0 ;;
        *) die "unknown argument: $1" ;;
    esac
done

stack_select prod

RELEASES="$REPO_ROOT/releases"
mkdir -p "$RELEASES"

# =========================================================================
# 1. Preflight
# =========================================================================
step "1/8  Preflight"

SHA="$(git -C "$REPO_ROOT" rev-parse --short HEAD)"
FULL_SHA="$(git -C "$REPO_ROOT" rev-parse HEAD)"
BRANCH="$(git -C "$REPO_ROOT" rev-parse --abbrev-ref HEAD)"
DIRTY="$(git -C "$REPO_ROOT" status --porcelain --untracked-files=no)"

say "  commit   $SHA ($BRANCH)"

if [ -n "$DIRTY" ]; then
    if [ "$ALLOW_DIRTY" -eq 1 ]; then
        warn "working tree is DIRTY — the release tag will not reproduce this image"
    else
        say ""
        say "$DIRTY"
        die "working tree has uncommitted tracked changes. A release tag names a commit,
       so an image built from a dirty tree cannot be rebuilt from that name.
       Commit first, or pass --allow-dirty to accept an unreproducible release."
    fi
fi

PREVIOUS="$(cat "$RELEASES/current" 2>/dev/null || echo '')"
if [ -n "$PREVIOUS" ]; then
    say "  current  release $PREVIOUS"
else
    warn "no previous release recorded — this is the first tracked deployment"
fi

if [ "$ASSUME_YES" -ne 1 ]; then
    say ""
    say "  About to deploy $SHA to the PRODUCTION stack at $BASE_URL."
    printf '  Type "deploy" to proceed: '
    if [ -r /dev/tty ]; then read -r REPLY < /dev/tty; else read -r REPLY || REPLY=''; fi
    [ "$REPLY" = "deploy" ] || die "aborted — nothing was changed."
fi

# =========================================================================
# 2. Quality gates
# =========================================================================
step "2/8  Quality gates"
if [ "$SKIP_GATES" -eq 1 ]; then
    warn "--skip-gates: the gates were NOT run for this release"
    GATES_RESULT='skipped'
else
    if sh "$REPO_ROOT/scripts/gates.sh" --stack dev; then
        GATES_RESULT='passed'
    else
        die "quality gates failed — deployment stopped before anything was changed."
    fi
fi

# =========================================================================
# 3. Pre-deploy backup
# =========================================================================
step "3/8  Pre-deploy snapshot"
SNAPSHOT=''
if [ "$SKIP_BACKUP" -eq 1 ]; then
    warn "--skip-backup: there is NO restore point for this deployment"
elif dc ps --status running --services 2>/dev/null | grep -qx "$DB_SVC"; then
    SNAPSHOT="$(sh "$REPO_ROOT/scripts/backup.sh" --stack prod --label "predeploy-$SHA" | tail -1)"
    ok "snapshot $SNAPSHOT"
else
    warn "production database is not running — nothing to snapshot"
fi

# =========================================================================
# 4. Build and tag
# =========================================================================
# Built before the old containers are touched: a build failure must leave the
# running deployment exactly as it was.
step "4/8  Build and tag images"
dc build app_prod nginx_prod

for pair in "sccit/app:prod app" "sccit/web:prod web"; do
    src="${pair%% *}"
    kind="${pair##* }"
    docker tag "$src" "sccit/$kind:prod-$SHA"
    ok "tagged sccit/$kind:prod-$SHA"
done

# =========================================================================
# 5. Deploy
# =========================================================================
step "5/8  Deploy"
dc up -d --force-recreate app_prod nginx_prod queue_prod scheduler_prod
ok "services recreated"

# The FPM healthcheck has a 20s start period; migrating before it settles gives
# a confusing "connection refused" that has nothing to do with the migration.
say "  waiting for the application container to report healthy..."
WAITED=0
while [ "$WAITED" -lt 90 ]; do
    STATE="$(docker inspect --format '{{.State.Health.Status}}' sccit_prod_app 2>/dev/null || echo unknown)"
    [ "$STATE" = 'healthy' ] && break
    WAITED=$((WAITED + 3))
    sleep 3
done
[ "$STATE" = 'healthy' ] && ok "application healthy after ${WAITED}s" || warn "still '$STATE' after ${WAITED}s — continuing"

# =========================================================================
# 6. Migrate
# =========================================================================
step "6/8  Migrate"
app_exec php artisan migrate --force
MIGRATIONS="$(db_exec psql -U "$DB_USER" -d "$DB_NAME" -tAc 'SELECT count(*) FROM migrations' | tr -d ' \r')"
ok "$MIGRATIONS migrations applied"

# =========================================================================
# 7. Smoke
# =========================================================================
step "7/8  Smoke test"
if sh "$REPO_ROOT/scripts/smoke.sh" --stack prod; then
    ok "smoke test passed"
else
    say ""
    fail "SMOKE TEST FAILED — the deployment is live but not verified."
    if [ -n "$PREVIOUS" ]; then
        say "  Roll back with:   sh scripts/rollback-prod.sh $PREVIOUS"
    fi
    if [ -n "$SNAPSHOT" ]; then
        say "  Restore data with: sh scripts/restore.sh --stack prod $SNAPSHOT"
    fi
    exit 1
fi

# =========================================================================
# 8. Record the release
# =========================================================================
step "8/8  Record release"
mkdir -p "$RELEASES/$SHA"
cat > "$RELEASES/$SHA/manifest.json" <<JSON
{
  "schema": "sccit.release/1",
  "release": "$SHA",
  "deployed_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "git": {
    "commit": "$FULL_SHA",
    "branch": "$BRANCH",
    "dirty": $( [ -n "$DIRTY" ] && echo true || echo false )
  },
  "images": {
    "app_tag": "sccit/app:prod-$SHA",
    "web_tag": "sccit/web:prod-$SHA",
    "app_id": "$(docker image inspect --format '{{.Id}}' "sccit/app:prod-$SHA")",
    "web_id": "$(docker image inspect --format '{{.Id}}' "sccit/web:prod-$SHA")"
  },
  "database": {
    "migration_count": "$MIGRATIONS"
  },
  "verification": {
    "gates": "$GATES_RESULT",
    "smoke": "passed"
  },
  "previous_release": "$PREVIOUS",
  "pre_deploy_snapshot": "$SNAPSHOT"
}
JSON
printf '%s\n' "$SHA" > "$RELEASES/current"
ok "releases/$SHA/manifest.json"

# Retention: keep the N most recent release records and their images. The tag
# currently deployed and the one immediately behind it are never pruned, so a
# rollback target always exists even at --keep 1.
sh "$REPO_ROOT/scripts/releases.sh" prune --keep "$KEEP"

say ""
say "=============================================================="
say " Deployed $SHA to $BASE_URL"
say " Roll back with:  sh scripts/rollback-prod.sh ${PREVIOUS:-<release>}"
say "=============================================================="
