#!/usr/bin/env sh
# =============================================================
#  SccIT — test a real mail relay (SMTP2GO) without touching production.
#
#      scripts/mail-sandbox.sh check
#      scripts/mail-sandbox.sh send you@example.org
#
#  Runs `php artisan mail:check` in a ONE-OFF container from the production
#  image. The container gets only:
#    - the variables in backend/.env.smtp2go.local (gitignored; MAIL_* only),
#    - APP_ENV=local, so the test redirect is honoured,
#    - MAIL_TEST_REDIRECT_TO=<your inbox> — every message, whatever address it
#      was given, goes there and nowhere else.
#  It has no database, no network peers and no production env file, and no
#  running service is touched. `check` connects, negotiates TLS and
#  authenticates, then disconnects WITHOUT sending. `send` sends the one fixed
#  test message.
#
#  backend/.env.smtp2go.local, for example:
#      MAIL_MAILER=smtp
#      MAIL_HOST=mail.smtp2go.com
#      MAIL_PORT=2525
#      MAIL_SCHEME=smtp
#      MAIL_REQUIRE_TLS=true
#      MAIL_TIMEOUT=10
#      MAIL_USERNAME=sccit-sandbox
#      MAIL_PASSWORD=...
#      MAIL_FROM_ADDRESS=<verified sender>
#      MAIL_FROM_NAME=SccIT
#      MAIL_TEST_REDIRECT_TO=<an inbox you control>
# =============================================================
set -eu

. "$(dirname "$0")/lib/stack.sh"

ACTION="${1:-}"
ENV_FILE="$REPO_ROOT/backend/.env.smtp2go.local"
IMAGE="${SCCIT_SANDBOX_IMAGE:-sccit/app:prod}"

[ -f "$ENV_FILE" ] || die "missing backend/.env.smtp2go.local (see the header of this script)."
git -C "$REPO_ROOT" check-ignore -q "$ENV_FILE" || die "backend/.env.smtp2go.local is NOT gitignored — refusing to use a credential file Git could commit."

REDIRECT="$(sed -n 's/^MAIL_TEST_REDIRECT_TO=//p' "$ENV_FILE" | tail -1 | tr -d "\"'\r")"
[ -n "$REDIRECT" ] || die "set MAIL_TEST_REDIRECT_TO in backend/.env.smtp2go.local — the sandbox never runs without it."

run() {
    docker run --rm --network bridge \
        --env-file "$ENV_FILE" \
        -e APP_ENV=local -e APP_DEBUG=false -e LOG_CHANNEL=stderr \
        -e MAIL_TEST_REDIRECT_TO="$REDIRECT" \
        --entrypoint php "$IMAGE" artisan mail:check "$@"
}

case "$ACTION" in
    check) run ;;
    send)
        TO="${2:?usage: mail-sandbox.sh send <address>}"
        say "Sending ONE test message. The redirect sends it to the MAIL_TEST_REDIRECT_TO inbox, whatever address is given."
        run --send="$TO"
        ;;
    *) die "usage: mail-sandbox.sh check | send <address>" ;;
esac
