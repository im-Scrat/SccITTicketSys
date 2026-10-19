#!/usr/bin/env sh
# =============================================================
#  Disposable, isolated environment for the quality gate.
#
#  WHY THIS FILE EXISTS
#  From 2026-10-02 the owner directed that the development stack (:8080) is no
#  longer used at all, and that automated tests run in a disposable isolated
#  environment with its own PostgreSQL and Redis — never against development
#  or production data. The production image cannot host the gate either: it
#  installs with --no-dev, so Pest, Pint, PHPStan and the frontend toolchain
#  are absent from it by design.
#
#  WHAT IT DOES
#    iso_prepare  export the tree under test into a clean build context OUTSIDE
#                 the repository (tracked files + untracked-but-not-ignored, so
#                 new files are tested and .env/vendor/node_modules never are),
#                 build sccit/app:gate + sccit/node:gate from it, and start a
#                 tmpfs pgvector Postgres (initialised by the repo's own
#                 docker/postgres/init scripts) + Redis on an --internal network
#    iso_be CMD…  run a backend command in a throwaway app container
#    iso_fe 'CMD' run a frontend command in a throwaway node container (no network)
#    iso_cleanup  remove the containers and the network (call it from a trap)
#
#  Nothing here touches either Compose stack, their volumes, or their images.
# =============================================================

# Git Bash rewrites container-side paths (`--tmpfs /var/lib/...`) into Windows
# paths, which docker then rejects. Host paths are converted explicitly below.
MSYS_NO_PATHCONV=1
export MSYS_NO_PATHCONV

ISO_NET='sccit_gate_net'
ISO_PG='sccit_gate_postgres'
ISO_REDIS='sccit_gate_redis'
ISO_CTX="${SCCIT_GATE_CTX:-${TMPDIR:-/tmp}/sccit-gate-ctx}"
ISO_APP_KEY=''

_iso_hostpath() {
    if command -v cygpath >/dev/null 2>&1; then cygpath -w "$1"; else printf '%s' "$1"; fi
}

iso_cleanup() {
    docker rm -f "$ISO_PG" "$ISO_REDIS" >/dev/null 2>&1
    docker network rm "$ISO_NET" >/dev/null 2>&1
    return 0
}

iso_prepare() {
    iso_cleanup

    rm -rf "$ISO_CTX" && mkdir -p "$ISO_CTX" || return 1
    # --ignore-failed-read: a tracked file deleted in the working tree is
    # still listed by `git ls-files --cached`; its absence is the change.
    ( cd "$REPO_ROOT" && git ls-files -z --cached --others --exclude-standard \
        | tar --null --ignore-failed-read -T - -cf - 2>/dev/null ) \
        | tar -xf - -C "$ISO_CTX" || return 1
    # `cd` rather than `git -C`: with path conversion off, Git for Windows
    # would receive an untranslated /c/... path.
    say "gate context: $(find "$ISO_CTX" -type f | wc -l | tr -d ' ') files from $(cd "$REPO_ROOT" && git rev-parse --short HEAD)$( [ -n "$(cd "$REPO_ROOT" && git status --porcelain)" ] && echo ' + working-tree changes')"

    ctx="$(_iso_hostpath "$ISO_CTX")"
    docker build -q --target base -t sccit/app:gate-base \
        -f "$(_iso_hostpath "$ISO_CTX/docker/php/Dockerfile")" "$ctx" >/dev/null || return 1
    docker build -q -t sccit/app:gate \
        -f "$(_iso_hostpath "$ISO_CTX/docker/gate/Dockerfile.backend")" "$ctx" >/dev/null || return 1
    docker build -q -t sccit/node:gate \
        -f "$(_iso_hostpath "$ISO_CTX/docker/gate/Dockerfile.frontend")" "$ctx" >/dev/null || return 1

    docker network create --internal "$ISO_NET" >/dev/null || return 1
    # docker create + cp rather than a bind mount: the init scripts reach the
    # container the same way on Windows, macOS and Linux.
    docker create --name "$ISO_PG" --network "$ISO_NET" --network-alias gate-postgres \
        -e POSTGRES_USER=gate -e POSTGRES_PASSWORD=gate -e POSTGRES_DB=gate_main \
        --tmpfs /var/lib/postgresql/data pgvector/pgvector:pg17 >/dev/null || return 1
    for f in "$ISO_CTX"/docker/postgres/init/*.sql; do
        docker cp "$(_iso_hostpath "$f")" "$ISO_PG:/docker-entrypoint-initdb.d/$(basename "$f")" >/dev/null || return 1
    done
    docker start "$ISO_PG" >/dev/null || return 1
    docker run -d --name "$ISO_REDIS" --network "$ISO_NET" --network-alias gate-redis \
        redis:7-alpine >/dev/null || return 1

    # Ready means the init scripts have run (test database + pgvector exist),
    # not merely that the server accepts connections.
    i=0
    until docker exec "$ISO_PG" psql -U gate -d school_it_service_management_test -tAc \
            "select 1 from pg_extension where extname = 'vector'" 2>/dev/null | grep -q 1; do
        i=$((i + 1)); [ "$i" -ge 90 ] && return 1; sleep 1
    done

    ISO_APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
    return 0
}

iso_be() {
    docker run --rm --network "$ISO_NET" \
        -e APP_KEY="$ISO_APP_KEY" \
        -e DB_HOST=gate-postgres -e DB_PORT=5432 -e DB_USERNAME=gate -e DB_PASSWORD=gate \
        -e REDIS_HOST=gate-redis -e REDIS_PORT=6379 -e REDIS_PASSWORD= \
        sccit/app:gate "$@"
}

iso_fe() {
    docker run --rm --network none sccit/node:gate sh -lc "$1"
}
