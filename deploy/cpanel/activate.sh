#!/usr/bin/env bash
# Activates a release on the cPanel server. Run from the extracted release directory:
#
#   cd ~/advisory/incoming && tar -xzf advisory-<stamp>-<commit>.tar.gz
#   advisory-<stamp>-<commit>/deploy/activate.sh                      # preflight, backups, shows pending migrations
#   CONFIRM_MIGRATIONS=1 advisory-<stamp>-<commit>/deploy/activate.sh # also applies them and goes live
#
# Layout (home directory):
#   ~/advisory/releases/<name>/      immutable releases
#   ~/advisory/current               symlink to the live release
#   ~/advisory/shared/.env.production  secrets (chmod 600, created by hand from env.production.template)
#   ~/advisory/shared/storage/       logs, uploads, private audio (kept across releases)
#   ~/advisory/backups/              database dumps and web-root archives taken before each activation
#   ~/public_html/                   web root (SPA + .htaccess + api.php; media → shared/storage/public)
#
# Never prints secret values. Stops at the first failure; nothing is switched until every check passes.
set -euo pipefail
umask 077

ADVISORY="${ADVISORY_HOME:-$HOME/advisory}"
WEBROOT="${WEBROOT:-$HOME/public_html}"
SITE="${SITE_URL:-https://www.hansterahiansh.com}"
read -r -a SMOKE_ARGS <<< "${SMOKE_CURL_ARGS:-}"
SHARED="$ADVISORY/shared"
ENV_FILE="$SHARED/.env.production"

SOURCE="$(cd "$(dirname "$0")/.." && pwd)"
NAME="$(basename "$SOURCE")"
RELEASE="$ADVISORY/releases/$NAME"
STAMP="$(date -u +%Y%m%d%H%M%S)"

# shellcheck source=lib.sh
. "$SOURCE/deploy/lib.sh"
PHP="${PHP_BIN:-$(site_php)}"

say() { printf '\n==> %s\n' "$*"; }
die() { printf '\nSTOPPED: %s\n' "$*" >&2; exit 1; }
console() { (cd "$RELEASE/backend" && APP_ENV=production ENV_PATH="$SHARED" "$PHP" bin/console "$@"); }

say "Release $NAME ($(tr '\n' ' ' < "$SOURCE/VERSION"))"
echo "PHP CLI: $PHP ($("$PHP" -r 'echo PHP_VERSION;' 2>/dev/null || echo 'not found'))"

[ -f "$ENV_FILE" ] || die "$ENV_FILE does not exist. Create it from deploy/env.production.template."
[ "$(mode "$ENV_FILE")" = "600" ] || die "$ENV_FILE must be chmod 600."
command -v "$PHP" >/dev/null || die "PHP CLI '$PHP' not found. Set PHP_BIN (e.g. /opt/cpanel/ea-php82/root/usr/bin/php)."
command -v mysqldump >/dev/null || die "mysqldump is not available; take a database backup in cPanel first and set SKIP_DB_BACKUP=1."
[ -d "$WEBROOT" ] || die "Web root $WEBROOT not found. Set WEBROOT to the domain's document root."

mkdir -p "$ADVISORY/releases" "$ADVISORY/backups" "$SHARED/storage"/{logs,cache,public,private}
# Apache serves /media through the symlink, so it may traverse (not list) the path down to storage/public.
chmod 711 "$ADVISORY" "$SHARED" "$SHARED/storage"
chmod 755 "$SHARED/storage/public"
chmod 700 "$ADVISORY/backups" "$ADVISORY/releases" "$SHARED/storage/logs" "$SHARED/storage/cache" "$SHARED/storage/private"

if [ "$SOURCE" != "$RELEASE" ]; then
    [ -e "$RELEASE" ] && die "$RELEASE already exists."
    mv "$SOURCE" "$RELEASE"
fi

say "Preflight"
console deploy:check || die "Preflight failed (see the list above). Nothing was changed."
console payments:check || die "Razorpay check failed. Nothing was changed."

