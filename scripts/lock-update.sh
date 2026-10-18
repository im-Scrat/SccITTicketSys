#!/usr/bin/env sh
# =============================================================
#  SccIT — regenerate composer.lock / package-lock.json (owner decision D7).
#
#      scripts/lock-update.sh composer require vendor/package:^1.0 [...]
#      scripts/lock-update.sh composer update vendor/package --with-dependencies
#      scripts/lock-update.sh composer remove vendor/package
#      scripts/lock-update.sh npm install package@^1.2 [...]
#      scripts/lock-update.sh npm uninstall package
#
#  WHY THIS FILE EXISTS
#  Production installs dependencies while its image builds, from composer.lock
#  and package-lock.json, so a dependency change IS a lock-file change.
#  scripts/dep-update.sh made those changes inside the development stack and
#  was retired with it on 2026-10-10. On 2026-10-17 the owner decided (D7)
#  that lock files are regenerated in disposable containers built from the
#  gate images: never on the host (it has neither the PHP extensions nor
#  Linux-native node_modules, so platform checks would answer for the wrong
#  machine), and never inside a running stack (dep-update.sh's header records
#  the 592 SIGSEGV crashes a vendor/ swap under live php-fpm caused, OD-1).
#
#  WHAT IT DOES
#    1. copies the two manifests of the side being changed into a scratch
#       directory outside the repository;
#    2. runs the command in a throwaway container:
#         composer  sccit/app:gate-base, the repo's own PHP `base` stage, so
#                   platform requirements are resolved against exactly the
#                   extensions production has;
#         npm       node:22-alpine, the base of the frontend gate image;
#       with --no-install / --package-lock-only and no scripts, so nothing is
#       installed anywhere: no vendor/ or node_modules/ (host, stack or image)
#       is touched and no package lifecycle script runs;
#    3. copies the manifests back and prints what changed.
#
#  It deliberately does not run the gate. Run `sh scripts/gates.sh` next: it
#  installs from the new lock files into fresh images the way production
#  will, and a lock change is not done until that passes.
# =============================================================
set -eu

. "$(dirname "$0")/lib/stack.sh"

# Git Bash would rewrite container-side paths (/work) into Windows paths.
MSYS_NO_PATHCONV=1
export MSYS_NO_PATHCONV

hostpath() {
    if command -v cygpath >/dev/null 2>&1; then cygpath -w "$1"; else printf '%s' "$1"; fi
}

[ $# -ge 2 ] || die "usage: scripts/lock-update.sh composer|npm <subcommand> [args...]
       (see the header of this file for examples)"

TOOL="$1"; shift
SUB="$1"

case "$TOOL" in
    composer)
        SIDE='backend'; MANIFESTS='composer.json composer.lock'
        case "$SUB" in
            require|update|remove) ;;
            *) die "composer $SUB is not a lock change; use require, update or remove." ;;
        esac
        ;;
    npm)
        SIDE='frontend'; MANIFESTS='package.json package-lock.json'
        case "$SUB" in
            install|uninstall|update) ;;
            *) die "npm $SUB is not a lock change; use install, uninstall or update." ;;
        esac
        ;;
    *) die "the first argument is composer or npm, not '$TOOL'." ;;
esac

WORK="${TMPDIR:-/tmp}/sccit-lock-update"
rm -rf "$WORK" && mkdir -p "$WORK"
trap 'rm -rf "$WORK"' EXIT INT TERM

for f in $MANIFESTS; do
    cp "$REPO_ROOT/$SIDE/$f" "$WORK/$f"
done

step "$TOOL $* (in a disposable container; nothing is installed)"

if [ "$TOOL" = 'composer' ]; then
    # Only the base stage: PHP, the production extensions and Composer. It
    # copies nothing from backend/, so the build is quick and cached.
    say "building sccit/app:gate-base from docker/php/Dockerfile (target base)"
    docker build -q --target base -t sccit/app:gate-base \
        -f "$(hostpath "$REPO_ROOT/docker/php/Dockerfile")" "$(hostpath "$REPO_ROOT")" >/dev/null
    docker run --rm \
        -e COMPOSER_ALLOW_SUPERUSER=1 \
        -v "$(hostpath "$WORK"):/work" -w /work \
        sccit/app:gate-base \
        composer "$@" --no-install --no-scripts --no-interaction --no-progress
    # Informational: a new package is the moment to learn about its advisories.
    step "composer audit (lock file only)"
    docker run --rm -e COMPOSER_ALLOW_SUPERUSER=1 \
        -v "$(hostpath "$WORK"):/work" -w /work \
        sccit/app:gate-base composer audit --locked --no-interaction || warn "composer audit reported advisories (above)."
else
    docker run --rm \
        -v "$(hostpath "$WORK"):/work" -w /work \
        node:22-alpine \
        npm "$@" --package-lock-only --ignore-scripts --no-audit --no-fund
    step "npm audit (lock file only)"
    docker run --rm -v "$(hostpath "$WORK"):/work" -w /work \
        node:22-alpine npm audit --package-lock-only --omit=dev || warn "npm audit reported advisories (above)."
fi

for f in $MANIFESTS; do
    cp "$WORK/$f" "$REPO_ROOT/$SIDE/$f"
done

step "changed"
( cd "$REPO_ROOT" && git diff --stat -- "$SIDE/${MANIFESTS%% *}" "$SIDE/${MANIFESTS##* }" )

say ""
say "Next: sh scripts/gates.sh  (installs from these lock files in fresh images)."
