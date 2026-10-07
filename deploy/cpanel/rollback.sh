#!/usr/bin/env bash
# Points the site back at an earlier release (code + web root). The database is NOT touched:
# migrations are additive, so the previous code runs against the newer schema. Restore a database
# dump from ~/advisory/backups only if a release corrupted data, and only after preserving the
# current state (take a fresh dump first).
#
#   ~/advisory/current/deploy/rollback.sh                 # lists available releases
#   ~/advisory/current/deploy/rollback.sh <release-name>  # switches to it
set -euo pipefail

ADVISORY="${ADVISORY_HOME:-$HOME/advisory}"
WEBROOT="${WEBROOT:-$HOME/public_html}"
SITE="${SITE_URL:-https://www.hansterahiansh.com}"
read -r -a SMOKE_ARGS <<< "${SMOKE_CURL_ARGS:-}"
SHARED="$ADVISORY/shared"

# shellcheck source=lib.sh
. "$(cd "$(dirname "$0")" && pwd)/lib.sh"

TARGET="$(basename "${1:-}")"
if [ -z "$TARGET" ] || [ ! -d "$ADVISORY/releases/$TARGET" ]; then
    echo "Live: $(basename "$(readlink "$ADVISORY/current" 2>/dev/null || echo none)")"
    echo "Available releases:"
    ls -1t "$ADVISORY/releases"
    [ -z "$TARGET" ] && exit 0
    echo "Release '$TARGET' not found." >&2
    exit 1
fi

RELEASE="$ADVISORY/releases/$TARGET"
STAMP="$(date -u +%Y%m%d%H%M%S)"
cp -a "$SHARED/storage/logs" "$ADVISORY/backups/logs-before-rollback-$STAMP" 2>/dev/null || true

point "$RELEASE" "$ADVISORY/current"
publish "$RELEASE/public_html"

echo "Switched to $TARGET (logs preserved in backups/logs-before-rollback-$STAMP)."
if curl -fsS --max-time 20 ${SMOKE_ARGS[@]+"${SMOKE_ARGS[@]}"} "$SITE/api/v1/health" >/dev/null; then
    echo "Health endpoint responds."
else
    echo "WARNING: health endpoint did not respond." >&2
    exit 1
fi