say "Backups"
if [ "${SKIP_DB_BACKUP:-0}" != "1" ]; then
    DEFAULTS="$(mktemp "$SHARED/.mysql-XXXXXX")"
    trap 'rm -f "$DEFAULTS"' EXIT
    (cd "$RELEASE/backend" && APP_ENV=production ENV_PATH="$SHARED" "$PHP" -r '
        $c = require "app/bootstrap.php"; $d = $c->get(App\Core\Config::class)->get("database");
        $u = $d["migration_username"] !== "" ? $d["migration_username"] : $d["username"];
        $p = $d["migration_username"] !== "" ? $d["migration_password"] : $d["password"];
        $q = static fn ($v) => "\"" . addcslashes((string) $v, "\\\"") . "\"";
        $lines = ["[client]", "user=" . $q($u), "password=" . $q($p), "host=" . $q($d["host"]), "port=" . (int) $d["port"]];
        if ($d["socket"] !== "") { $lines[] = "socket=" . $q($d["socket"]); }
        file_put_contents($argv[1], implode("\n", $lines) . "\n");
        echo $d["database"];
    ' "$DEFAULTS") > "$DEFAULTS.db"
    DB_NAME="$(cat "$DEFAULTS.db")"; rm -f "$DEFAULTS.db"
    mysqldump --defaults-extra-file="$DEFAULTS" --single-transaction --quick --no-tablespaces "$DB_NAME" \
        | gzip > "$ADVISORY/backups/db-$STAMP.sql.gz"
    rm -f "$DEFAULTS"
    echo "Database:  $ADVISORY/backups/db-$STAMP.sql.gz"
fi
tar -C "$WEBROOT" -czf "$ADVISORY/backups/public_html-$STAMP.tar.gz" --exclude ./media .
echo "Web root:  $ADVISORY/backups/public_html-$STAMP.tar.gz"
PREVIOUS="$(readlink "$ADVISORY/current" 2>/dev/null || true)"
echo "${PREVIOUS:-none}" > "$ADVISORY/backups/previous-release-$STAMP"
echo "Previous release: ${PREVIOUS:-none}"

say "Migrations"
set +e; console migrate:status; status=$?; set -e
if [ "$status" = "2" ]; then
    [ "${CONFIRM_MIGRATIONS:-0}" = "1" ] || die "Review the pending migrations above, then re-run with CONFIRM_MIGRATIONS=1 (backups are already taken)."
    console migrate
elif [ "$status" != "0" ]; then
    die "Could not read the migration status."
fi
console db:seed

say "Switching to $NAME"
point "$RELEASE" "$ADVISORY/current"
publish "$RELEASE/public_html"

say "Smoke test"
sleep 2
if curl -fsS --max-time 20 ${SMOKE_ARGS[@]+"${SMOKE_ARGS[@]}"} "$SITE/api/v1/health" >/dev/null \
    && curl -fsS --max-time 20 ${SMOKE_ARGS[@]+"${SMOKE_ARGS[@]}"} -o /dev/null "$SITE/"; then
    echo "Health endpoint and home page respond."
else
    echo "WARNING: the site did not respond as expected. Investigate first; to roll back:" >&2
    if [ -n "$PREVIOUS" ]; then
        echo "  $ADVISORY/current/deploy/rollback.sh $(basename "$PREVIOUS")" >&2
    else
        echo "  (first deployment) restore the old web root:" >&2
        echo "  mkdir $ADVISORY/restore-$STAMP && tar -xzf $ADVISORY/backups/public_html-$STAMP.tar.gz -C $ADVISORY/restore-$STAMP && rsync -rlpt --delete --exclude /.well-known $ADVISORY/restore-$STAMP/ $WEBROOT/" >&2
    fi
    exit 1
fi

say "Live: $NAME"
echo "Cron (cPanel → Cron Jobs) must run every minute:"
echo "  cd $ADVISORY/current/backend && APP_ENV=production ENV_PATH=$SHARED $PHP bin/console schedule:run >> $SHARED/storage/logs/cron.log 2>&1"
echo "  cd $ADVISORY/current/backend && APP_ENV=production ENV_PATH=$SHARED $PHP bin/console worker --once >> $SHARED/storage/logs/cron.log 2>&1"
